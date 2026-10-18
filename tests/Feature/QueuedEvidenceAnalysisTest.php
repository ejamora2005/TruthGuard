<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeDetectionEvidence;
use App\Models\Detection;
use App\Models\User;
use App\Services\Detections\DetectionPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class QueuedEvidenceAnalysisTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config()->set([
            'fact_check.queue_enabled' => true,
            // RefreshDatabase wraps the test in an outer transaction.
            'queue.connections.evidence.after_commit' => false,
            'playwright.related_posts_inline' => false,
        ]);
    }

    public function test_uploaded_image_is_serialized_and_reopened_by_the_evidence_worker(): void
    {
        // Queue mode must also protect media requests if the enhanced claim flag is off.
        config()->set('fact_check.enhanced_enabled', false);
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('detections.store'), [
            'caption_text' => 'Is this image AI-generated?', 'analysis_task' => 'media',
            'media_file' => $this->upload(),
        ])->assertSessionHasNoErrors()->assertRedirect();
        $detection = Detection::firstOrFail();
        $this->assertSame('queued', $detection->processing_status);
        $this->assertSame('evidence', DB::table('jobs')->sole()->queue);
        $this->getJson(route('detections.result', $detection))->assertJsonPath('processing_status', 'queued');
        Http::assertNothingSent();

        $this->expectWorkerRun($detection);
        Queue::connection('evidence')->pop('evidence')->fire();
        $this->assertSame('completed', $detection->fresh()->processing_status);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(1, Detection::count());
        Http::assertNothingSent();
    }

    public function test_retry_is_queued_once_and_preserves_the_old_result_until_the_worker_finishes(): void
    {
        $detection = $this->savedReport();
        $oldResult = $detection->fact_check_result;
        $oldPath = $detection->media_path;
        $this->actingAs($detection->user)->post(route('detections.retry-media', $detection), ['analysis_task' => 'media'])
            ->assertRedirect()->assertSessionHas('status', fn ($text) => str_contains($text, 'retry queued'));
        $this->assertSame('queued', $detection->fresh()->processing_status);
        $this->assertSame($oldResult, $detection->fresh()->fact_check_result);
        $this->getJson(route('detections.result', $detection))->assertJsonPath('processing_status', 'queued');
        $this->post(route('detections.retry-media', $detection))->assertRedirect()
            ->assertSessionHas('status', 'Analysis is already running for this report.');
        $this->assertSame(1, DB::table('jobs')->count());
        Http::assertNothingSent();

        $this->expectWorkerRun($detection, true);
        Queue::connection('evidence')->pop('evidence')->fire();
        $this->assertSame('completed', $detection->fresh()->processing_status);
        $this->assertSame('New saved assessment.', $detection->fresh()->fact_check_result['summary']);
        $this->assertSame($oldPath, $detection->fresh()->media_path);
        $this->assertSame(1, Detection::count());
        $this->assertArrayNotHasKey('analysis_retry', $detection->fresh()->signals);
    }

    public function test_failed_retry_restores_previous_result_and_records_only_a_safe_failure_status(): void
    {
        Queue::fake();
        $detection = $this->savedReport();
        $oldResult = $detection->fact_check_result;
        $this->actingAs($detection->user)->post(route('detections.retry-media', $detection))->assertRedirect();
        $job = Queue::pushed(AnalyzeDetectionEvidence::class)->sole();
        $this->mock(DetectionPipeline::class)->shouldReceive('run')->once()->andThrow(new \RuntimeException('private-provider-secret'));
        try {
            $job->handle(app(DetectionPipeline::class));
            $this->fail('The fixture must fail during processing.');
        } catch (\RuntimeException $error) {
            $job->failed($error);
        }
        $this->assertSame('completed', $detection->fresh()->processing_status);
        $this->assertSame($oldResult, $detection->fresh()->fact_check_result);
        $this->assertSame('failed', data_get($detection->fresh()->signals, 'analysis_retry.status'));
        $this->assertStringNotContainsString('private-provider-secret', json_encode($detection->fresh()->toArray()));
        $this->get(route('detections.result', $detection))->assertOk()
            ->assertSee('The previous saved result is shown below.')->assertDontSee('private-provider-secret');
        Http::assertNothingSent();
    }

    public function test_stale_retry_job_cannot_run_or_fail_a_newer_retry(): void
    {
        $detection = $this->savedReport();
        $detection->forceFill(['processing_status' => 'queued', 'signals' => ['analysis_retry' => ['id' => 'new-attempt', 'status' => 'queued']]])->saveQuietly();
        $pipeline = $this->mock(DetectionPipeline::class)->shouldNotReceive('run')->getMock();
        $oldJob = new AnalyzeDetectionEvidence($detection->id, [], 'old-attempt');
        $oldJob->handle($pipeline);
        $oldJob->failed(new \RuntimeException('old failure'));
        $this->assertSame('queued', $detection->fresh()->processing_status);
        $this->assertSame('new-attempt', data_get($detection->fresh()->signals, 'analysis_retry.id'));
        Http::assertNothingSent();
    }

    public function test_duplicate_delivery_does_not_rerun_a_processing_or_completed_retry(): void
    {
        $detection = $this->savedReport();
        $detection->forceFill(['processing_status' => 'processing', 'signals' => ['analysis_retry' => ['id' => 'attempt', 'status' => 'queued']]])->saveQuietly();
        $pipeline = $this->mock(DetectionPipeline::class)->shouldNotReceive('run')->getMock();
        $job = new AnalyzeDetectionEvidence($detection->id, [], 'attempt');
        $job->handle($pipeline);
        $detection->forceFill(['processing_status' => 'completed', 'signals' => []])->saveQuietly();
        $job->handle($pipeline);
        $job->failed(new \RuntimeException('late failure'));
        $this->assertSame('completed', $detection->fresh()->processing_status);
    }

    public function test_queue_dispatch_failure_does_not_erase_or_leave_the_saved_report_pending(): void
    {
        $detection = $this->savedReport();
        $oldResult = $detection->fact_check_result;
        $oldSignals = $detection->signals;
        Queue::shouldReceive('connection')->with('evidence')->once()->andThrow(new \RuntimeException('queue unavailable'));
        $this->actingAs($detection->user)->post(route('detections.retry-media', $detection))->assertRedirect()
            ->assertSessionHas('status', 'The retry could not be queued. Your saved result is unchanged. Please try again later.');
        $this->assertSame('completed', $detection->fresh()->processing_status);
        $this->assertSame($oldResult, $detection->fresh()->fact_check_result);
        $this->assertSame($oldSignals, $detection->fresh()->signals);
    }

    public function test_other_users_cannot_queue_a_retry_for_someone_elses_report(): void
    {
        $detection = $this->savedReport();
        $this->actingAs(User::factory()->create())->post(route('detections.retry-media', $detection))->assertNotFound();
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_jobs_queued_before_the_update_keep_their_original_behavior(): void
    {
        $legacy = new AnalyzeDetectionEvidence(123, ['analysis_task' => 'media']);
        unset($legacy->retryId, $legacy->previousStatus);
        $restored = unserialize(serialize($legacy));
        $this->assertNull($restored->retryId);
        $this->assertSame('completed', $restored->previousStatus);
        $this->assertSame('123', $restored->uniqueId());
        $this->assertSame('evidence', $restored->connection);
        $this->assertSame('evidence', $restored->queue);
    }

    private function expectWorkerRun(Detection $detection, bool $retry = false): void
    {
        $this->mock(DetectionPipeline::class)->shouldReceive('run')->once()
            ->andReturnUsing(function ($user, $context, $file, $pending) use ($detection, $retry) {
                $this->assertSame($detection->id, $pending->id);
                $this->assertSame($detection->user_id, $user->id);
                $this->assertSame('processing', $pending->processing_status);
                $this->assertSame('media', $context['analysis_task']);
                $this->assertArrayNotHasKey('media_file', $context);
                $this->assertSame(hash_file('sha256', $this->upload()->getRealPath()), hash_file('sha256', $file->getRealPath()));
                $this->assertSame($retry, $pending->fact_check_result !== null);
                $pending->forceFill(['processing_status' => 'completed', 'signals' => [],
                    'fact_check_result' => ['schema_version' => 4, 'verdict' => 'UNVERIFIED', 'summary' => 'New saved assessment.'],
                ])->saveQuietly();

                return $pending;
            });
    }

    private function savedReport(): Detection
    {
        return Detection::create(['user_id' => User::factory()->create()->id,
            'source_kind' => 'upload', 'media_type' => 'image', 'media_path' => $this->upload()->store('detections', 'public'),
            'caption_text' => 'Is this image AI-generated?', 'processing_status' => 'completed', 'verdict' => 'review',
            'fake_score' => 0, 'signals' => [], 'analysis_summary' => 'Original saved assessment.',
            'fact_check_result' => ['schema_version' => 4, 'primary_task' => 'media', 'verdict' => 'UNVERIFIED',
                'summary' => 'Original saved assessment.', 'request' => ['selected_mode' => 'media']],
        ]);
    }

    private function upload(): UploadedFile
    {
        return new UploadedFile(base_path('tests/Fixtures/evidence/media/notice.png'), 'submitted.png', null, null, true);
    }
}
