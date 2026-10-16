<?php

namespace Tests\Unit;

use App\Services\Detections\Evidence\ArticleSearch;
use App\Services\Detections\Evidence\EvidenceJudge;
use App\Services\Detections\Evidence\OpenAiWebSearch;
use App\Services\Detections\Evidence\RetrievalBudget;
use App\Services\Detections\Evidence\SafeArticleFetcher;
use App\Services\Detections\Evidence\StructuredAnalysis;
use App\Services\Detections\OpenAiDetectionReportService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ResearchCostControlsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config()->set(['services.openai.key' => 'offline-fixture', 'services.openai.analysis_enabled' => true,
            'services.openai.model' => 'gpt-5.4', 'services.openai.planning_model' => 'gpt-5.4-mini',
            'services.openai.extraction_model' => 'gpt-5.4-mini', 'fact_check.plan_output_tokens' => 1200,
            'fact_check.model_output_tokens' => 3000, 'fact_check.max_model_input_bytes' => 32768]);
    }

    public function test_planning_uses_mini_while_evidence_keeps_the_main_model_and_separate_caps(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['status' => 'completed', 'usage' => [
            'input_tokens' => 100, 'output_tokens' => 20, 'total_tokens' => 120],
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '{"ok":true}']]]]])]);
        $model = app(StructuredAnalysis::class);
        $schema = StructuredAnalysis::object(['ok' => ['type' => 'boolean']]);
        $this->assertSame(['ok' => true], $model->ask('claim_decomposition', 'Fixture.', [], $schema));
        $this->assertSame(['ok' => true], $model->ask('evidence_relationships_0', 'Fixture.', [], $schema));
        $requests = Http::recorded()->map(fn ($pair) => $pair[0]->data())->all();
        $this->assertSame(['gpt-5.4-mini', 'gpt-5.4'], array_column($requests, 'model'));
        $this->assertSame([1200, 3000], array_column($requests, 'max_output_tokens'));
        foreach ($requests as $request) {
            $this->assertFalse($request['store']);
            $this->assertSame('none', $request['reasoning']['effort']);
            $this->assertArrayNotHasKey('tools', $request);
        }
        $this->assertSame(240, $model->usage['total_tokens']);
        $this->assertSame(['gpt-5.4-mini', 'gpt-5.4'], array_values($model->models));
    }

    public function test_image_extraction_payload_uses_mini_without_another_research_tool(): void
    {
        $method = new \ReflectionMethod(OpenAiDetectionReportService::class, 'claimExtractionPayload');
        $payload = $method->invoke(app(OpenAiDetectionReportService::class), ['media_type' => 'image'], []);
        $this->assertSame('gpt-5.4-mini', $payload['model']);
        $this->assertSame('none', $payload['reasoning']['effort']);
        $this->assertLessThanOrEqual(2000, $payload['max_output_tokens']);
        $this->assertArrayNotHasKey('tools', $payload);
        Http::assertNothingSent();
    }

    public function test_accessible_main_model_keeps_routine_output_caps_without_hidden_reasoning_or_fallback_calls(): void
    {
        config()->set(['services.openai.extraction_model' => 'gpt-5.4', 'services.openai.planning_model' => 'gpt-5.4',
            'services.openai.max_completion_tokens' => 1400, 'fact_check.web_search.model' => 'gpt-5.4',
            'fact_check.web_search.enabled' => true, 'fact_check.web_search.max_tool_calls' => 1,
            'fact_check.web_search.max_output_tokens' => 600, 'fact_check.web_search.context_size' => 'low']);
        $method = new \ReflectionMethod(OpenAiDetectionReportService::class, 'claimExtractionPayload');
        $payload = $method->invoke(app(OpenAiDetectionReportService::class), ['media_type' => 'image'], []);
        $this->assertSame('gpt-5.4', $payload['model']);
        $this->assertSame(1400, $payload['max_output_tokens']);
        $this->assertSame('none', $payload['reasoning']['effort']);
        $this->assertArrayNotHasKey('tools', $payload);
        Http::fake(['api.openai.com/*' => Http::response(['status' => 'completed', 'output' => [
            ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '{"ok":true}']]],
        ]])]);
        $this->assertSame(['ok' => true], app(StructuredAnalysis::class)->ask('claim_decomposition', 'Fixture.', [], StructuredAnalysis::object(['ok' => ['type' => 'boolean']])));
        app(OpenAiWebSearch::class)->discover(['fictional library'], new RetrievalBudget, '2026-10-16T00:00:00Z');
        $requests = Http::recorded()->map(fn ($pair) => $pair[0]->data())->all();
        $this->assertSame(['gpt-5.4', 'gpt-5.4'], array_column($requests, 'model'));
        $this->assertSame([1200, 600], array_column($requests, 'max_output_tokens'));
        $this->assertSame(['none', 'none'], array_column(array_column($requests, 'reasoning'), 'effort'));
        $this->assertSame(1, $requests[1]['max_tool_calls']);
        Http::assertSentCount(2);
    }

    public function test_oversized_input_stops_before_request_and_output_limit_is_not_a_verdict(): void
    {
        $model = app(StructuredAnalysis::class);
        $schema = StructuredAnalysis::object(['ok' => ['type' => 'boolean']]);
        $this->assertNull($model->ask('large', 'Fixture.', ['text' => str_repeat('x', 33000)], $schema));
        Http::assertNothingSent();
        Http::fake(['api.openai.com/*' => Http::response(['status' => 'incomplete',
            'incomplete_details' => ['reason' => 'max_output_tokens'], 'usage' => ['output_tokens' => 3000]])]);
        $this->assertNull($model->ask('evidence_relationships_0', 'Fixture.', [], $schema));
        $this->assertSame('output_limit_reached', $model->stages['evidence_relationships_0']);
        $this->assertSame(3000, $model->usage['output_tokens']);
        Http::assertSentCount(1);
    }

    public function test_discovery_uses_mini_with_one_tool_call_and_a_small_context(): void
    {
        config()->set(['fact_check.web_search.enabled' => true, 'fact_check.web_search.model' => 'gpt-5.4-mini',
            'fact_check.web_search.max_tool_calls' => 1, 'fact_check.web_search.max_output_tokens' => 600,
            'fact_check.web_search.context_size' => 'low']);
        Http::fake(['api.openai.com/*' => Http::response(['status' => 'completed', 'output' => []])]);
        app(OpenAiWebSearch::class)->discover(['fictional library'], new RetrievalBudget, '2026-10-16T00:00:00Z');
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['model'] === 'gpt-5.4-mini'
            && $request['max_tool_calls'] === 1 && $request['max_output_tokens'] === 600
            && $request['tools'][0]['search_context_size'] === 'low'
            && $request['tools'][0]['external_web_access'] === true);
    }

    public function test_unusable_extraction_keeps_returned_usage_without_an_expensive_model_retry(): void
    {
        config()->set(['services.openai.claim_extraction_enabled' => true, 'services.openai.image_context_enabled' => true]);
        Http::fake(['api.openai.com/*' => Http::response(['status' => 'incomplete', 'model' => 'gpt-5.4-mini',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 20, 'total_tokens' => 30], 'output' => []])]);
        $file = UploadedFile::fake()->createWithContent('notice.png', file_get_contents(base_path('tests/Fixtures/evidence/media/notice.png')));
        $service = app(OpenAiDetectionReportService::class);
        $this->assertNull($service->extractClaimText(['media_type' => 'image'], $file));
        $this->assertSame(30, $service->extractionDetails()['usage']['total_tokens']);
        $this->assertSame('gpt-5.4-mini', $service->extractionDetails()['response_model']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['model'] === 'gpt-5.4-mini');
    }

    public function test_unchanged_review_is_reused_but_new_contrary_source_still_sends_all_sources(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['status' => 'completed', 'output' => [
            ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '{"relationships":[],"context_findings":[]}']]],
        ]])]);
        $judge = new EvidenceJudge(app(StructuredAnalysis::class), app(ArticleSearch::class));
        $claims = [['id' => 'c1', 'text' => 'The fictional library opened in 2024.', 'kind' => 'factual']];
        $source = ['id' => 's1', 'url' => 'https://example.gov.ph/records/opening', 'name' => 'Example',
            'title' => 'Opening record', 'retrieval_status' => 'retrieved', 'source_type' => 'official_government',
            'text' => 'The fictional library opened in 2024. This is a software test fixture.'];
        $judge->assess($claims, [$source], '2026-10-16T00:00:00Z', 0);
        $judge->assess($claims, [$source], '2026-10-16T00:00:00Z', 1);
        Http::assertSentCount(1);
        $this->assertSame(1, $judge->duplicateReviewsSkipped);
        $contrary = array_replace($source, ['id' => 's2', 'url' => 'https://example.gov.ph/records/correction',
            'text' => 'Correction: the fictional library opened in 2025, replacing the previous 2024 report.']);
        $judge->assess($claims, [$source, $contrary], '2026-10-16T00:00:00Z', 1);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => array_column(json_decode($request['input'][1]['content'], true)['sources'], 'id') === ['s1', 's2']);
        $judge->reset();
        $judge->assess($claims, [$source], '2026-10-16T00:00:00Z', 0);
        Http::assertSentCount(3);
    }

    public function test_article_body_excludes_surrounding_unrelated_recommendations_and_preserves_qualifiers(): void
    {
        $result = app(SafeArticleFetcher::class)->html('<html><head><title>Fictional notice</title></head><body><main>'
            .'<div>Unrelated recommended story</div><article><h1>Library schedule</h1><p>The fictional library opens in 2027 only if funding is approved.</p></article>'
            .'<section>Subscribe and share unrelated news</section></main></body></html>', 'https://example.gov.ph/records/notice');
        $this->assertStringContainsString('only if funding is approved', $result['text']);
        $this->assertStringNotContainsString('Unrelated recommended', $result['text']);
        $this->assertStringNotContainsString('Subscribe and share', $result['text']);
        Http::assertNothingSent();
    }

    public function test_search_token_usage_includes_failed_responses_without_counting_other_providers(): void
    {
        $budget = new RetrievalBudget;
        $budget->attempts = [
            ['provider' => 'openai_web_search', 'status' => 'ok', 'web_search' => ['usage' => ['input_tokens' => 90, 'output_tokens' => 10, 'total_tokens' => 100]]],
            ['provider' => 'openai_web_search', 'status' => 'invalid_response', 'web_search' => ['usage' => ['input_tokens' => 8, 'output_tokens' => 2, 'total_tokens' => 10]]],
            ['provider' => 'google', 'usage' => ['total_tokens' => 999]],
        ];
        $this->assertSame(['input_tokens' => 98, 'output_tokens' => 12, 'total_tokens' => 110], $budget->openAiUsage());
    }
}
