<?php

namespace Tests\Feature;

use App\Models\Detection;
use App\Models\User;
use App\Services\Detections\DetectionPipeline;
use App\Services\Detections\EvidenceVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class FinalAssessmentReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_queue_dispatch_failure_returns_a_report_instead_of_leaving_queued_work(): void
    {
        config()->set('fact_check.queue_enabled', true);
        Bus::shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('queue-secret'));
        $this->actingAs(User::factory()->create())->postJson(route('detections.store'), [
            'caption_text' => 'The bridge reopened on November 2, 2026.', 'analysis_task' => 'claim',
        ])->assertStatus(202)->assertJsonStructure(['result_url']);
        $detection = Detection::sole();
        $this->assertSame('failed', $detection->processing_status);
        $this->assertSame('ANALYSIS_UNAVAILABLE', $detection->fact_check_result['verdict']);
        $this->assertStringNotContainsString('queue-secret', json_encode($detection->toArray()));
    }

    public function test_provider_exception_leaves_a_saved_final_report_without_exposing_the_error(): void
    {
        Http::preventStrayRequests();
        config()->set(['fact_check.enhanced_enabled' => true, 'fact_check.result_reuse.enabled' => false,
            'services.filipino_news_classifier.enabled' => false, 'services.openai.analysis_enabled' => false,
            'services.live_verification.enabled' => false, 'playwright.related_posts_inline' => false]);
        $this->mock(EvidenceVerificationService::class)->shouldReceive('apply')->once()
            ->andThrow(new \RuntimeException('secret-provider-payload'));
        $report = app(DetectionPipeline::class)->run(User::factory()->create(), [
            'caption_text' => 'The bridge reopened on November 2, 2026.', 'analysis_task' => 'claim',
        ], null);
        $this->assertSame('failed', $report->processing_status);
        $this->assertSame('ANALYSIS_UNAVAILABLE', $report->fact_check_result['verdict']);
        $this->assertNotEmpty($report->fact_check_result['summary']);
        $this->assertStringNotContainsString('secret-provider-payload', json_encode($report->toArray()));
        $this->actingAs($report->user)->get(route('detections.result', $report))->assertOk();
        Http::assertNothingSent();
    }

    public function test_scheduler_closes_abandoned_jobs_without_a_browser_poll(): void
    {
        $user = User::factory()->create();
        $stale = Detection::create(['user_id' => $user->id, 'caption_text' => 'Old queued claim',
            'processing_status' => 'queued', 'verdict' => 'review', 'source_kind' => 'text', 'fake_score' => 0]);
        $stale->forceFill(['created_at' => now()->subHour(), 'updated_at' => now()->subHour()])->saveQuietly();
        $fresh = Detection::create(['user_id' => $user->id, 'caption_text' => 'New queued claim',
            'processing_status' => 'queued', 'verdict' => 'review', 'source_kind' => 'text', 'fake_score' => 0]);
        $this->artisan('truthguard:close-stalled-assessments')->assertSuccessful();
        $this->assertSame('failed', $stale->fresh()->processing_status);
        $this->assertSame('ANALYSIS_UNAVAILABLE', $stale->fresh()->fact_check_result['verdict']);
        $this->assertSame('queued', $fresh->fresh()->processing_status);
        $this->artisan('truthguard:close-stalled-assessments')->assertSuccessful();
    }
}
