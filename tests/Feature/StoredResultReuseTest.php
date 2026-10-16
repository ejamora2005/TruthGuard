<?php

namespace Tests\Feature;

use App\Models\Detection;
use App\Models\User;
use App\Services\Detections\AiOriginClassifier;
use App\Services\Detections\DeepfakeCnnClassifier;
use App\Services\Detections\DetectionPipeline;
use App\Services\Detections\EvidenceVerificationService;
use App\Services\Detections\GoogleFactCheckFeedService;
use App\Services\Detections\ReportAnswer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StoredResultReuseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        Http::preventStrayRequests();
        Storage::fake('public');
        config()->set(['fact_check.enhanced_enabled' => true, 'fact_check.result_reuse.enabled' => true,
            'fact_check.queue_enabled' => false, 'services.ai_origin.enabled' => true,
            'services.openai.analysis_enabled' => false, 'services.filipino_news_classifier.enabled' => false,
            'services.live_verification.enabled' => false, 'playwright.related_posts_inline' => false]);
    }

    private function upload(string $bytes = 'identical image bytes'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('same.png', file_get_contents(base_path('tests/Fixtures/evidence/media/notice.png')).$bytes);
    }

    private function mockAnalysis(int $calls, string $task = 'media', ?int $detectorCalls = null): void
    {
        $this->mock(DeepfakeCnnClassifier::class)->shouldNotReceive('classify');
        $this->mock(AiOriginClassifier::class)->shouldReceive('classify')->times($detectorCalls ?? $calls)->andReturn(['status' => 'unavailable']);
        $this->mock(EvidenceVerificationService::class)->shouldReceive('apply')->times($calls)->andReturnUsing(function ($analysis) use ($task) {
            $result = ReportAnswer::complete(['schema_version' => 4, 'primary_task' => $task,
                'verdict' => $task === 'media' ? 'INCONCLUSIVE' : 'UNVERIFIED',
                'summary' => 'Saved independent assessment.', 'assessed_at' => now()->toIso8601String(),
                'evidence_cutoff' => now()->toIso8601String()]);

            return array_replace($analysis, ['fact_check_result' => $result, 'analysis_summary' => $result['summary'],
                'explanation_summary' => $result['summary'], 'signals' => ['openai_usage' => ['input_tokens' => 100, 'output_tokens' => 20, 'total_tokens' => 120]]]);
        });
    }

    private function submitFixture(User $user, array $extra = [], ?Detection $pending = null, string $bytes = 'identical image bytes'): Detection
    {
        return app(DetectionPipeline::class)->run($user, $extra + ['analysis_task' => 'media', 'caption_text' => 'Check image origin.'], $this->upload($bytes), $pending);
    }

    public function test_duplicate_upload_reuses_before_any_analysis_without_double_counting_tokens(): void
    {
        $this->mockAnalysis(1);
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $first = $this->submitFixture($owner);
        $snapshot = $first->fresh()->getRawOriginal();
        $this->travel(1)->minutes();
        $second = $this->submitFixture($other);
        $third = $this->submitFixture($owner);
        $this->assertSame($first->id, $second->reused_from_detection_id);
        $this->assertSame($first->id, $third->reused_from_detection_id);
        $this->assertSame($other->id, $second->user_id);
        $this->assertSame($first->fact_check_result, $second->fact_check_result);
        $this->assertTrue($second->analyzed_at->eq($first->analyzed_at));
        $this->assertNotSame($first->media_path, $second->media_path);
        $this->assertSame(0, $second->signals['openai_usage']['total_tokens']);
        $this->assertSame(120, $second->signals['result_reuse']['original_openai_usage']['total_tokens']);
        $this->assertSame($snapshot, $first->fresh()->getRawOriginal());
        Storage::disk('public')->assertExists($second->media_path);
        $this->mock(GoogleFactCheckFeedService::class)->shouldReceive('latest')->once()->with(6)->andReturn(['items' => []]);
        $this->actingAs($other)->get(route('detections.result', $second))->assertOk()->assertSee('Saved result reused.')
            ->assertSee('No new API tokens')->assertSee('Reused saved assistance');
        $this->get(route('detections.result', $first))->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_old_reports_are_excluded_until_a_fresh_analysis_has_created_a_new_cache_entry(): void
    {
        $this->mockAnalysis(2);
        $user = User::factory()->create();
        $old = $this->submitFixture($user);
        $signals = $old->signals;
        unset($signals['result_cache']);
        $old->update(['signals' => $signals]);
        $snapshot = $old->fresh()->getRawOriginal();

        $fresh = $this->submitFixture($user);
        $this->assertNull($fresh->reused_from_detection_id);
        $this->assertSame(config('fact_check.result_reuse.generation'), $fresh->signals['result_cache']['generation']);
        $this->assertSame($snapshot, $old->fresh()->getRawOriginal());
        $repeat = $this->submitFixture($user);
        $this->assertSame($fresh->id, $repeat->reused_from_detection_id);
        $this->assertSame(0, $repeat->signals['openai_usage']['total_tokens']);
        Http::assertNothingSent();
    }

    public function test_pending_job_is_completed_in_place_from_the_cached_result(): void
    {
        $this->mockAnalysis(1);
        $user = User::factory()->create();
        $first = $this->submitFixture($user);
        $file = $this->upload();
        $pending = Detection::create(['user_id' => $user->id, 'source_kind' => 'upload', 'media_type' => 'image',
            'media_path' => $file->store('detections', 'public'), 'processing_status' => 'processing', 'fake_score' => 0, 'verdict' => 'review']);
        $result = $this->submitFixture($user, [], $pending);
        $this->assertSame($pending->id, $result->id);
        $this->assertSame($first->id, $result->reused_from_detection_id);
        $this->assertSame('completed', $result->processing_status);
        $this->assertSame(2, Detection::count());
        Http::assertNothingSent();
    }

    public function test_different_image_question_notes_task_and_model_settings_require_new_analysis(): void
    {
        $this->mockAnalysis(6, 'media', 5);
        $user = User::factory()->create();
        $first = $this->submitFixture($user);
        foreach ([['caption_text' => 'Is this event real?'], ['notes' => 'Check a different context.'], ['analysis_task' => 'claim']] as $extra) {
            $this->assertNull($this->submitFixture($user, $extra)->reused_from_detection_id);
        }
        $this->assertNull($this->submitFixture($user, [], null, 'different image bytes')->reused_from_detection_id);
        config()->set('services.deepfake_cnn.threshold', .7);
        $this->assertNull($this->submitFixture($user)->reused_from_detection_id);
        $this->assertNotNull($first->request_fingerprint);
        Http::assertNothingSent();
    }

    public function test_retry_and_expired_result_bypass_reuse_without_sliding_the_original_assessment_date(): void
    {
        config()->set('fact_check.result_reuse.media_seconds', 120);
        $this->mockAnalysis(3);
        $user = User::factory()->create();
        $first = $this->submitFixture($user);
        $this->travel(1)->minutes();
        $copy = $this->submitFixture($user);
        $this->assertSame($first->id, $copy->reused_from_detection_id);
        $this->travel(2)->minutes();
        $fresh = $this->submitFixture($user);
        $this->assertNull($fresh->reused_from_detection_id);
        $retried = $this->submitFixture($user, [], $fresh);
        $this->assertSame($fresh->id, $retried->id);
        $this->assertNull($retried->reused_from_detection_id);
        Http::assertNothingSent();
    }

    public function test_failed_partial_and_quota_limited_results_are_not_reused(): void
    {
        $this->mockAnalysis(5);
        $user = User::factory()->create();
        $first = $this->submitFixture($user);
        foreach (['failed', 'partial', 'quota', 'stage_failure'] as $case) {
            $result = $first->fact_check_result;
            if ($case === 'partial') {
                $result['processing_status'] = 'partial';
            }
            if ($case === 'stage_failure') {
                $result['diagnostics']['model_stages'] = ['claim_plan' => 'quota_exhausted'];
            }
            $signals = $first->signals;
            if ($case === 'quota') {
                $signals['submission_extraction'] = [['failure_reason' => 'quota_exhausted']];
            }
            $first->update(['processing_status' => $case === 'failed' ? 'failed' : 'completed', 'fact_check_result' => $result, 'signals' => $signals]);
            $fresh = $this->submitFixture($user);
            $this->assertNull($fresh->reused_from_detection_id);
            $fresh->update(['archived_at' => now()]);
        }
        Http::assertNothingSent();
    }

    public function test_completed_factual_assessment_is_reused_with_its_original_evidence_cutoff_until_expiry(): void
    {
        $this->mockAnalysis(2, 'claim', 0);
        $user = User::factory()->create();
        $input = ['analysis_task' => 'claim', 'caption_text' => 'The bridge opened in 2010.'];
        $first = $this->submitFixture($user, $input);
        $this->travel(1)->minutes();
        $second = $this->submitFixture($user, $input);
        $this->assertSame($first->id, $second->reused_from_detection_id);
        $this->assertSame($first->fact_check_result['evidence_cutoff'], $second->fact_check_result['evidence_cutoff']);
        $this->travel(6)->minutes();
        $this->assertNull($this->submitFixture($user, $input)->reused_from_detection_id);
        Http::assertNothingSent();
    }

    public function test_cache_can_be_disabled_and_case_sensitive_source_paths_are_distinct(): void
    {
        $this->mockAnalysis(4);
        $user = User::factory()->create();
        $first = $this->submitFixture($user, ['source_url' => 'https://example.com/Article']);
        $second = $this->submitFixture($user, ['source_url' => 'https://example.com/article']);
        $this->assertNotSame($first->request_fingerprint, $second->request_fingerprint);
        $this->assertNull($second->reused_from_detection_id);
        config()->set('fact_check.result_reuse.enabled', false);
        $this->submitFixture($user);
        $this->assertNull($this->submitFixture($user)->reused_from_detection_id);
        Http::assertNothingSent();
    }

    public function test_busy_identical_submission_does_not_start_another_paid_analysis(): void
    {
        config()->set('fact_check.result_reuse.wait_seconds', 0);
        $this->mockAnalysis(1);
        $user = User::factory()->create();
        $first = $this->submitFixture($user);
        $lock = Cache::lock('truthguard-result:'.$first->request_fingerprint, 900);
        $this->assertTrue($lock->get());
        try {
            $this->submitFixture($user, ['force_refresh' => true]);
            $this->fail('The held lock must prevent duplicate analysis.');
        } catch (ValidationException $error) {
            $this->assertStringContainsString('no duplicate analysis was started', $error->errors()['media_file'][0]);
            $this->assertSame(1, Detection::count());
        } finally {
            $lock->release();
        }
        Http::assertNothingSent();
    }

    public function test_current_status_and_weather_consent_always_require_fresh_evidence(): void
    {
        $this->mockAnalysis(4, 'media', 2);
        $user = User::factory()->create();
        foreach ([['caption_text' => 'Are classes suspended today?'], ['weather_consent' => true, 'weather_lat' => 11.2, 'weather_lon' => 124.9]] as $extra) {
            $this->submitFixture($user, $extra);
            $this->assertNull($this->submitFixture($user, $extra)->reused_from_detection_id);
        }
        Http::assertNothingSent();
    }
}
