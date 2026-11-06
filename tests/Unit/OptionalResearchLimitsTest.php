<?php

namespace Tests\Unit;

use App\Services\Detections\Evidence\ClaimPlanner;
use App\Services\Detections\Evidence\ReportApiBudget;
use App\Services\Detections\Evidence\StructuredAnalysis;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OptionalResearchLimitsTest extends TestCase
{
    public function test_zero_disables_fixed_model_call_request_and_token_stops_without_losing_usage_tracking(): void
    {
        config()->set(['services.openai.key' => 'fixture', 'services.openai.analysis_enabled' => true,
            'fact_check.max_model_calls' => 0, 'fact_check.report_budget.max_requests' => 0,
            'fact_check.report_budget.stop_after_tokens' => 0]);
        Http::fake(['api.openai.com/*' => Http::response(['status' => 'completed', 'usage' => ['total_tokens' => 10000],
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '{"ok":true}']]]]])]);
        $model = app(StructuredAnalysis::class);
        $schema = StructuredAnalysis::object(['ok' => ['type' => 'boolean']]);
        foreach (range(1, 8) as $round) {
            $this->assertSame(['ok' => true], $model->ask('probe_'.$round, 'Fixture.', [], $schema));
        }
        $this->assertNull($model->remainingCalls());
        $this->assertTrue($model->canAsk());
        $this->assertSame(80000, app(ReportApiBudget::class)->snapshot()['reported_tokens']);
        $this->assertNull(app(ReportApiBudget::class)->snapshot()['blocked_reason']);
        Http::assertSentCount(8);
    }

    public function test_an_explicit_positive_model_call_cap_still_stops_before_another_request(): void
    {
        config()->set(['services.openai.key' => 'fixture', 'services.openai.analysis_enabled' => true, 'fact_check.max_model_calls' => 1]);
        Http::fake(['api.openai.com/*' => Http::response(['status' => 'completed',
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '{"ok":true}']]]]])]);
        $model = app(StructuredAnalysis::class);
        $schema = StructuredAnalysis::object(['ok' => ['type' => 'boolean']]);
        $this->assertSame(['ok' => true], $model->ask('probe', 'Fixture.', [], $schema));
        $this->assertFalse($model->canAsk());
        $this->assertNull($model->ask('probe_2', 'Fixture.', [], $schema));
        $this->assertSame('budget_exhausted', $model->stages['probe_2']);
        Http::assertSentCount(1);
    }

    public function test_zero_claim_limit_preserves_every_valid_atomic_claim(): void
    {
        config()->set('fact_check.max_claims', 0);
        $rows = array_map(fn ($year) => ['quote' => 'The fictional school opened in '.$year.'.',
            'kind' => 'factual', 'requires_context' => false, 'context_reason' => '', 'entities' => [], 'locations' => [],
            'dates' => [], 'quantities' => [], 'qualifiers' => [], 'searches' => ['fictional school opening '.$year], 'references' => []], range(2020, 2025));
        $this->mock(StructuredAnalysis::class)->shouldReceive('ask')->once()->withArgs(function ($stage, $instructions) {
            $this->assertStringContainsString('Extract every checkable factual assertion', $instructions);
            $this->assertStringNotContainsString('Return at most 0 claims', $instructions);

            return $stage === 'claim_decomposition';
        })->andReturn(['claims' => $rows, 'original_claim_date' => null, 'date_quote' => '']);
        $plan = app(ClaimPlanner::class)->plan(implode(' ', array_column($rows, 'quote')));
        $this->assertCount(6, $plan['claims']);
        $this->assertSame(array_column($rows, 'quote'), array_column($plan['claims'], 'text'));
        $this->assertFalse(collect($plan['claims'])->contains(fn ($claim) => $claim['requires_context']));
    }
}
