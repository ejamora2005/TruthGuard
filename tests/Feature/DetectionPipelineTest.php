<?php

namespace Tests\Feature;

use App\Models\Detection;
use App\Models\User;
use App\Services\Detections\DeepfakeCnnClassifier;
use App\Services\Detections\DetectionPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DetectionPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.deepfake_cnn.enabled', false);
        config()->set('services.live_verification.enabled', false);
        config()->set('services.openai.analysis_enabled', false);
        config()->set('services.openai.claim_extraction_enabled', false);
    }

    public function test_detection_submission_stores_structured_truthguard_pipeline_output(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->post(route('detections.store'), [
                'source_url' => 'https://www.facebook.com/example/posts/deepfake-flood-warning',
                'source_platform' => 'facebook',
                'caption_text' => 'Breaking flood warning. Share now before this gets deleted.',
                'notes' => 'Looks like a viral disaster post with no clear source.',
                'media_file' => UploadedFile::fake()->image('deepfake-flood-warning.png'),
            ]);

        $detection = Detection::query()->firstOrFail();

        $response->assertRedirect(route('detections.result', $detection));

        $this->assertSame($user->id, $detection->user_id);
        $this->assertSame('upload', $detection->source_kind);
        $this->assertSame('facebook', $detection->platform);
        $this->assertSame('image', $detection->media_type);
        $this->assertSame('completed', $detection->processing_status);
        $this->assertNotNull($detection->caption_text);
        $this->assertNotNull($detection->preprocessing_summary);
        $this->assertNotNull($detection->analysis_summary);
        $this->assertNotNull($detection->verification_summary);
        $this->assertNotNull($detection->explanation_summary);
        $this->assertNotNull($detection->recommendation);
        $this->assertIsArray($detection->signals);
        $this->assertIsArray($detection->verification_sources);
        $this->assertGreaterThanOrEqual(1, count($detection->verification_sources));

        Storage::disk('public')->assertExists((string) $detection->media_path);
    }

    public function test_detection_upload_rejects_files_over_configured_limit(): void
    {
        Storage::fake('public');
        config()->set('truthguard.uploads.media_max_mb', 1);
        config()->set('truthguard.uploads.media_max_kb', 1024);
        config()->set('truthguard.uploads.media_max_bytes', 1024 * 1024);

        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from(route('detections.create'))
            ->post(route('detections.store'), [
                'caption_text' => 'Check this media.',
                'media_file' => UploadedFile::fake()->create('large-proof.pdf', 1025, 'application/pdf'),
            ]);

        $response
            ->assertRedirect(route('detections.create'))
            ->assertSessionHasErrors('media_file');

        $this->assertSame(0, Detection::query()->count());
    }

    public function test_detection_pipeline_uses_deepfake_cnn_for_uploaded_image(): void
    {
        Storage::fake('public');
        config()->set('services.deepfake_cnn.enabled', true);

        $this->app->instance(DeepfakeCnnClassifier::class, new class extends DeepfakeCnnClassifier
        {
            public function classify(?UploadedFile $uploadedFile, string $mediaType, int $heuristicScore = 0): ?array
            {
                return [
                    'status' => 'completed',
                    'model_family' => 'cnn',
                    'screening_score' => 91,
                    'fake_probability' => 0.9082,
                    'threshold' => 0.475,
                    'label' => 'fake',
                    'summary' => 'CNN inference completed with 91% synthetic-media probability.',
                    'signals' => [
                        [
                            'label' => 'CNN model found very strong synthetic-media artifacts',
                            'weight' => 30,
                        ],
                    ],
                ];
            }
        });

        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->post(route('detections.store'), [
                'caption_text' => 'Please verify this uploaded face image.',
                'media_file' => UploadedFile::fake()->image('uploaded-face.png'),
            ]);

        $detection = Detection::query()->latest('id')->firstOrFail();

        $response->assertRedirect(route('detections.result', $detection));

        $this->assertSame('fake', $detection->verdict);
        $this->assertSame(91, $detection->fake_score);
        $this->assertSame('fake', $detection->signals['deepfake_cnn'][0]['verdict']);
        $this->assertSame(0.9082, $detection->signals['deepfake_cnn'][0]['fake_probability']);
        $this->assertStringContainsString('Image CNN', $detection->explanation_summary);
    }

    public function test_detection_pipeline_uses_deepfake_cnn_for_uploaded_video(): void
    {
        Storage::fake('public');
        config()->set('services.deepfake_cnn.enabled', true);

        $this->app->instance(DeepfakeCnnClassifier::class, new class extends DeepfakeCnnClassifier
        {
            public function classify(?UploadedFile $uploadedFile, string $mediaType, int $heuristicScore = 0): ?array
            {
                return [
                    'status' => 'completed',
                    'model_family' => 'cnn',
                    'media_type' => $mediaType,
                    'screening_score' => 71,
                    'fake_probability' => 0.71,
                    'threshold' => 0.68,
                    'label' => 'fake',
                    'summary' => 'CNN video inference completed with 71% synthetic-media probability using top5_mean aggregation across 16 sampled frame(s).',
                    'signals' => [
                        [
                            'label' => 'CNN model found strong synthetic-media artifacts',
                            'weight' => 22,
                        ],
                    ],
                    'frames_used' => 16,
                    'aggregation_method' => 'top5_mean',
                ];
            }
        });

        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->post(route('detections.store'), [
                'caption_text' => 'Please verify this uploaded face video.',
                'media_file' => UploadedFile::fake()->create('uploaded-face-video.mp4', 128, 'video/mp4'),
            ]);

        $detection = Detection::query()->latest('id')->firstOrFail();

        $response->assertRedirect(route('detections.result', $detection));

        $this->assertSame('video', $detection->media_type);
        $this->assertSame('fake', $detection->signals['deepfake_cnn'][0]['verdict']);
        $this->assertSame('video', $detection->signals['deepfake_cnn'][0]['media_type']);
        $this->assertSame('top5_mean', $detection->signals['deepfake_cnn'][0]['aggregation_method']);
        $this->assertSame(16, $detection->signals['deepfake_cnn'][0]['frames_used']);
        $this->assertStringContainsString('Video CNN', $detection->explanation_summary);
    }

    public function test_detection_pipeline_uses_local_filipino_classifier_for_long_article_text(): void
    {
        config()->set('services.filipino_news_classifier.enabled', true);
        config()->set('services.filipino_news_classifier.url', 'http://127.0.0.1:8765');
        config()->set('services.filipino_news_classifier.min_words', 20);

        Http::fake([
            'http://127.0.0.1:8765/predict' => Http::response([
                'verdict' => 'Likely Fake News',
                'predicted_label' => '1',
                'confidence' => 0.94,
                'real_probability' => 0.06,
                'fake_probability' => 0.94,
                'word_count' => 63,
                'reason' => 'Prediction passed length and confidence checks.',
            ]),
        ]);

        $user = User::factory()->create();
        $article = 'Isiniwalat ng isang viral post na may lihim na programa raw ang pamahalaan para alisin ang lahat ng bayarin sa kuryente simula bukas. Ayon sa post, hindi ito ibinalita ng mainstream media at dapat agad itong ibahagi sa lahat ng kaibigan bago raw burahin ng mga opisyal ang anunsyo.';

        $response = $this
            ->actingAs($user)
            ->post(route('detections.store'), [
                'source_platform' => 'web',
                'caption_text' => $article,
            ]);

        $detection = Detection::query()->latest('id')->firstOrFail();

        $response->assertRedirect(route('detections.result', $detection));

        Http::assertSent(fn ($request): bool => $request->url() === 'http://127.0.0.1:8765/predict'
            && $request['article'] === $article
            && $request['min_words'] === 20);

        $this->assertSame('fake', $detection->verdict);
        $this->assertGreaterThanOrEqual(72, $detection->fake_score);
        $this->assertSame('fake', $detection->signals['local_nlp'][0]['verdict']);
        $this->assertSame('1', $detection->signals['local_nlp'][0]['predicted_label']);
        $this->assertStringContainsString('Filipino article classifier', $detection->explanation_summary);
    }

    public function test_detection_center_shows_selected_case_pipeline_sections(): void
    {
        $user = User::factory()->create();

        $detection = Detection::create([
            'user_id' => $user->id,
            'source_kind' => 'link',
            'platform' => 'web',
            'source_url' => 'https://example.com/post',
            'media_type' => 'image',
            'caption_text' => 'Example misleading claim',
            'fake_score' => 52,
            'processing_status' => 'completed',
            'preprocessing_summary' => 'Input metadata was normalized.',
            'analysis_summary' => 'Primary signals were detected from the claim context.',
            'verification_summary' => 'Prepared 2 verification routes for this case.',
            'explanation_summary' => 'Needs Review at 52% risk because the strongest signal was: the source lacks corroboration.',
            'recommendation' => 'Hold this case for manual review.',
            'signals' => [
                'visual' => [],
                'textual' => [
                    ['label' => 'Caption uses high-urgency language', 'weight' => 14],
                ],
                'contextual' => [],
            ],
            'verification_sources' => [
                [
                    'name' => 'Google Fact Check Explorer',
                    'status' => 'ready-for-integration',
                    'purpose' => 'Cross-check fact-check archives.',
                    'url' => 'https://toolbox.google.com/factcheck/explorer',
                ],
            ],
            'verdict' => 'review',
            'notes' => 'Investigate this claim.',
            'analyzed_at' => now(),
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('detections.result', $detection));

        $response->assertOk();
        $response->assertDontSee('Latest Result Ready');
        $response->assertDontSee('Latest Detection Result');
        $response->assertSee('Result');
        $response->assertSee('AI confidence');
        $response->assertSee('52%');
        $response->assertSee('Needs Review at 52% risk because the strongest signal was: the source lacks corroboration.');
        $response->assertSee('Review strongest source');
        $response->assertSee('Source verification');
        $response->assertSee('Google Fact Check Explorer');
    }

    public function test_detection_center_shows_uploaded_media_result_panel(): void
    {
        $user = User::factory()->create();

        $detection = Detection::create([
            'user_id' => $user->id,
            'source_kind' => 'upload',
            'platform' => 'web',
            'source_url' => null,
            'media_path' => 'detections/example-proof.png',
            'media_type' => 'image',
            'caption_text' => 'Check this uploaded image.',
            'fake_score' => 24,
            'processing_status' => 'completed',
            'preprocessing_summary' => 'Image input was normalized for metadata-aware scoring.',
            'analysis_summary' => 'No strong indicators were detected.',
            'verification_summary' => 'Prepared 1 verification route for this case.',
            'explanation_summary' => 'Likely Real at 24% risk because the strongest signal was: limited corroborating context.',
            'recommendation' => 'Keep a quick verification pass before reuse.',
            'signals' => [
                'visual' => [],
                'textual' => [],
                'contextual' => [],
            ],
            'verification_sources' => [
                [
                    'name' => 'Trusted newsroom review',
                    'status' => 'ready-for-integration',
                    'purpose' => 'Compare the claim with reporting from reputable news organizations before sharing.',
                    'url' => 'https://www.reuters.com/fact-check/',
                ],
            ],
            'verdict' => 'real',
            'notes' => null,
            'analyzed_at' => now(),
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('detections.result', $detection));

        $response->assertOk();
        $this->assertSame('/storage/detections/example-proof.png', $detection->media_url);
        $response->assertSee('latest-detection-result', false);
        $response->assertSee('Likely Real at 24% risk because the strongest signal was: limited corroborating context.');
        $response->assertSee('/storage/detections/example-proof.png');
    }

    public function test_dashboard_context_submission_still_returns_to_detection_center(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->post(route('detections.store'), [
                'caption_text' => 'Check this uploaded image for manipulation.',
                'media_file' => UploadedFile::fake()->image('uploaded-proof.png'),
                'return_context' => 'dashboard',
            ]);

        $detection = Detection::query()->latest('id')->firstOrFail();

        $response->assertRedirect(route('detections.result', $detection));

        Storage::disk('public')->assertExists((string) $detection->media_path);
    }

    public function test_ai_composer_renders_a_standard_detection_form_for_fallback_safe_submission(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get(route('detections.create'));

        $response->assertOk();
        $response->assertSee('name="return_context"', false);
        $response->assertSee('name="media_file"', false);
        $response->assertSee('name="caption_text"', false);
        $response->assertDontSee('name="source_url"', false);
        $response->assertDontSee('placeholder="Paste source link..."', false);
        $response->assertDontSee('Result Preview');
        $response->assertDontSee('latest-detection-result', false);
    }

    public function test_detection_center_does_not_show_an_old_result_without_a_new_selected_detection(): void
    {
        $user = User::factory()->create();

        Detection::create([
            'user_id' => $user->id,
            'source_kind' => 'upload',
            'platform' => 'web',
            'source_url' => null,
            'media_path' => 'detections/old-proof.png',
            'media_type' => 'image',
            'caption_text' => 'Old uploaded image.',
            'fake_score' => 18,
            'processing_status' => 'completed',
            'preprocessing_summary' => 'Image was analyzed for visual characteristics.',
            'analysis_summary' => 'No strong indicators were detected.',
            'verification_summary' => 'Prepared 2 verification routes for this case.',
            'explanation_summary' => 'Likely Real at 18% risk because the strongest signal was: limited corroborating context.',
            'recommendation' => 'Keep a quick context check before reuse.',
            'signals' => [
                'visual' => [],
                'textual' => [],
                'contextual' => [],
            ],
            'verification_sources' => [
                [
                    'name' => 'Google Fact Check Explorer',
                    'status' => 'ready-for-integration',
                    'purpose' => 'Cross-check fact-check archives.',
                    'url' => 'https://toolbox.google.com/factcheck/explorer',
                ],
            ],
            'verdict' => 'real',
            'notes' => null,
            'analyzed_at' => now(),
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('detections.create'));

        $response->assertOk();
        $response->assertDontSee('Result Preview');
        $response->assertDontSee('latest-detection-result', false);
        $response->assertDontSee('Likely Real at 18% risk because the strongest signal was: limited corroborating context.');
        $response->assertDontSee('Open reliable source');
        $response->assertDontSee('/storage/detections/old-proof.png');
    }

    public function test_detection_submission_extracts_a_pasted_link_from_the_main_text_box(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->post(route('detections.store'), [
                'caption_text' => 'https://example.com/fact-check-source Please verify this suspicious claim.',
            ]);

        $detection = Detection::query()->latest('id')->firstOrFail();

        $response->assertRedirect(route('detections.result', $detection));
        $this->assertSame('https://example.com/fact-check-source', $detection->source_url);
        $this->assertSame('Please verify this suspicious claim.', $detection->caption_text);
    }

    public function test_detection_pipeline_can_persist_an_uploaded_file_when_realpath_is_unavailable(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $tempPath = tempnam(sys_get_temp_dir(), 'truthguard-upload-');

        if ($tempPath === false) {
            $this->fail('Unable to create a temporary upload fixture.');
        }

        file_put_contents($tempPath, 'simulated-image-binary');

        $uploadedFile = new class($tempPath) extends UploadedFile
        {
            public function __construct(string $path)
            {
                parent::__construct($path, 'fallback-proof.png', 'image/png', null, true);
            }

            public function getRealPath(): string|false
            {
                return false;
            }
        };

        $detection = app(DetectionPipeline::class)->run(
            $user,
            [
                'source_url' => null,
                'caption_text' => 'Please verify this uploaded file.',
                'notes' => null,
                'source_platform' => 'web',
            ],
            $uploadedFile,
        );

        $this->assertNotNull($detection->media_path);
        Storage::disk('public')->assertExists((string) $detection->media_path);

        @unlink($tempPath);
    }

    public function test_detection_submission_reuses_matching_analysis_across_users(): void
    {
        Storage::fake('public');

        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();

        $firstResponse = $this
            ->actingAs($firstUser)
            ->post(route('detections.store'), [
                'source_url' => 'https://example.com/shared-viral-claim',
                'source_platform' => 'web',
                'caption_text' => 'Check whether this uploaded image is fake.',
                'media_file' => $this->createSharedUpload(),
            ]);

        $firstDetection = Detection::query()
            ->where('user_id', $firstUser->id)
            ->latest('id')
            ->firstOrFail();

        $secondResponse = $this
            ->actingAs($secondUser)
            ->post(route('detections.store'), [
                'source_url' => 'https://example.com/shared-viral-claim',
                'source_platform' => 'web',
                'caption_text' => 'Check whether this uploaded image is fake.',
                'media_file' => $this->createSharedUpload(),
            ]);

        $secondDetection = Detection::query()
            ->where('user_id', $secondUser->id)
            ->latest('id')
            ->firstOrFail();

        $firstResponse->assertRedirect(route('detections.result', $firstDetection));
        $secondResponse->assertRedirect(route('detections.result', $secondDetection));

        $this->assertSame($firstDetection->request_fingerprint, $secondDetection->request_fingerprint);
        $this->assertSame($firstDetection->fake_score, $secondDetection->fake_score);
        $this->assertSame($firstDetection->verdict, $secondDetection->verdict);
        $this->assertSame($firstDetection->id, $secondDetection->reused_from_detection_id);
        $this->assertStringContainsString('reused the stored result', (string) $secondDetection->preprocessing_summary);
    }

    private function createSharedUpload(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'shared-proof.png',
            (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO2Z3ioAAAAASUVORK5CYII=')
        );
    }
}
