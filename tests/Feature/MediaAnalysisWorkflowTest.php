<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeDetectionEvidence;
use App\Models\Detection;
use App\Models\User;
use App\Services\Detections\AiOriginClassifier;
use App\Services\Detections\DetectionPipeline;
use App\Services\Detections\Evidence\ClaimPlanner;
use App\Services\Detections\Evidence\SubmissionExtractor;
use App\Services\Detections\FilipinoNewsClassifier;
use App\Services\Detections\GoogleFactCheckFeedService;
use App\Services\Detections\SubmissionTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MediaAnalysisWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Http::preventStrayRequests();
        config()->set(['fact_check.enhanced_enabled' => true, 'fact_check.queue_enabled' => false,
            'services.openai.analysis_enabled' => false, 'services.filipino_news_classifier.enabled' => false,
            'services.live_verification.enabled' => false, 'playwright.related_posts_inline' => false,
            'services.ai_origin.enabled' => true]);
        $this->mock(GoogleFactCheckFeedService::class)->shouldReceive('latest')->andReturn([]);
    }

    private function upload(): UploadedFile
    {
        return new UploadedFile(base_path('tests/Fixtures/evidence/media/notice.png'), 'original.png', null, null, true);
    }

    private function detector(string $label = 'fake'): void
    {
        $this->mock(AiOriginClassifier::class)->shouldReceive('classify')->once()->withArgs(function ($file) {
            $this->assertStringStartsWith(Storage::disk('public')->path('detections'), $file->getPathname());
            $this->assertSame(hash_file('sha256', $this->upload()->getPathname()), hash_file('sha256', $file->getPathname()));

            return true;
        })->andReturn(['status' => 'completed', 'media_type' => 'image', 'task' => 'AI-generation screening',
            'model_version' => 'fixture-model', 'label' => $label, 'fake_probability' => $label === 'fake' ? 0.8 : 0.1,
            'threshold' => 0.5, 'screening_score' => $label === 'fake' ? 80 : 10, 'elapsed_ms' => 9]);
    }

    public static function instructions(): array
    {
        return ['no caption' => [null], 'automatic media task' => [SubmissionTask::MEDIA_INSTRUCTION.'.']];
    }

    #[DataProvider('instructions')]
    public function test_media_task_runs_without_text_and_is_not_replaced_by_needs_context(?string $caption): void
    {
        $this->detector();
        $this->mock(FilipinoNewsClassifier::class)->shouldNotReceive('inspect');
        $this->mock(ClaimPlanner::class)->shouldNotReceive('plan');
        $this->actingAs(User::factory()->create())->post(route('detections.store'), [
            'media_file' => $this->upload(), 'caption_text' => $caption,
        ])->assertSessionHasNoErrors();
        $detection = Detection::sole();
        $result = $detection->fact_check_result;
        $this->assertSame('LIKELY_AI_GENERATED', $result['verdict']);
        $this->assertSame('fixture-model', $result['media_assessment']['model_version']);
        $this->assertSame($caption === null ? 'unavailable' : 'not_requested', $result['claim_assessment']['status']);
        $this->assertNull($result['confidence']);
        $this->get(route('detections.result', $detection))->assertOk()->assertSee('Likely AI-Generated')->assertDontSee('Missing Context')
            ->assertDontSee('unsuitable short input')->assertSee('Retry media analysis');
        // GET/JSON reads the saved output; the detector mock is called exactly once.
        $this->assertSame(0.8, $result['media_assessment']['fake_probability']);
        $this->getJson(route('detections.result', $detection))->assertJsonPath('verdict', 'LIKELY_AI_GENERATED')
            ->assertJsonPath('media_assessment.label', 'fake')
            ->assertJsonMissingPath('media_assessment.fake_probability');
    }

    public function test_media_routing_preserves_openai_extraction_failure_without_changing_detector_finding(): void
    {
        $this->detector('real');
        $extraction = ['analysis_text' => '', 'status' => 'unavailable', 'visible_text' => '',
            'failure_reason' => 'quota_exhausted', 'http_status' => 429, 'configured_model' => 'fixture-model'];
        $this->mock(SubmissionExtractor::class)->shouldReceive('extract')->once()->andReturn($extraction);
        $this->mock(FilipinoNewsClassifier::class)->shouldNotReceive('inspect');
        $this->mock(ClaimPlanner::class)->shouldNotReceive('plan');
        $this->actingAs(User::factory()->create())->post(route('detections.store'), ['media_file' => $this->upload()])
            ->assertSessionHasNoErrors();
        $detection = Detection::sole();
        $this->assertSame('LIKELY_NOT_AI_GENERATED', $detection->fact_check_result['verdict']);
        $this->assertSame($extraction, $detection->signals['submission_extraction'][0]);
        $this->get(route('detections.result', $detection))->assertOk()->assertSee('Status: API credits exhausted')
            ->assertDontSee('Status: Used for this report');
        Http::assertNothingSent();
    }

    public function test_no_readable_text_skips_article_classifier_after_successful_ocr(): void
    {
        $this->detector('real');
        $this->mock(SubmissionExtractor::class)->shouldReceive('extract')->once()->andReturn([
            'analysis_text' => '', 'status' => 'completed', 'visible_text' => '',
        ]);
        $this->mock(FilipinoNewsClassifier::class)->shouldNotReceive('inspect');
        $this->actingAs(User::factory()->create())->post(route('detections.store'), ['media_file' => $this->upload()]);
        $result = Detection::sole()->fact_check_result;
        $this->assertSame('LIKELY_NOT_AI_GENERATED', $result['verdict']);
        $this->assertSame('not_applicable', $result['claim_assessment']['status']);
        $this->assertStringContainsString('does not prove', $result['summary']);
        $this->assertSame('review', Detection::sole()->verdict);
    }

    public function test_headline_assessment_stays_separate_from_media_output_when_article_service_is_unavailable(): void
    {
        $this->detector();
        $this->mock(FilipinoNewsClassifier::class)->shouldReceive('inspect')->once()->with('A headline without enough context.', 'visible image text and supplied caption')->andReturn([
            'status' => 'unavailable', 'task' => 'article_style_classification', 'prediction' => null,
            'input_scope' => 'visible image text and supplied caption', 'contribution' => 'Supporting diagnostic only.', 'limitation' => 'Not factual confidence.',
        ]);
        config()->set(['services.openai.analysis_enabled' => true, 'services.openai.key' => 'fixture']);
        Http::fake(['api.openai.com/*' => Http::response(['output_text' => json_encode([
            'visible_text' => 'A headline without enough context.', 'claim_text' => '', 'synthetic_media_note' => '', 'confidence' => 90,
        ])])]);
        $this->mock(ClaimPlanner::class)->shouldReceive('plan')->once()->andReturn([
            'claims' => [['id' => 'c1', 'text' => 'A headline without enough context.', 'kind' => 'unclear', 'requires_context' => true,
                'context_reason' => 'The event and date are missing.']], 'original_claim_date' => null,
        ])->getMock()->shouldReceive('queries')->andReturn([]);
        $this->actingAs(User::factory()->create())->post(route('detections.store'), [
            'media_file' => $this->upload(), 'caption_text' => 'Is this AI-generated? Is this statement true?',
        ])->assertSessionHasNoErrors();
        $result = Detection::sole()->fact_check_result;
        $this->assertSame('LIKELY_AI_GENERATED', $result['verdict']);
        $this->assertSame('Missing Context', $result['claim_assessment']['result']['assessment_label']);
        $this->assertSame('unavailable', $result['claim_assessment']['result']['trained_model']['status']);
        $this->assertStringNotContainsString('Check whether', $result['claim_assessment']['result']['claim']);
        $this->get(route('detections.result', Detection::sole()))->assertOk()->assertSeeInOrder(['Likely AI-Generated', 'Factual claim assessment', 'Missing Context']);
    }

    public function test_text_exception_cannot_erase_successful_media_analysis(): void
    {
        $this->detector();
        $this->mock(SubmissionExtractor::class)->shouldReceive('extract')->andThrow(new \RuntimeException('fixture text outage'));
        $this->actingAs(User::factory()->create())->post(route('detections.store'), ['media_file' => $this->upload()])->assertSessionHasNoErrors();
        $this->assertSame('LIKELY_AI_GENERATED', Detection::sole()->fact_check_result['verdict']);
        $this->assertSame('unavailable', Detection::sole()->fact_check_result['claim_assessment']['status']);
    }

    public function test_factual_first_combined_request_retains_media_when_text_extraction_throws(): void
    {
        $this->detector();
        $this->mock(SubmissionExtractor::class)->shouldReceive('extract')->andThrow(new \RuntimeException('internal outage'));
        $this->actingAs(User::factory()->create())->post(route('detections.store'), [
            'media_file' => $this->upload(), 'caption_text' => 'Is this claim true? Is this AI-generated?',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $result = Detection::sole()->fact_check_result;
        $this->assertSame('claim', $result['primary_task']);
        $this->assertSame('ANALYSIS_UNAVAILABLE', $result['verdict']);
        $this->assertSame('LIKELY_AI_GENERATED', $result['secondary_assessments'][0]['verdict']);
        $this->assertSame('partial', $result['processing_status']);
        $this->assertSame(['media'], $result['completed_tasks']);
        $this->getJson(route('detections.result', Detection::sole()))
            ->assertJsonPath('secondary_assessments.0.verdict', 'LIKELY_AI_GENERATED')
            ->assertJsonPath('secondary_assessments.0.processing_status', 'completed');
        $this->get(route('detections.result', Detection::sole()))->assertOk()
            ->assertSee('Content verification report')->assertSee('Likely AI-Generated')->assertDontSee('internal outage');
    }

    public function test_missing_backend_original_produces_processing_failure_even_with_a_saved_preview_path(): void
    {
        Queue::fake();
        config()->set('fact_check.queue_enabled', true);
        $this->actingAs(User::factory()->create())->post(route('detections.store'), [
            'media_file' => $this->upload(), 'caption_text' => 'is this ai or not',
        ])->assertSessionHasNoErrors();
        $detection = Detection::sole();
        $this->assertNotNull($detection->media_path);
        Storage::disk('public')->delete($detection->media_path);
        $job = new AnalyzeDetectionEvidence($detection->id, ['caption_text' => $detection->caption_text]);
        try {
            $job->handle(app(DetectionPipeline::class));
            $this->fail('Missing original should fail processing.');
        } catch (\RuntimeException $error) {
            $job->failed($error);
        }
        $this->assertSame('failed', $detection->fresh()->processing_status);
        $this->get(route('detections.result', $detection))
            ->assertRedirect(route('detections.create', ['pending' => $detection->id]));
        $this->getJson(route('detections.result', $detection))->assertJsonPath('processing_status', 'failed');
        Http::assertNothingSent();
    }

    public function test_missing_model_is_an_explicit_failure_with_retry_and_ownership_checks(): void
    {
        config()->set('services.ai_origin.model_path', 'missing-origin-model');
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('detections.store'), ['media_file' => $this->upload()])->assertSessionHasNoErrors();
        $detection = Detection::sole();
        $this->assertSame('ANALYSIS_UNAVAILABLE', $detection->fact_check_result['verdict']);
        $this->assertSame('model_missing', $detection->fact_check_result['media_assessment']['failure_code']);
        $this->get(route('detections.result', $detection))->assertOk()->assertSee('Analysis Unavailable')->assertSee('Retry media analysis');
        $this->actingAs(User::factory()->create())->post(route('detections.retry-media', $detection))->assertNotFound();
        $this->actingAs($user);
        $this->detector();
        $this->post(route('detections.retry-media', $detection))->assertRedirect(route('detections.result', $detection));
        $this->assertSame(1, Detection::count());
        $this->assertSame('LIKELY_AI_GENERATED', $detection->fresh()->fact_check_result['verdict']);
    }

    public function test_queued_upload_uses_saved_bytes_and_preserves_explicit_media_task(): void
    {
        Queue::fake();
        config()->set('fact_check.queue_enabled', true);
        $this->actingAs(User::factory()->create())->post(route('detections.store'), [
            'media_file' => $this->upload(), 'analysis_task' => 'media', 'caption_text' => SubmissionTask::MEDIA_INSTRUCTION,
        ])->assertSessionHasNoErrors();
        $this->detector();
        Queue::assertPushed(AnalyzeDetectionEvidence::class, function ($job) {
            $job->handle(app(DetectionPipeline::class));

            return true;
        });
        $this->assertSame('LIKELY_AI_GENERATED', Detection::sole()->fact_check_result['verdict']);
    }

    public function test_unavailable_runtime_reports_failure_instead_of_falling_back_to_a_fact_verdict(): void
    {
        config()->set('services.ai_origin.python_binary', 'truthguard-missing-python-executable');
        $this->actingAs(User::factory()->create())->post(route('detections.store'), ['media_file' => $this->upload()])->assertSessionHasNoErrors();
        $result = Detection::sole()->fact_check_result;
        $this->assertSame('ANALYSIS_UNAVAILABLE', $result['verdict']);
        $this->assertSame('process_or_response_failed', $result['media_assessment']['failure_code']);
        $this->assertNull($result['media_assessment']['fake_probability']);
    }

    public function test_identical_upload_is_reinferred_and_does_not_reuse_a_stale_media_result(): void
    {
        $base = ['status' => 'completed', 'media_type' => 'image', 'task' => 'AI-generation screening', 'threshold' => 0.5];
        $this->mock(AiOriginClassifier::class)->shouldReceive('classify')->twice()->andReturn(
            $base + ['label' => 'fake', 'fake_probability' => 0.8],
            $base + ['label' => 'real', 'fake_probability' => 0.1],
        );
        $this->actingAs(User::factory()->create());
        for ($i = 0; $i < 2; $i++) {
            $this->post(route('detections.store'), ['media_file' => $this->upload()])->assertSessionHasNoErrors();
        }
        $reports = Detection::orderBy('id')->get();
        $this->assertCount(2, $reports);
        $this->assertSame('LIKELY_AI_GENERATED', $reports[0]->fact_check_result['verdict']);
        $this->assertSame('LIKELY_NOT_AI_GENERATED', $reports[1]->fact_check_result['verdict']);
        $this->assertNull($reports[1]->reused_from_detection_id);
    }

    public function test_media_instruction_is_not_sent_to_legacy_article_classification(): void
    {
        config()->set('fact_check.enhanced_enabled', false);
        $this->detector();
        $this->mock(FilipinoNewsClassifier::class)->shouldNotReceive('classify')->shouldNotReceive('inspect');
        $this->actingAs(User::factory()->create())->post(route('detections.store'), [
            'media_file' => $this->upload(), 'caption_text' => SubmissionTask::MEDIA_INSTRUCTION,
        ])->assertSessionHasNoErrors();
        $this->assertSame('LIKELY_AI_GENERATED', Detection::sole()->fact_check_result['verdict']);
    }
}
