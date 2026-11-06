<?php

namespace Tests\Unit;

use App\Services\Detections\Evidence\ReportApiBudget;
use App\Services\Detections\Evidence\StructuredAnalysis;
use App\Services\Detections\OpenAiDetectionReportService;
use App\Services\Detections\OpenAiMediaExplanation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReportApiBudgetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set(['services.openai.analysis_enabled' => true, 'services.openai.claim_extraction_enabled' => true,
            'services.openai.key' => 'fixture', 'fact_check.openai_retry.max_retries' => 1,
            'fact_check.openai_retry.max_delay_seconds' => 0]);
    }

    private function reply(int $tokens = 100): array
    {
        return ['status' => 'completed', 'usage' => ['input_tokens' => $tokens, 'output_tokens' => 0, 'total_tokens' => $tokens],
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '{"ok":true}']]]]];
    }

    public function test_retries_share_the_request_limit_and_keep_reported_usage_when_another_attempt_is_blocked(): void
    {
        config()->set('fact_check.report_budget.max_requests', 1);
        Http::fakeSequence()->push(['usage' => ['input_tokens' => 12, 'total_tokens' => 12]], 503, ['Retry-After' => '0']);
        $model = app(StructuredAnalysis::class);
        $this->assertNull($model->ask('probe', 'Fixture.', [], StructuredAnalysis::object(['ok' => ['type' => 'boolean']])));
        $this->assertSame('report_budget_exhausted', $model->stages['probe']);
        $this->assertSame(12, $model->usage['total_tokens']);
        $this->assertSame(1, app(ReportApiBudget::class)->snapshot()['requests']);
        $this->assertSame(12, app(ReportApiBudget::class)->snapshot()['reported_tokens']);
        $this->assertSame('request_limit', app(ReportApiBudget::class)->snapshot()['blocked_reason']);
        Http::assertSentCount(1);
    }

    public function test_the_token_stop_is_shared_by_review_legacy_reporting_and_optional_media_narration(): void
    {
        config()->set('fact_check.report_budget.stop_after_tokens', 100);
        Http::fake(['api.openai.com/*' => Http::response($this->reply())]);
        $model = app(StructuredAnalysis::class);
        $this->assertSame(['ok' => true], $model->ask('probe', 'Fixture.', [], StructuredAnalysis::object(['ok' => ['type' => 'boolean']])));
        $model->reset(); // Resetting a stage service must not reset the report budget.
        $this->assertNull($model->ask('probe_2', 'Fixture.', [], StructuredAnalysis::object(['ok' => ['type' => 'boolean']])));
        $this->assertSame('report_budget_exhausted', $model->stages['probe_2']);
        $this->assertSame([], app(OpenAiDetectionReportService::class)->enhance([], ['media_type' => 'text']));
        $analysis = ['signals' => [], 'fact_check_result' => [
            'primary_task' => 'media', 'verdict' => 'LIKELY_AI_GENERATED', 'assessment_label' => 'Likely AI-generated',
            'summary' => 'The detector reported a strong AI-generation signal.', 'score' => [], 'limitations' => [],
            'media_assessment' => ['status' => 'completed', 'media_type' => 'image', 'label' => 'fake', 'fake_probability' => .99],
        ]];
        $after = app(OpenAiMediaExplanation::class)->apply($analysis);
        $this->assertSame('report_budget_exhausted', $after['signals']['media_explanation'][0]['failure_reason']);
        $this->assertSame($analysis['fact_check_result']['verdict'], $after['fact_check_result']['verdict']);
        $this->assertSame(1, app(ReportApiBudget::class)->snapshot()['requests']);
        Http::assertSentCount(1);
    }

    public function test_extraction_and_planning_share_one_budget(): void
    {
        config()->set('fact_check.report_budget.max_requests', 1);
        $reply = $this->reply(15);
        $reply['output'][0]['content'][0]['text'] = json_encode([
            'visible_text' => 'The municipal library opened in 2024.', 'claim_text' => 'The municipal library opened in 2024.',
        ]);
        Http::fake(['api.openai.com/*' => Http::response($reply)]);
        $file = UploadedFile::fake()->image('notice.png', 640, 640);
        $this->assertNotNull(app(OpenAiDetectionReportService::class)->extractClaimText(['media_type' => 'image'], $file));
        $model = app(StructuredAnalysis::class);
        $this->assertNull($model->ask('claim_decomposition', 'Fixture.', [], StructuredAnalysis::object(['ok' => ['type' => 'boolean']])));
        $this->assertSame('report_budget_exhausted', $model->stages['claim_decomposition']);
        $this->assertSame(15, app(ReportApiBudget::class)->snapshot()['reported_tokens']);
        Http::assertSentCount(1);
    }

    public function test_an_in_flight_search_overshoot_is_recorded_honestly_and_stops_every_subsequent_request(): void
    {
        config()->set('fact_check.report_budget.stop_after_tokens', 15000);
        Http::fake(['api.openai.com/*' => Http::response($this->reply(47383))]);
        $model = app(StructuredAnalysis::class);
        $schema = StructuredAnalysis::object(['ok' => ['type' => 'boolean']]);
        $this->assertSame(['ok' => true], $model->ask('probe', 'Fixture.', [], $schema));
        $this->assertNull($model->ask('probe_2', 'Fixture.', [], $schema));
        $snapshot = app(ReportApiBudget::class)->snapshot();
        $this->assertSame(47383, $snapshot['reported_tokens']);
        $this->assertTrue($snapshot['in_flight_search_can_exceed_token_threshold']);
        $this->assertSame('reported_token_limit', $snapshot['blocked_reason']);
        Http::assertSentCount(1);
    }
}
