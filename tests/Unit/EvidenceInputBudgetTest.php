<?php

namespace Tests\Unit;

use App\Services\Detections\Evidence\ArticleSearch;
use App\Services\Detections\Evidence\EvidenceJudge;
use App\Services\Detections\Evidence\RetrievalBudget;
use App\Services\Detections\Evidence\SafeArticleFetcher;
use App\Services\Detections\Evidence\StructuredAnalysis;
use App\Services\Detections\Evidence\SubmissionExtractor;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EvidenceInputBudgetTest extends TestCase
{
    public function test_evidence_output_budget_supports_multiple_claims_without_changing_generic_report_budget(): void
    {
        config()->set(['services.openai.analysis_enabled' => true, 'services.openai.key' => 'fixture',
            'services.openai.max_completion_tokens' => 1400, 'fact_check.model_output_tokens' => 5000]);
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/*' => Http::response(['status' => 'incomplete', 'incomplete_details' => ['reason' => 'max_output_tokens']])]);
        $model = app(StructuredAnalysis::class);
        $this->assertNull($model->ask('multi_claim_probe', 'Fixture only.', [], StructuredAnalysis::object(['ok' => ['type' => 'boolean']])));
        Http::assertSent(fn ($request) => $request['max_output_tokens'] === 5000);
        $this->assertSame('output_limit_reached', $model->stages['multi_claim_probe']);
        $this->assertSame(1400, config('services.openai.max_completion_tokens'));
    }

    public function test_repeated_submission_context_and_escaped_source_text_fit_the_real_wire_budget(): void
    {
        $context = str_repeat('Whole submitted paragraph. ', 650);
        $claims = array_map(fn ($i) => ['id' => 'c'.$i, 'text' => 'Statement '.$i, 'kind' => 'factual',
            'requires_context' => false, 'submission_context' => $context], range(1, 6));
        $sources = array_map(fn ($i) => ['id' => 's'.$i, 'url' => 'https://example.gov.ph/record/'.$i,
            'title' => 'Official record', 'source_type' => 'official_government', 'retrieval_status' => 'retrieved',
            'text' => str_repeat("Quoted \"passage\" / path\nFilipino: impormasyon. ", 900)], range(1, 6));
        $model = $this->mock(StructuredAnalysis::class);
        $model->shouldReceive('ask')->once()->withArgs(function ($stage, $instructions, $input) use ($context) {
            $this->assertStringContainsString('Match flags measure correspondence', $instructions);
            $this->assertStringContainsString('explicitly correcting the date, quantity, location', $instructions);
            $this->assertLessThanOrEqual(config('fact_check.max_model_input_bytes'),
                strlen(json_encode($input, JSON_UNESCAPED_UNICODE)) + strlen($instructions));
            $this->assertSame([$context], $input['submission_contexts']);
            foreach ($input['sources'] as $source) {
                $this->assertGreaterThan(100, strlen($source['text']));
                $this->assertTrue($source['review_text_truncated']);
            }

            return true;
        })->andReturn(['relationships' => [], 'context_findings' => []]);
        $judge = new EvidenceJudge($model, app(ArticleSearch::class));
        $this->assertSame([], $judge->assess($claims, $sources, '2026-10-14T00:00:00Z', 0));
        $this->assertCount(6, $judge->reviewScopes);
    }

    public function test_supplied_claim_uses_link_as_evidence_without_checking_every_sentence_on_the_page(): void
    {
        Http::preventStrayRequests();
        config()->set('services.live_verification.enabled', true);
        $fetcher = $this->mock(SafeArticleFetcher::class);
        $fetcher->shouldReceive('fetch')->once()->andReturn(['status' => 'retrieved', 'text' => 'Another unrelated assertion on the same page.']);
        $result = app(SubmissionExtractor::class)->extract(['caption_text' => 'The agency name is Example Agency.',
            'source_url' => 'https://example.gov.ph/about'], null, new RetrievalBudget);
        $this->assertSame('The agency name is Example Agency.', $result['analysis_text']);
        $this->assertStringContainsString('unrelated assertion', $result['article_text']);
        $this->assertSame('retrieved', $result['source_record']['status']);
    }
}
