<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeDetectionEvidence;
use App\Models\Detection;
use App\Models\User;
use App\Services\Detections\AiOriginClassifier;
use App\Services\Detections\DetectionPipeline;
use App\Services\Detections\EvidenceVerificationService;
use App\Services\Detections\GoogleFactCheckFeedService;
use App\Services\Detections\ReportAnswer;
use App\Services\Detections\SubmissionTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ImageUploadReuseTest extends TestCase
{
    use RefreshDatabase;

    private const INPUT = ['analysis_task' => 'media', 'caption_text' => SubmissionTask::MEDIA_INSTRUCTION];

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        Storage::fake('public');
        Queue::fake();
        Http::preventStrayRequests();
        config()->set(['fact_check.enhanced_enabled' => true, 'fact_check.result_reuse.enabled' => true,
            'fact_check.queue_enabled' => true, 'services.openai.analysis_enabled' => false,
            'services.ai_origin.enabled' => true, 'services.filipino_news_classifier.enabled' => false,
            'services.live_verification.enabled' => false, 'playwright.related_posts_inline' => false]);
        $this->mock(GoogleFactCheckFeedService::class)->shouldReceive('latest')->andReturn(['items' => []]);
    }

    private function upload(string $suffix = ''): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('image.png',
            file_get_contents(base_path('tests/Fixtures/evidence/media/notice.png')).$suffix);
    }

    private function mockAnalysis(int $calls = 1, ?int $detectorCalls = null): void
    {
        $this->mock(AiOriginClassifier::class)->shouldReceive('classify')->times($detectorCalls ?? $calls)->andReturn(['status' => 'unavailable']);
        $this->mock(EvidenceVerificationService::class)->shouldReceive('apply')->times($calls)
            ->andReturnUsing(function ($analysis, $context) {
                $request = SubmissionTask::resolve($context);
                $result = ReportAnswer::complete(['schema_version' => 4, 'primary_task' => $request['resolved_task'],
                    'request' => $request, 'verdict' => $request['resolved_task'] === 'media' ? 'INCONCLUSIVE' : 'UNVERIFIED',
                    'summary' => 'The saved assessment of this image.',
                    'assessed_at' => now()->toIso8601String(), 'evidence_cutoff' => now()->toIso8601String()]);

                return array_replace($analysis, ['fact_check_result' => $result, 'analysis_summary' => $result['summary'],
                    'explanation_summary' => $result['summary'],
                    'signals' => ['openai_usage' => ['input_tokens' => 100, 'output_tokens' => 20, 'total_tokens' => 120]]]);
            });
    }

    private function saved(User $user, array $input = []): Detection
    {
        return app(DetectionPipeline::class)->run($user, $input + self::INPUT, $this->upload());
    }

    private function submit(User $user, array $input = [], string $suffix = '')
    {
        return $this->actingAs($user)->postJson(route('detections.store'),
            $input + ['media_file' => $this->upload($suffix)] + self::INPUT);
    }

    public function test_same_account_opens_the_first_completed_report_without_a_row_file_job_or_api_call(): void
    {
        $this->mockAnalysis(2);
        $owner = User::factory()->create();
        $first = $this->saved($owner);
        $this->travel(1)->minutes();
        $this->saved($owner, ['force_refresh' => true]);
        $snapshot = $first->fresh()->getRawOriginal();
        $files = Storage::disk('public')->allFiles('detections');
        // Changes to model settings do not require paying to open a historical report.
        config()->set('services.deepfake_cnn.threshold', .7);
        $this->submit($owner)->assertOk()->assertJsonPath('reused', true)
            ->assertJsonPath('result_url', route('detections.result', $first));
        $this->assertSame(2, Detection::count());
        $this->assertSame($files, Storage::disk('public')->allFiles('detections'));
        $this->assertSame($snapshot, $first->fresh()->getRawOriginal());
        $this->get(route('detections.result', $first))->assertOk()->assertSee('No new analysis or API tokens were used.');
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_legacy_report_without_identity_and_old_guide_can_be_opened_after_cache_ttl(): void
    {
        $this->mockAnalysis();
        $owner = User::factory()->create();
        $first = $this->saved($owner, ['caption_text' => 'Check this image or video for AI generation and explain visible details.']);
        $signals = $first->signals;
        unset($signals['upload_reuse']);
        $first->update(['signals' => $signals, 'request_fingerprint' => 'old-deployment']);
        $this->travel(2)->days();
        config()->set('fact_check.result_reuse.media_seconds', 60);
        $this->submit($owner)->assertOk()->assertJsonPath('result_url', route('detections.result', $first));
        $this->assertSame(1, Detection::count());
        Queue::assertNothingPushed();
    }

    public function test_duplicate_queued_upload_joins_the_same_job_on_the_same_account(): void
    {
        $owner = User::factory()->create();
        $response = $this->submit($owner)->assertAccepted();
        $this->submit($owner)->assertAccepted()->assertJsonPath('reused', true)
            ->assertJsonPath('result_url', $response->json('result_url'));
        $this->assertSame(1, Detection::count());
        $this->assertCount(1, Storage::disk('public')->allFiles('detections'));
        Queue::assertPushed(AnalyzeDetectionEvidence::class, 1);
        Http::assertNothingSent();
    }

    public static function separateRequests(): array
    {
        return ['different question' => [['caption_text' => 'Was this photographed in Cebu in 2020?']],
            'different amount after guide' => [['caption_text' => SubmissionTask::MEDIA_INSTRUCTION.'. Also check if each costs 11 million pesos.']],
            'different notes' => [['notes' => 'Check the stated date too.']],
            'fact checking instead' => [['analysis_task' => 'claim', 'caption_text' => SubmissionTask::CLAIM_INSTRUCTION]],
            'explicit fresh analysis' => [['force_refresh' => true]],
            'different bytes' => [[], 'changed bytes']];
    }

    #[DataProvider('separateRequests')]
    public function test_changed_question_task_image_and_explicit_refresh_do_not_open_a_wrong_report(array $input, string $suffix = ''): void
    {
        $this->mockAnalysis();
        $owner = User::factory()->create();
        $this->saved($owner);
        $this->submit($owner, $input, $suffix)->assertAccepted();
        $this->assertSame(2, Detection::count());
        Queue::assertPushed(AnalyzeDetectionEvidence::class, 1);
        Http::assertNothingSent();
    }

    public function test_different_account_gets_an_owned_copy_with_original_date_and_zero_new_tokens(): void
    {
        $this->mockAnalysis();
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $first = $this->saved($owner);
        $signals = $first->signals;
        $signals['private_runtime_detail'] = ['label' => 'PRIVATE OWNER INTERNAL NOTE'];
        $result = $first->fact_check_result;
        $result['diagnostics']['provider_request_id'] = 'PRIVATE REQUEST ID';
        $first->update(['signals' => $signals, 'fact_check_result' => $result]);
        $snapshot = $first->fresh()->getRawOriginal();
        $this->travel(1)->minutes();
        $response = $this->submit($other)->assertOk()->assertJsonPath('reused', true);
        $copy = Detection::where('user_id', $other->id)->sole();
        $this->assertSame(route('detections.result', $copy), $response->json('result_url'));
        $this->assertSame($first->id, $copy->reused_from_detection_id);
        $this->assertNotSame($first->media_path, $copy->media_path);
        $this->assertTrue($first->analyzed_at->eq($copy->analyzed_at));
        $this->assertSame($first->fact_check_result['summary'], $copy->fact_check_result['summary']);
        $this->assertSame($first->fact_check_result['evidence_cutoff'], $copy->fact_check_result['evidence_cutoff']);
        $this->assertSame(0, $copy->signals['openai_usage']['total_tokens']);
        $this->assertSame(0, $copy->fact_check_result['diagnostics']['api_usage']['total_tokens']);
        $this->assertArrayNotHasKey('private_runtime_detail', $copy->signals);
        $this->assertArrayNotHasKey('provider_request_id', $copy->fact_check_result['diagnostics']);
        $this->assertSame($snapshot, $first->fresh()->getRawOriginal());
        $this->get(route('detections.result', $first))->assertNotFound();
        $this->get(route('detections.result', $copy))->assertOk()->assertSee('Saved result reused.')
            ->assertSee('Reused saved assistance')
            ->assertDontSee('PRIVATE OWNER INTERNAL NOTE')->assertDontSee('PRIVATE REQUEST ID');
        $this->submit($other)->assertOk()->assertJsonPath('result_url', route('detections.result', $copy));
        $this->assertSame(2, Detection::count());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_private_notes_from_another_account_are_not_reused_for_an_unannotated_upload(): void
    {
        $this->mockAnalysis();
        $this->saved(User::factory()->create(), ['notes' => 'My private information.']);
        $this->submit(User::factory()->create())->assertAccepted();
        Queue::assertPushed(AnalyzeDetectionEvidence::class, 1);
        Http::assertNothingSent();
    }

    public function test_factual_image_report_reuses_its_exact_question_and_keeps_a_fresh_retry_available(): void
    {
        $this->mockAnalysis(1, 0);
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $input = ['analysis_task' => 'claim', 'caption_text' => 'Did these vehicles cost 10 million pesos each?'];
        $first = $this->saved($owner, $input);
        $this->submit($owner, $input)->assertOk()->assertJsonPath('result_url', route('detections.result', $first));
        $this->submit($other, $input)->assertOk()->assertJsonPath('reused', true);
        $copy = Detection::where('user_id', $other->id)->sole();
        $this->assertSame('claim', $copy->fact_check_result['primary_task']);
        $this->assertSame($first->id, $copy->reused_from_detection_id);
        $this->get(route('detections.result', $copy))->assertOk()->assertSee('Retry assessment');
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_failed_archived_or_wrongly_routed_reports_are_not_returned(): void
    {
        $this->mockAnalysis();
        $owner = User::factory()->create();
        $first = $this->saved($owner);
        $first->update(['processing_status' => 'failed']);
        $this->submit($owner)->assertAccepted();
        Detection::where('processing_status', 'queued')->delete();
        $first->update(['processing_status' => 'completed', 'archived_at' => now()]);
        $this->submit($owner)->assertAccepted();
        Detection::where('processing_status', 'queued')->delete();
        $result = $first->fact_check_result;
        $result['primary_task'] = 'claim';
        $first->update(['archived_at' => null, 'fact_check_result' => $result]);
        $this->submit($owner)->assertAccepted();
        Queue::assertPushed(AnalyzeDetectionEvidence::class, 3);
    }

    public function test_expired_shared_result_starts_a_new_check_for_a_different_account(): void
    {
        $this->mockAnalysis();
        $this->saved(User::factory()->create());
        $this->travel(2)->minutes();
        config()->set('fact_check.result_reuse.media_seconds', 60);
        $this->submit(User::factory()->create())->assertAccepted();
        Queue::assertPushed(AnalyzeDetectionEvidence::class, 1);
    }

    public function test_stale_queued_upload_does_not_keep_the_account_waiting_forever(): void
    {
        $owner = User::factory()->create();
        $this->submit($owner)->assertAccepted();
        $first = Detection::sole();
        $this->travel(20)->minutes();
        $this->submit($owner)->assertAccepted();
        $this->assertSame('failed', $first->fresh()->processing_status);
        $this->assertSame(2, Detection::count());
        Queue::assertPushed(AnalyzeDetectionEvidence::class, 2);
    }

    public function test_standard_form_submission_redirects_to_the_existing_report(): void
    {
        $this->mockAnalysis();
        $owner = User::factory()->create();
        $first = $this->saved($owner);
        $this->actingAs($owner)->post(route('detections.store'), self::INPUT + ['media_file' => $this->upload()])
            ->assertRedirect(route('detections.result', $first));
        $this->assertSame(1, Detection::count());
        Queue::assertNothingPushed();
    }
}
