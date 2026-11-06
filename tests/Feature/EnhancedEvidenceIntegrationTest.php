<?php

namespace Tests\Feature;

use App\Models\Detection;
use App\Models\PublicClaimReview;
use App\Models\User;
use App\Services\Detections\AiOriginClassifier;
use App\Services\Detections\Evidence\ArticleSearch;
use App\Services\Detections\Evidence\ClaimPlanner;
use App\Services\Detections\Evidence\RetrievalBudget;
use App\Services\Detections\Evidence\SafeArticleFetcher;
use App\Services\Detections\Evidence\StructuredAnalysis;
use App\Services\Detections\Evidence\SubmissionExtractor;
use App\Services\Detections\EvidenceVerificationService;
use App\Services\Detections\MediaAnalysisReport;
use App\Services\Detections\ReportAnswer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EnhancedEvidenceIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 6)->setTime(12, 0));
        Http::preventStrayRequests();
        config()->set(['services.live_verification.enabled' => true, 'fact_check.web_search.enabled' => false,
            'services.google_fact_check.key' => '', 'services.gnews.key' => '', 'services.newsapi.key' => '',
            'services.filipino_news_classifier.enabled' => false, 'services.openai.analysis_enabled' => false,
            'fact_check.retrieval.respect_robots' => false, 'fact_check.retrieval.max_rounds' => 2]);
        $this->partialMock(SafeArticleFetcher::class)->shouldReceive('publicAddress')->andReturn('93.184.216.34');
        $this->partialMock(StructuredAnalysis::class)->shouldReceive('ask')->andReturn([
            'relationships' => [], 'context_findings' => [], 'wording' => [],
        ]);
    }

    private function plan(string $text): void
    {
        $planner = $this->mock(ClaimPlanner::class);
        $planner->shouldReceive('plan')->andReturn(['status' => 'completed', 'original_claim_date' => null, 'claims' => [[
            'id' => 'c1', 'quote' => $text, 'text' => $text, 'kind' => 'factual', 'requires_context' => true,
            'context_reason' => 'An imperfect headline still needs research.', 'references' => [],
            'searches' => [$text], 'entities' => [], 'locations' => [], 'dates' => [], 'quantities' => [], 'qualifiers' => [],
        ]]]);
        $planner->shouldReceive('queries')->andReturn([$text]);
    }

    private function review(string $claim, string $rating = 'False', string $publisher = 'Rappler', string $url = 'https://rappler.com/fact-check/test'): void
    {
        PublicClaimReview::create(['feed_item_id' => hash('sha256', $url), 'publisher' => $publisher,
            'headline' => 'A publisher title that need not repeat the proposition', 'claim' => $claim,
            'rating' => $rating, 'url' => $url, 'published_at' => '2025-01-01',
            'source_payload' => ['full_claim' => $claim, 'source_rating' => $rating]]);
        Http::fake([$url => Http::response('<article>The published review examines this assertion: '.$claim.'. Its published rating is '.$rating.'.</article>',
            200, ['Content-Type' => 'text/html'])]);
    }

    public static function requestedHeadlines(): array
    {
        return [
            ['Posts on Rep. Suan’s ‘shoot-to-kill’ resolution vs Bato dela Rosa FAKE', 'Suan filed a shoot-to-kill resolution against Bato dela Rosa'],
            ['Suan filed a shoot-to-kill resolution against Bato dela Rosa', 'Suan filed a shoot-to-kill resolution against Bato dela Rosa'],
            ['GMA SARADO NA?! HATOL SA KORTE?!', 'GMA Network shutdown due to court order'],
        ];
    }

    #[DataProvider('requestedHeadlines')]
    public function test_saved_exact_publisher_reviews_reach_the_enhanced_pipeline(string $submitted, string $reviewed): void
    {
        $this->plan($submitted);
        $this->review($reviewed);
        $result = app(EvidenceVerificationService::class)->apply(['signals' => []], [
            'analysis_task' => 'claim', 'media_type' => 'text', 'caption_text' => $submitted,
        ])['fact_check_result'];

        $this->assertSame('FALSE', $result['verdict']);
        $this->assertSame('applied', $result['exact_fact_check']['status']);
        $this->assertSame('refuted', $result['claims'][0]['outcome']);
        $this->assertSame('False', $result['sources'][0]['published_rating']);
        $this->assertStringContainsString($reviewed, $result['sources'][0]['relationships'][0]['excerpt']);
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.openai.com'));
    }

    public function test_conflicting_saved_exact_reviews_force_abstention_in_the_final_report(): void
    {
        $claim = 'Suan filed a resolution against Bato';
        $this->plan($claim);
        $this->review($claim);
        $this->review($claim, 'True', 'AFP', 'https://factcheck.afp.com/review');
        $result = app(EvidenceVerificationService::class)->apply(['signals' => []], [
            'analysis_task' => 'claim', 'media_type' => 'text', 'caption_text' => $claim,
        ])['fact_check_result'];

        $this->assertSame('CONFLICTING_EVIDENCE', $result['verdict']);
        $this->assertSame('conflicting', $result['claims'][0]['outcome']);
        $this->assertTrue($result['editorial_review']['required']);
        $this->assertCount(2, $result['sources']);
        Http::assertSentCount(2);
    }

    public function test_an_exact_saved_review_avoids_both_planning_and_evidence_model_calls(): void
    {
        $claim = 'Suan filed a resolution against Bato';
        $this->mock(ClaimPlanner::class)->shouldNotReceive('plan');
        $this->partialMock(StructuredAnalysis::class)->shouldNotReceive('ask');
        $this->review($claim);
        $result = app(EvidenceVerificationService::class)->apply(['signals' => []], [
            'analysis_task' => 'claim', 'media_type' => 'text', 'caption_text' => $claim,
        ])['fact_check_result'];

        $this->assertSame('FALSE', $result['verdict']);
        $this->assertTrue($result['diagnostics']['exact_review_without_model']);
        $this->assertSame(1, $result['diagnostics']['sources_used']);
        $this->assertSame('matched_evidence', $result['diagnostics']['outcome_reason']);
        $this->assertSame([], $result['diagnostics']['filter_rejections']);
        $this->assertSame(0, $result['diagnostics']['api_usage']['total_tokens']);
        Http::assertSentCount(1);
    }

    public function test_a_publisher_page_can_supply_its_exact_rating_without_a_search_api_or_saved_catalogue_entry(): void
    {
        $claim = 'Suan filed a resolution against Bato';
        $url = 'https://rappler.com/fact-check/publisher-structured-rating';
        $this->mock(ClaimPlanner::class)->shouldNotReceive('plan');
        $this->partialMock(StructuredAnalysis::class)->shouldNotReceive('ask');
        $schema = ['@graph' => [['@type' => ['CreativeWork', 'ClaimReview'], 'url' => $url,
            'claimReviewed' => $claim, 'reviewRating' => ['alternateName' => 'False'], 'datePublished' => '2025-01-01']]];
        Http::fake([$url => Http::response('<script type="application/ld+json">'.json_encode($schema).'</script><article>'
            .str_repeat('The publisher review includes the original records. ', 4).'</article>', 200, ['Content-Type' => 'text/html'])]);
        $result = app(EvidenceVerificationService::class)->apply(['signals' => []], [
            'analysis_task' => 'claim', 'media_type' => 'link', 'caption_text' => $claim, 'source_url' => $url,
        ])['fact_check_result'];

        $this->assertSame('FALSE', $result['verdict']);
        $this->assertSame('False', $result['sources'][0]['published_rating']);
        $this->assertSame(0, $result['diagnostics']['api_usage']['total_tokens']);
        $this->assertStringNotContainsString('application/ld+json', $result['sources'][0]['relationships'][0]['excerpt']);
        Http::assertSentCount(1);
    }

    public function test_an_inaccessible_supporting_link_does_not_replace_a_supplied_claim(): void
    {
        $claim = 'Suan filed a resolution against Bato';
        $this->plan($claim);
        $this->review($claim);
        $url = 'https://news.example.test/deleted';
        Http::fake([$url => Http::response('', 404)]);
        $result = app(EvidenceVerificationService::class)->apply(['signals' => []], [
            'analysis_task' => 'claim', 'media_type' => 'link', 'source_url' => $url, 'caption_text' => $claim,
        ])['fact_check_result'];

        $this->assertSame('FALSE', $result['verdict']);
        $this->assertSame($claim, $result['claim']);
        $this->assertStringNotContainsString('upload a clear screenshot', $result['generated_wording']['recommended_action']);
        Http::assertSentCount(2);
    }

    public function test_refresh_bypasses_both_page_cache_and_search_cache(): void
    {
        $claim = 'The bridge opened in 2010';
        $this->plan($claim);
        $url = 'https://records.gov.ph/bridge';
        Http::fake([$url => Http::sequence()->push(str_repeat('An old bridge record. ', 8), 200, ['Content-Type' => 'text/plain'])
            ->push(str_repeat('An updated bridge record. ', 8), 200, ['Content-Type' => 'text/plain'])]);
        app(SafeArticleFetcher::class)->fetch($url, new RetrievalBudget);
        $search = app(ArticleSearch::class);
        $mock = $this->partialMock(ArticleSearch::class);
        $mock->shouldReceive('reviewedSubmission')->andReturnUsing($search->reviewedSubmission(...));
        $mock->shouldReceive('retrieve')->atLeast()->once()
            ->withArgs(fn ($queries, $budget) => $budget->freshSources === true)->andReturn([]);
        $result = app(EvidenceVerificationService::class)->apply(['signals' => []], [
            'analysis_task' => 'claim', 'media_type' => 'link', 'source_url' => $url,
            'caption_text' => $claim, 'force_refresh' => true,
        ])['fact_check_result'];

        $this->assertStringContainsString('updated bridge record', $result['extraction']['source_record']['text']);
        Http::assertSentCount(2);
    }

    public function test_fact_check_metadata_is_retained_when_web_search_found_the_url_first(): void
    {
        config()->set(['services.google_fact_check.key' => 'offline-test', 'fact_check.retrieval.max_search_requests' => 1]);
        $claim = 'Suan filed a resolution against Bato';
        $url = 'https://rappler.com/fact-check/test';
        Http::fake(['factchecktools.googleapis.com/*' => Http::response(['claims' => [['text' => $claim,
            'claimReview' => [['url' => $url, 'publisher' => ['name' => 'Rappler'], 'textualRating' => 'False']]]]])]);
        $key = app(ArticleSearch::class)->canonicalUrl($url);
        $initial = [$key => ['id' => 's1', 'url' => $url, 'provider' => 'openai_web_search', 'source_type' => 'fact_check',
            'title' => 'Publisher review', 'retrieval_status' => 'retrieved', 'text' => 'The reviewed claim is false.',
            'content_hash' => hash('sha256', 'The reviewed claim is false.')]];
        $records = app(ArticleSearch::class)->retrieve([$claim], new RetrievalBudget, now()->utc()->toIso8601String(), $initial);

        $this->assertSame($claim, $records[$key]['reviewed_claim']);
        $this->assertSame('False', $records[$key]['review_rating']);
        $this->assertSame('retrieved', $records[$key]['retrieval_status']);
        Http::assertSentCount(1);
    }

    public function test_identical_article_bodies_cannot_hide_conflicting_published_ratings(): void
    {
        $text = 'This is the same retrieved review body.';
        $base = ['id' => 's1', 'retrieval_status' => 'retrieved', 'text' => $text,
            'content_hash' => hash('sha256', $text), 'review_rating' => 'False'];
        $rows = app(ArticleSearch::class)->deduplicate([$base, array_replace($base, ['id' => 's2', 'review_rating' => 'True'])]);
        $this->assertSame('retrieved', $rows[1]['retrieval_status']);
        $this->assertSame('s1', $rows[1]['possible_copy_of']);
    }

    public function test_optional_ocr_usage_is_recorded_even_when_media_origin_is_inconclusive(): void
    {
        $this->mock(SubmissionExtractor::class)->shouldReceive('extract')->once()->andReturn([
            'status' => 'completed', 'analysis_text' => '', 'visible_text' => '',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'total_tokens' => 15],
        ]);
        $analysis = app(EvidenceVerificationService::class)->apply(['signals' => [],
            'media_assessment' => ['status' => 'completed', 'media_type' => 'image', 'label' => 'fake', 'fake_probability' => .55]], [
                'analysis_task' => 'media', 'media_type' => 'image',
            ]);
        $this->assertSame('INCONCLUSIVE', $analysis['fact_check_result']['verdict']);
        $this->assertSame(15, $analysis['signals']['openai_usage']['total_tokens']);
        $this->assertSame(15, $analysis['fact_check_result']['diagnostics']['api_usage']['total_tokens']);
        Http::assertNothingSent();
    }

    public function test_result_json_preserves_a_saved_internal_failure_status(): void
    {
        $user = User::factory()->create();
        $result = ReportAnswer::complete(['primary_task' => 'claim', 'verdict' => 'ANALYSIS_UNAVAILABLE',
            'summary' => 'The analysis service is unavailable; retry this saved report later.', 'sources' => []]);
        $detection = Detection::create(['user_id' => $user->id, 'processing_status' => 'completed',
            'source_kind' => 'text', 'media_type' => 'text', 'verdict' => 'review', 'fake_score' => 0, 'fact_check_result' => $result]);
        $this->actingAs($user)->getJson(route('detections.result', $detection))->assertOk()
            ->assertJsonPath('processing_status', 'failed')->assertJsonPath('verdict', 'ANALYSIS_UNAVAILABLE');
        Http::assertNothingSent();
    }

    public function test_synchronous_retry_saves_the_fresh_assessment_in_the_existing_report(): void
    {
        Storage::fake('public');
        config()->set(['fact_check.queue_enabled' => false, 'services.ai_origin.enabled' => true,
            'services.deepfake_cnn.enabled' => false]);
        $user = User::factory()->create();
        $path = UploadedFile::fake()->image('upload.png', 640, 640)->store('detections', 'public');
        $old = ReportAnswer::complete(MediaAnalysisReport::result(['status' => 'completed', 'media_type' => 'image',
            'label' => 'fake', 'fake_probability' => .99], []));
        $detection = Detection::create(['user_id' => $user->id, 'processing_status' => 'completed',
            'caption_text' => 'is this ai or not', 'source_kind' => 'upload', 'media_type' => 'image',
            'media_path' => $path, 'verdict' => 'review', 'fake_score' => 0, 'fact_check_result' => $old]);
        $this->mock(AiOriginClassifier::class)->shouldReceive('classify')->once()->andReturn([
            'status' => 'completed', 'media_type' => 'image', 'label' => 'fake', 'fake_probability' => .89,
        ]);
        $this->actingAs($user)->post(route('detections.retry-media', $detection), ['analysis_task' => 'media'])
            ->assertSessionHasNoErrors()->assertRedirect(route('detections.result', $detection));
        $fresh = $detection->fresh();
        $this->assertSame('INCONCLUSIVE', $fresh->fact_check_result['verdict']);
        $this->assertSame(.89, $fresh->fact_check_result['media_assessment']['fake_probability']);
        $this->assertSame('completed', $fresh->processing_status);
        $this->assertSame(1, Detection::count());
        Http::assertNothingSent();
    }

    public function test_opening_a_fresh_report_cannot_replace_research_with_a_catalogue_shortcut(): void
    {
        $user = User::factory()->create();
        $claim = 'The world will end on November 13, 2026, according to a scientist mathematical prediction.';
        $result = ReportAnswer::complete(['primary_task' => 'claim', 'claim' => $claim, 'verdict' => 'UNVERIFIED',
            'summary' => 'The fresh research did not establish this exact assertion.', 'sources' => [],
            'diagnostics' => ['pipeline_version' => EvidenceVerificationService::VERSION]]);
        $detection = Detection::create(['user_id' => $user->id, 'processing_status' => 'completed', 'caption_text' => $claim,
            'source_kind' => 'text', 'media_type' => 'text', 'verdict' => 'review', 'fake_score' => 0, 'fact_check_result' => $result]);
        $snapshot = $detection->fresh()->getRawOriginal();
        $this->actingAs($user)->getJson(route('detections.result', $detection))->assertOk()->assertJsonPath('verdict', 'UNVERIFIED');
        $this->assertSame($snapshot, $detection->fresh()->getRawOriginal());
        Http::assertNothingSent();
    }
}
