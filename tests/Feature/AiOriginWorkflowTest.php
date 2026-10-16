<?php

namespace Tests\Feature;

use App\Models\Detection;
use App\Models\User;
use App\Services\Detections\AiOriginClassifier;
use App\Services\Detections\DeepfakeCnnClassifier;
use App\Services\Detections\GoogleFactCheckFeedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Mocked transport/composition tests; model accuracy is evaluated separately. */
class AiOriginWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Http::preventStrayRequests();
        $this->mock(GoogleFactCheckFeedService::class)->shouldReceive('latest')->andReturn(['items' => []]);
        config()->set(['services.ai_origin.enabled' => true, 'fact_check.enhanced_enabled' => true,
            'fact_check.queue_enabled' => false, 'services.openai.analysis_enabled' => false,
            'services.live_verification.enabled' => false, 'playwright.related_posts_inline' => false]);
    }

    private function submit(array $origin): Detection
    {
        $path = base_path('tests/Fixtures/evidence/media/notice.png');
        $hash = hash_file('sha256', $path);
        $this->mock(DeepfakeCnnClassifier::class)->shouldNotReceive('classify');
        $this->mock(AiOriginClassifier::class)->shouldReceive('classify')->once()->withArgs(function ($file) use ($hash) {
            $this->assertSame($hash, hash_file('sha256', $file->getRealPath()));
            $this->assertSame(realpath(Storage::disk('public')->path('detections')), dirname($file->getRealPath()));

            return true;
        })->andReturn($origin);
        $this->actingAs(User::factory()->create())->post(route('detections.store'), [
            'media_file' => new UploadedFile($path, 'submission.png', null, null, true),
            'caption_text' => 'is this ai or not',
        ])->assertSessionHasNoErrors()->assertRedirect();

        return Detection::sole();
    }

    private function finding(string $label): array
    {
        return ['status' => 'completed', 'media_type' => 'image', 'label' => $label,
            'model_name' => 'OpenFake SigLIP2 + DINOv2 AI-origin detector',
            'fake_probability' => $label === 'fake' ? .93 : .07, 'threshold' => .5,
            'label_mapping' => ['real', 'fake'], 'model_version' => 'mock-model',
            'output_contract' => ['activation' => 'sigmoid', 'positive_class' => 'fake', 'binary_exclusive' => true],
            'method_reference' => 'https://huggingface.co/Bombek1/ai-image-detector-siglip-dinov2'];
    }

    public function test_image_uses_only_the_primary_origin_model_without_additional_screening(): void
    {
        $d = $this->submit($this->finding('fake'));
        $r = $d->fact_check_result;
        $this->assertSame('LIKELY_AI_GENERATED', $r['verdict']);
        $this->assertEqualsWithDelta(.93, $r['score']['value'], .00001);
        $this->assertFalse($r['media_assessment']['detector_disagreement'] ?? false);
        $this->assertEmpty($r['media_assessment']['supporting_assessments'] ?? []);
        $this->assertStringContainsString('OpenFake SigLIP2 + DINOv2', $d->signals['deepfake_cnn'][0]['label']);
        $this->get(route('detections.result', $d))->assertOk()->assertSee('Likely AI-Generated')
            ->assertSee('OpenFake SigLIP2 + DINOv2 AI-origin detector')
            ->assertDontSee('Additional image screening')->assertDontSee('The classifiers disagree.')
            ->assertSee('Method references')
            ->assertDontSee('"fake_probability"');
        Http::assertNothingSent();
    }

    public function test_negative_origin_prediction_remains_likely_not_ai_generated(): void
    {
        $d = $this->submit($this->finding('real'));
        $this->assertSame('LIKELY_NOT_AI_GENERATED', $d->fact_check_result['verdict']);
        $this->assertFalse($d->fact_check_result['media_assessment']['detector_disagreement'] ?? false);
    }

    public function test_failed_origin_model_reports_unavailable_without_a_mobilenet_fallback(): void
    {
        $d = $this->submit(['status' => 'unavailable', 'label' => null]);
        $this->assertSame('ANALYSIS_UNAVAILABLE', $d->fact_check_result['verdict']);
        $this->assertSame('failed', $d->fact_check_result['processing_status']);
        $this->assertNull($d->fact_check_result['score']['value']);
        $this->getJson(route('detections.result', $d))->assertJsonPath('processing_status', 'failed');
        $this->get(route('detections.result', $d))->assertOk()->assertDontSee('Additional image screening');
    }

    public function test_weak_class_prediction_is_retained_but_does_not_become_a_confident_origin_verdict(): void
    {
        $origin = $this->finding('fake');
        $origin['fake_probability'] = .55;
        $origin['origin_policy'] = ['version' => 'origin-selective-v1', 'inconclusive' => true];
        $d = $this->submit($origin);
        $this->assertSame('fake', $d->fact_check_result['media_assessment']['label']);
        $this->assertSame('INCONCLUSIVE', $d->fact_check_result['verdict']);
        $this->assertNull($d->fact_check_result['score']['value']);
        $summary = $d->fact_check_result['summary'];
        $this->assertGreaterThanOrEqual(100, str_word_count($summary));
        $this->assertStringContainsString('neither AI generation nor human origin has been established', $summary);
        $this->assertStringContainsString('does not verify the people, events, captions, or factual claims', $summary);
        $this->assertStringContainsString('a traceable first publication', $summary);
        $this->assertSame($summary, $d->explanation_summary);
        $this->getJson(route('detections.result', $d))->assertJsonPath('media_assessment.origin_inconclusive', true)
            ->assertJsonPath('summary', $summary);
    }

    public function test_disabled_origin_model_does_not_silently_reenable_mobilenet(): void
    {
        config()->set('services.ai_origin.enabled', false);
        $this->mock(DeepfakeCnnClassifier::class)->shouldNotReceive('classify');
        $this->mock(AiOriginClassifier::class)->shouldNotReceive('classify');
        $this->actingAs(User::factory()->create())->post(route('detections.store'), [
            'media_file' => new UploadedFile(base_path('tests/Fixtures/evidence/media/notice.png'), 'submission.png', null, null, true),
            'caption_text' => 'is this ai or not',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('ANALYSIS_UNAVAILABLE', Detection::sole()->fact_check_result['verdict']);
        Http::assertNothingSent();
    }

    public function test_video_keeps_its_original_detector_without_running_the_image_origin_model(): void
    {
        $this->mock(AiOriginClassifier::class)->shouldNotReceive('classify');
        $this->mock(DeepfakeCnnClassifier::class)->shouldReceive('classify')->once()
            ->withArgs(fn ($file, $mediaType) => $file instanceof UploadedFile && $mediaType === 'video')
            ->andReturn(['status' => 'completed', 'media_type' => 'video', 'label' => 'fake', 'fake_probability' => .91,
                'screening_score' => 91, 'frames_used' => 4, 'model_version' => 'mock-video-model']);
        $this->actingAs(User::factory()->create())->post(route('detections.store'), [
            'media_file' => UploadedFile::fake()->create('submission.mp4', 10, 'video/mp4'),
            'caption_text' => 'is this ai or not',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('mock-video-model', Detection::sole()->fact_check_result['media_assessment']['model_version']);
        Http::assertNothingSent();
    }

    public function test_factual_image_question_does_not_run_either_origin_or_mobilenet_screening(): void
    {
        $this->mock(AiOriginClassifier::class)->shouldNotReceive('classify');
        $this->mock(DeepfakeCnnClassifier::class)->shouldNotReceive('classify');
        $this->actingAs(User::factory()->create())->post(route('detections.store'), [
            'media_file' => new UploadedFile(base_path('tests/Fixtures/evidence/media/notice.png'), 'submission.png', null, null, true),
            'caption_text' => 'Is the claim in this post true?',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $result = Detection::sole()->fact_check_result;
        $this->assertSame('claim', $result['primary_task']);
        $this->assertSame('not_requested', $result['media_assessment']['status']);
        Http::assertNothingSent();
    }
}
