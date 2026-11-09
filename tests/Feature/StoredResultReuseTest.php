<?php

namespace Tests\Feature;

use App\Models\ClaimVariation;
use App\Models\Detection;
use App\Models\ReviewedClaim;
use App\Models\User;
use App\Services\Detections\AiOriginClassifier;
use App\Services\Detections\DeepfakeCnnClassifier;
use App\Services\Detections\DetectionPipeline;
use App\Services\Detections\Evidence\ArticleSearch;
use App\Services\Detections\Evidence\SubmissionExtractor;
use App\Services\Detections\EvidenceVerificationService;
use App\Services\Detections\GoogleFactCheckFeedService;
use App\Services\Detections\ReportAnswer;
use App\Services\Detections\ReviewedClaimLookup;
use Database\Seeders\ReviewedClaimSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
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

    private const POSTER_HEADLINE = 'SHOOT-TO-KILL KAY SEN, BATO, IHAHAIN?!';

    /** Exercise the real registry and cache; external evidence gathering is fixed. */
    private function mockReviewedAnalysis(int $calls): void
    {
        $this->travelTo(now()->setDate(2026, 11, 6)->startOfDay());
        $this->mock(DeepfakeCnnClassifier::class)->shouldNotReceive('classify');
        $this->mock(AiOriginClassifier::class)->shouldNotReceive('classify');
        $this->mock(EvidenceVerificationService::class)->shouldReceive('apply')->times($calls)
            ->andReturnUsing(function ($analysis) {
                $result = ReviewedClaimLookup::lookup(self::POSTER_HEADLINE, now()->toIso8601String())
                    ?? ReportAnswer::complete(['schema_version' => 4, 'primary_task' => 'claim',
                        'claim' => self::POSTER_HEADLINE, 'verdict' => 'UNVERIFIED',
                        'summary' => 'No directly resolving passage was retrieved.']);
                $result['extraction']['analysis_text'] = self::POSTER_HEADLINE;
                $result['assessed_at'] = $result['evidence_cutoff'] = now()->toIso8601String();

                return array_replace($analysis, ['fact_check_result' => $result,
                    'analysis_summary' => $result['summary'], 'explanation_summary' => $result['summary'],
                    'signals' => ['openai_usage' => ['input_tokens' => 100, 'output_tokens' => 20, 'total_tokens' => 120]]]);
            });
    }

    public static function newlyApprovedAnswers(): array
    {
        return ['new reviewed answer' => [false], 'new approved variation' => [true]];
    }

    #[DataProvider('newlyApprovedAnswers')]
    public function test_an_approved_answer_invalidates_a_cached_unverified_poster(bool $existingReview): void
    {
        $this->mockReviewedAnalysis(2);
        if ($existingReview) {
            $this->seed(ReviewedClaimSeeder::class);
            ClaimVariation::where('text_hash', hash('sha256', ReviewedClaimLookup::normalize(self::POSTER_HEADLINE)))->delete();
        }
        $user = User::factory()->create();
        $input = ['analysis_task' => 'claim', 'caption_text' => null];
        $first = $this->submitFixture($user, $input);
        $before = $first->fresh()->getRawOriginal();
        $this->assertSame('UNVERIFIED', $first->fact_check_result['verdict']);
        $this->assertSame($first->id, $this->submitFixture($user, $input)->reused_from_detection_id);

        if ($existingReview) {
            ReviewedClaim::where('slug', 'suan-fabricated-bato-shoot-to-kill-quote')->firstOrFail()
                ->variations()->create(['text' => self::POSTER_HEADLINE]);
        } else {
            $this->seed(ReviewedClaimSeeder::class);
        }
        $this->travel(1)->minutes();
        $fresh = $this->submitFixture($user, $input);
        $this->assertNull($fresh->reused_from_detection_id);
        $this->assertSame('FALSE', $fresh->fact_check_result['verdict']);
        $this->assertNotEmpty($fresh->fact_check_result['sources']);
        $this->assertSame('exact_normalized_variation', $fresh->fact_check_result['diagnostics']['match_method']);
        $this->assertSame($fresh->id, ($repeat = $this->submitFixture($user, $input))->reused_from_detection_id);
        $this->assertSame(0, $repeat->signals['openai_usage']['total_tokens']);
        $this->assertSame($before, $first->fresh()->getRawOriginal());
        Http::assertNothingSent();
    }

    public static function reviewChanges(): array
    {
        return ['disabled' => ['disable'], 'expired without a write' => ['expire'],
            'changed explanation' => ['revise'], 'removed variation' => ['remove']];
    }

    #[DataProvider('reviewChanges')]
    public function test_a_changed_or_withdrawn_review_cannot_survive_in_the_result_cache(string $change): void
    {
        $this->mockReviewedAnalysis(2);
        $this->seed(ReviewedClaimSeeder::class);
        $review = ReviewedClaim::where('slug', 'suan-fabricated-bato-shoot-to-kill-quote')->firstOrFail();
        if ($change === 'expire') {
            $review->update(['expires_at' => now()->addMinute()]);
        }
        $user = User::factory()->create();
        $input = ['analysis_task' => 'claim', 'caption_text' => null];
        $first = $this->submitFixture($user, $input);
        $before = $first->fresh()->getRawOriginal();
        $this->assertSame('FALSE', $first->fact_check_result['verdict']);
        $this->assertSame($first->id, $this->submitFixture($user, $input)->reused_from_detection_id);

        match ($change) {
            'disable' => $review->update(['active' => false]),
            'expire' => $this->travel(2)->minutes(),
            'revise' => $review->update(['result' => array_replace($review->result, ['summary' => 'Updated reviewed explanation.'])]),
            'remove' => $review->variations()->where('text_hash', hash('sha256', ReviewedClaimLookup::normalize(self::POSTER_HEADLINE)))->delete(),
        };
        $fresh = $this->submitFixture($user, $input);
        $this->assertNull($fresh->reused_from_detection_id);
        $this->assertSame($change === 'revise' ? 'FALSE' : 'UNVERIFIED', $fresh->fact_check_result['verdict']);
        if ($change === 'revise') {
            $this->assertSame('Updated reviewed explanation.', $fresh->fact_check_result['summary']);
        }
        $this->assertSame($fresh->id, $this->submitFixture($user, $input)->reused_from_detection_id);
        $this->assertSame($before, $first->fresh()->getRawOriginal());
        Http::assertNothingSent();
    }

    public function test_unrelated_review_changes_do_not_repeat_paid_analysis(): void
    {
        $this->mockReviewedAnalysis(1);
        $this->seed(ReviewedClaimSeeder::class);
        $user = User::factory()->create();
        $input = ['analysis_task' => 'claim', 'caption_text' => null];
        $first = $this->submitFixture($user, $input);
        $other = ReviewedClaim::where('slug', 'hev-abi-fake-bucket-quote')->firstOrFail();
        $other->update(['active' => false]);
        $other->variations()->create(['text' => 'An unrelated new approved headline.']);
        $repeat = $this->submitFixture($user, $input);
        $this->assertSame($first->id, $repeat->reused_from_detection_id);
        $this->assertSame(0, $repeat->signals['openai_usage']['total_tokens']);
        Http::assertNothingSent();
    }

    public function test_installing_missing_variations_reaches_the_real_pipeline_after_a_cached_unresolved_image(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 6)->startOfDay());
        config()->set(['services.openai.analysis_enabled' => true, 'services.openai.key' => 'fixture',
            'services.live_verification.enabled' => true, 'fact_check.web_search.enabled' => false,
            'fact_check.retrieval.max_rounds' => 1]);
        $this->mock(DeepfakeCnnClassifier::class)->shouldNotReceive('classify');
        $this->mock(AiOriginClassifier::class)->shouldNotReceive('classify');
        $this->mock(SubmissionExtractor::class)->shouldReceive('extract')->twice()->andReturn([
            'status' => 'completed', 'analysis_text' => self::POSTER_HEADLINE, 'visible_text' => self::POSTER_HEADLINE,
            'original_text' => self::POSTER_HEADLINE, 'reconstructed_text' => self::POSTER_HEADLINE,
            'input_scope' => 'visible image text', 'limitations' => [], 'source_record' => null,
            'usage' => ['input_tokens' => 90, 'output_tokens' => 10, 'total_tokens' => 100],
        ]);
        $search = $this->mock(ArticleSearch::class);
        $search->shouldReceive('prefetchExactReviews')->once()->andReturn([]);
        $search->shouldReceive('retrieve')->once()->andReturn([]);
        Http::fake(['api.openai.com/*' => function ($request) {
            $this->assertSame('claim_decomposition', $request['text']['format']['name']);

            return Http::response(['status' => 'completed',
                'usage' => ['input_tokens' => 180, 'output_tokens' => 20, 'total_tokens' => 200],
                'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode([
                    'claims' => [['quote' => self::POSTER_HEADLINE, 'kind' => 'factual', 'requires_context' => false,
                        'context_reason' => '', 'entities' => [], 'dates' => [], 'locations' => [],
                        'quantities' => [], 'qualifiers' => [], 'searches' => ['exact poster headline'], 'references' => []]],
                    'date_quote' => '', 'original_claim_date' => null,
                ])]]]]]);
        }]);
        $this->seed(ReviewedClaimSeeder::class);
        ClaimVariation::where('text_hash', hash('sha256', ReviewedClaimLookup::normalize(self::POSTER_HEADLINE)))->delete();
        $user = User::factory()->create();
        $input = ['analysis_task' => 'claim', 'caption_text' => null];
        $first = $this->submitFixture($user, $input);
        $this->assertContains($first->fact_check_result['verdict'], ['UNVERIFIED', 'MISSING_CONTEXT']);
        $this->assertSame($first->id, $this->submitFixture($user, $input)->reused_from_detection_id);
        $this->artisan('truthguard:install-reviewed-variations', [
            'slug' => 'suan-fabricated-bato-shoot-to-kill-quote', '--apply' => true,
        ])->assertSuccessful();
        $this->travel(1)->minutes();
        $fresh = $this->submitFixture($user, $input);
        $this->assertNull($fresh->reused_from_detection_id);
        $this->assertSame('FALSE', $fresh->fact_check_result['verdict']);
        $this->assertSame('suan-fabricated-bato-shoot-to-kill-quote', $fresh->fact_check_result['diagnostics']['reviewed_claim_slug']);
        $this->assertSame(100, $fresh->signals['openai_usage']['total_tokens']);
        $repeat = $this->submitFixture($user, $input);
        $this->assertSame($fresh->id, $repeat->reused_from_detection_id);
        $this->assertSame(0, $repeat->signals['openai_usage']['total_tokens']);
        Http::assertSentCount(1); // Only the initial, mocked claim planner; no repeated research.
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
        $this->assertSame($first->fact_check_result['verdict'], $second->fact_check_result['verdict']);
        $this->assertSame($first->fact_check_result['summary'], $second->fact_check_result['summary']);
        $this->assertNotSame(
            $first->fact_check_result['generated_wording']['explanation'],
            $second->fact_check_result['generated_wording']['explanation'],
        );
        $this->assertTrue($second->analyzed_at->eq($first->analyzed_at));
        $this->assertNotSame($first->media_path, $second->media_path);
        $this->assertSame(0, $second->signals['openai_usage']['total_tokens']);
        $this->assertTrue($second->signals['result_cache']['hit']);
        $this->assertTrue($second->fact_check_result['diagnostics']['result_cache_hit']);
        $this->assertSame(0, $second->fact_check_result['diagnostics']['api_usage']['total_tokens']);
        $this->assertFalse($first->signals['result_cache']['hit']);
        $this->assertTrue($second->signals['result_reuse']['wording_refreshed_locally']);
        $this->assertSame(120, $second->signals['result_reuse']['original_openai_usage']['total_tokens']);
        $this->assertSame($snapshot, $first->fresh()->getRawOriginal());
        Storage::disk('public')->assertExists($second->media_path);
        $this->mock(GoogleFactCheckFeedService::class)->shouldNotReceive('latest');
        $this->actingAs($other)->get(route('detections.result', $second))->assertOk()->assertSee('Saved result reused.')
            ->assertSee('This saved assessment was reused without a new analysis.')->assertSee('Reused saved assistance');
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
        $this->mockAnalysis(5, 'media', 4);
        $user = User::factory()->create();
        $first = $this->submitFixture($user);
        foreach ([['caption_text' => 'Is this event real?'], ['notes' => 'Check a different context.'], ['analysis_task' => 'claim']] as $extra) {
            $this->assertNull($this->submitFixture($user, $extra)->reused_from_detection_id);
        }
        // Appending transport bytes does not change the decoded picture, so an
        // origin-only request safely reuses the visual identity.
        $this->assertSame($first->id, $this->submitFixture($user, [], null, 'different image bytes')->reused_from_detection_id);
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
        $fresh->update(['processing_status' => 'processing']); // The retry controller claims the report before running it.
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
        $third = $this->submitFixture($user, $input);
        $this->assertSame($first->id, $third->reused_from_detection_id);
        $this->assertSame(0, $third->signals['openai_usage']['total_tokens']);
        $this->travel(1)->days();
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

    public function test_reencoded_media_only_uploads_reuse_the_saved_result_in_auto_mode(): void
    {
        $this->mockAnalysis(1);
        $user = User::factory()->create();
        $image = imagecreatefrompng(base_path('tests/Fixtures/evidence/media/notice.png'));
        try {
            ob_start();
            imagepng($image, null, 0);
            $uncompressed = ob_get_clean();
            ob_start();
            imagepng($image, null, 9);
            $compressed = ob_get_clean();
        } finally {
            imagedestroy($image);
        }
        $first = app(DetectionPipeline::class)->run($user, ['analysis_task' => 'auto'],
            UploadedFile::fake()->createWithContent('image.png', $uncompressed));
        $second = app(DetectionPipeline::class)->run($user, ['analysis_task' => 'auto'],
            UploadedFile::fake()->createWithContent('image.png', $compressed));

        $this->assertNotSame($first->media_checksum, $second->media_checksum);
        $this->assertSame($first->id, $second->reused_from_detection_id);
        $this->assertSame(0, $second->signals['openai_usage']['total_tokens']);
    }

    public function test_perceptual_reuse_cannot_bypass_factual_verification_after_auto_routing(): void
    {
        $this->mockAnalysis(2, 'claim', 2);
        $user = User::factory()->create();
        $first = app(DetectionPipeline::class)->run($user, ['analysis_task' => 'auto'], $this->upload('one encoding'));
        $second = app(DetectionPipeline::class)->run($user, ['analysis_task' => 'auto'], $this->upload('another encoding'));
        $this->assertSame($first->media_perceptual_hash, $second->media_perceptual_hash);
        $this->assertNull($second->reused_from_detection_id);
    }

    public function test_explicit_refresh_reaches_the_evidence_service(): void
    {
        $this->mock(AiOriginClassifier::class)->shouldNotReceive('classify');
        $this->mock(EvidenceVerificationService::class)->shouldReceive('apply')->once()
            ->withArgs(fn ($analysis, $context) => $context['force_refresh'] === true)
            ->andReturnUsing(fn ($analysis) => array_replace($analysis, ['fact_check_result' => ReportAnswer::complete([
                'schema_version' => 4, 'primary_task' => 'claim', 'verdict' => 'UNVERIFIED', 'summary' => 'Fresh evidence was requested.',
            ])]));
        $result = app(DetectionPipeline::class)->run(User::factory()->create(), [
            'analysis_task' => 'claim', 'caption_text' => 'The bridge opened in 2010.', 'force_refresh' => true,
        ], null);
        $this->assertNull($result->reused_from_detection_id);
    }

    public function test_failed_optional_narration_does_not_repeat_a_completed_detector_analysis(): void
    {
        $this->mockAnalysis(1);
        $user = User::factory()->create();
        $first = $this->submitFixture($user);
        $signals = $first->signals;
        $signals['media_explanation'] = [['status' => 'unavailable', 'failure_reason' => 'request_or_validation_failed']];
        $first->update(['signals' => $signals]);
        $second = $this->submitFixture($user);
        $this->assertSame($first->id, $second->reused_from_detection_id);
        $this->assertSame(0, $second->signals['openai_usage']['total_tokens']);
    }

    public function test_perceptually_identical_png_jpeg_and_webp_reuse_an_abstaining_media_result(): void
    {
        $this->mockAnalysis(1);
        $user = User::factory()->create();
        $first = null;
        foreach (['png', 'jpg', 'webp'] as $extension) {
            $file = UploadedFile::fake()->createWithContent('notice.'.$extension,
                file_get_contents(base_path('tests/Fixtures/evidence/media/notice.'.$extension)));
            $result = app(DetectionPipeline::class)->run($user, ['analysis_task' => 'media'], $file);
            if ($first === null) {
                $first = $result;
            } else {
                $this->assertNotSame($first->media_checksum, $result->media_checksum);
                $this->assertSame($first->id, $result->reused_from_detection_id);
                $this->assertSame('INCONCLUSIVE', $result->fact_check_result['verdict']);
            }
        }
    }

    public function test_different_images_with_the_same_dhash_do_not_reuse_a_verdict(): void
    {
        $this->mockAnalysis(2);
        $user = User::factory()->create();
        $detections = [];
        foreach ([[255, 0, 0], [0, 0, 255]] as $rgb) {
            $image = imagecreatetruecolor(640, 640);
            imagefill($image, 0, 0, imagecolorallocate($image, ...$rgb));
            ob_start();
            imagepng($image);
            $bytes = ob_get_clean();
            imagedestroy($image);
            $detections[] = app(DetectionPipeline::class)->run($user, ['analysis_task' => 'media'],
                UploadedFile::fake()->createWithContent('image.png', $bytes));
        }
        $this->assertSame($detections[0]->media_perceptual_hash, $detections[1]->media_perceptual_hash);
        $this->assertNull($detections[1]->reused_from_detection_id);
    }
}
