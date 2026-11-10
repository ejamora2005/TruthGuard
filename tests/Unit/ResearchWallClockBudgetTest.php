<?php

namespace Tests\Unit;

use App\Services\Detections\Evidence\ReportApiBudget;
use App\Services\Detections\Evidence\ReportBudgetExceeded;
use App\Services\Detections\Evidence\RetrievalBudget;
use App\Services\Detections\Evidence\StructuredAnalysis;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ResearchWallClockBudgetTest extends TestCase
{
    public function test_unrestricted_research_and_model_time_exclusion_do_not_bypass_the_total_deadline(): void
    {
        Http::preventStrayRequests();
        config()->set(['fact_check.unrestricted_research' => true, 'fact_check.report_budget.max_seconds' => 120]);
        $budget = app(ReportApiBudget::class);
        $budget->reset();
        (new \ReflectionProperty($budget, 'startedAt'))->setValue($budget, microtime(true) - 121);
        $retrieval = new RetrievalBudget;
        $retrieval->excludeAnalysisTime(500);

        $this->assertSame(0.0, $retrieval->remaining());
        $this->assertFalse($retrieval->take('pages'));
        $this->assertFalse(app(StructuredAnalysis::class)->canAsk());
        $this->assertTrue($budget->snapshot()['time_budget_exhausted']);
        try {
            $budget->beforeRequest();
            $this->fail('An expired report must not start another paid request.');
        } catch (ReportBudgetExceeded) {
            $this->assertSame('elapsed_time_limit', $budget->snapshot()['blocked_reason']);
            $this->assertSame(0, $budget->snapshot()['requests']);
        }
        Http::assertNothingSent();
    }

    public function test_an_explicit_zero_can_disable_the_wall_deadline_and_reset_starts_a_new_report(): void
    {
        config()->set('fact_check.report_budget.max_seconds', 0);
        $budget = app(ReportApiBudget::class);
        (new \ReflectionProperty($budget, 'startedAt'))->setValue($budget, microtime(true) - 1000);
        $this->assertSame(INF, $budget->remainingSeconds());
        config()->set('fact_check.report_budget.max_seconds', 120);
        $budget->reset();
        $this->assertGreaterThan(119, $budget->remainingSeconds());
        $this->assertFalse($budget->snapshot()['time_budget_exhausted']);
    }
}
