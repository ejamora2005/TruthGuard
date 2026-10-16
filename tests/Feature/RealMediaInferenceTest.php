<?php

namespace Tests\Feature;

use App\Models\Detection;
use App\Models\User;
use App\Services\Detections\AssessmentScore;
use App\Services\Detections\DeepfakeCnnClassifier;
use App\Services\Detections\GoogleFactCheckFeedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Opt-in integration tests: actual pinned local AI-origin weights; external text APIs disabled. */
class RealMediaInferenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('TRUTHGUARD_RUN_REAL_MEDIA') !== '1') {
            $this->markTestSkipped('Set TRUTHGUARD_RUN_REAL_MEDIA=1 to run the real CPU AI-origin detector.');
        }
        Storage::fake('public');
        Http::preventStrayRequests();
        config()->set(['fact_check.enhanced_enabled' => true, 'fact_check.queue_enabled' => false,
            'services.openai.analysis_enabled' => false, 'services.filipino_news_classifier.enabled' => false,
            'services.live_verification.enabled' => false, 'services.ai_origin.enabled' => true,
            'playwright.related_posts_inline' => false]);
        $this->mock(GoogleFactCheckFeedService::class)->shouldReceive('latest')->andReturn([]);
        $this->mock(DeepfakeCnnClassifier::class)->shouldNotReceive('classify');
    }

    public static function samples(): array
    {
        return [
            'documented AI generation' => ['media-analysis/stable-diffusion-astronaut.png', 'fake'],
            'documented camera origin' => ['media-analysis/nasa-as11-40-5903.jpg', 'real'],
            'image containing headline' => ['evidence/media/notice.png', null],
        ];
    }

    #[DataProvider('samples')]
    public function test_original_upload_runs_real_model_and_persists_exact_output(string $fixture, ?string $provenance): void
    {
        $path = base_path('tests/Fixtures/'.$fixture);
        $hash = hash_file('sha256', $path);
        $this->actingAs(User::factory()->create())->post(route('detections.store'), [
            'media_file' => new UploadedFile($path, basename($path), null, null, true),
            'caption_text' => 'is this ai or not', 'analysis_task' => 'claim',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $detection = Detection::sole();
        $result = $detection->fact_check_result;
        $media = $result['media_assessment'];
        $this->assertSame('completed', $media['status'], json_encode($media));
        $this->assertSame($hash, $media['input_sha256']);
        $this->assertSame($hash, hash_file('sha256', Storage::disk('public')->path($detection->media_path)));
        $this->assertSame('sha256:'.hash_file('sha256', base_path(config('services.ai_origin.model_path').'/pytorch_model.pt')), $media['model_version']);
        $this->assertSame(['real', 'fake'], $media['label_mapping']);
        $this->assertSame('RGB', $media['preprocessing']['color_mode']);
        $this->assertSame('transpose', $media['preprocessing']['exif_orientation']);
        $this->assertSame('broad-ai-origin-v1', $media['adapter_version']);
        $this->assertEqualsWithDelta(0.5, $media['threshold'], 0.0000001);
        $this->assertSame($media['fake_probability'] > $media['threshold'] ? 'fake' : 'real', $media['label']);
        if ($media['origin_policy']['inconclusive']) {
            $this->assertSame('INCONCLUSIVE', $result['verdict']);
            $this->assertNull($result['score']['value']);
        } else {
            $this->assertSame($media['label'] === 'fake' ? 'LIKELY_AI_GENERATED' : 'LIKELY_NOT_AI_GENERATED', $result['verdict']);
            $this->assertSame('model_score', $result['score']['type']);
            $this->assertEqualsWithDelta($media['label'] === 'fake' ? $media['fake_probability'] : 1 - $media['fake_probability'], $result['score']['value'], 1e-9);
        }
        $this->assertSame(AssessmentScore::display($result['score']), $result['score_display']);
        $this->assertGreaterThan(0, $media['elapsed_ms']);
        $this->getJson(route('detections.result', $detection))->assertJsonPath('media_assessment.label', $media['label'])->assertJsonMissingPath('media_assessment.fake_probability');
        $html = $this->get(route('detections.result', $detection))->assertOk()->assertSee($result['assessment_label'])->assertDontSee('Needs context')->getContent();
        $audit = ['fixture' => $fixture, 'provenance_label' => $provenance, 'predicted_label' => $media['label'],
            'misclassified' => $provenance === null ? null : $provenance !== $media['label'], 'media_assessment' => $media];
        $directory = storage_path('tmp-media-inference');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        file_put_contents($directory.'/'.basename($fixture).'.json', json_encode($audit, JSON_PRETTY_PRINT));
        file_put_contents($directory.'/'.basename($fixture).'.html', $html);
        fwrite(STDOUT, "\nREAL AI-ORIGIN ".basename($fixture).': '.json_encode(array_intersect_key($audit, array_flip(['predicted_label', 'misclassified'])))."\n");
        Http::assertNothingSent();
    }

    public function test_corrupt_original_returns_unavailable_without_a_content_verdict(): void
    {
        $bytes = substr(file_get_contents(base_path('tests/Fixtures/evidence/media/notice.png')), 0, 48);
        $file = UploadedFile::fake()->createWithContent('corrupt.png', $bytes);
        $this->actingAs(User::factory()->create())->post(route('detections.store'), ['media_file' => $file])->assertSessionHasNoErrors();
        $result = Detection::sole()->fact_check_result;
        $this->assertSame('ANALYSIS_UNAVAILABLE', $result['verdict']);
        $this->assertSame('process_or_response_failed', $result['media_assessment']['failure_code']);
        $this->assertNull($result['media_assessment']['fake_probability']);
        $this->get(route('detections.result', Detection::sole()))->assertOk()->assertSee('Analysis Unavailable')->assertSee('Retry media analysis');
    }
}
