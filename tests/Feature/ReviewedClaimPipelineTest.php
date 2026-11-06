<?php

namespace Tests\Feature;

use App\Models\ReviewedClaim;
use App\Services\Detections\Evidence\ArticleSearch;
use App\Services\Detections\Evidence\SubmissionExtractor;
use App\Services\Detections\Evidence\SubmittedText;
use App\Services\Detections\EvidenceVerificationService;
use App\Services\Detections\ReviewedClaimLookup;
use App\Services\Detections\SubmissionTask;
use Database\Seeders\ReviewedClaimSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReviewedClaimPipelineTest extends TestCase
{
    use RefreshDatabase;

    private const CLAIM = 'Lordan Suan threatened a shoot-to-kill resolution against Bato dela Rosa.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 6)->startOfDay());
        config()->set(['services.openai.analysis_enabled' => true, 'services.openai.key' => 'fixture',
            'services.live_verification.enabled' => true, 'services.filipino_news_classifier.enabled' => false,
            'fact_check.web_search.enabled' => false, 'fact_check.retrieval.max_rounds' => 1]);
        Http::preventStrayRequests();
        $this->seed(ReviewedClaimSeeder::class);
    }

    private function row(string $text): array
    {
        return ['quote' => $text, 'kind' => 'factual', 'requires_context' => false, 'context_reason' => '',
            'entities' => [], 'dates' => [], 'locations' => [], 'quantities' => [], 'qualifiers' => [],
            'searches' => ['relevant event evidence'], 'references' => []];
    }

    private function runPoster(string $text, array $quotes, array $request = []): array
    {
        $this->mock(SubmissionExtractor::class)->shouldReceive('extract')->once()->andReturn([
            'status' => 'completed', 'analysis_text' => $text, 'visible_text' => $text,
            'original_text' => $text, 'reconstructed_text' => $text, 'input_scope' => 'visible image text',
            'limitations' => [], 'source_record' => null,
            'usage' => ['input_tokens' => 90, 'output_tokens' => 10, 'total_tokens' => 100],
        ]);
        // Use the real planner, database lookup, aggregation and report formatter.
        // Only the external OCR/planning responses are fixed test inputs.
        Http::fake(['api.openai.com/*' => function ($request) use ($quotes) {
            $this->assertSame('claim_decomposition', $request['text']['format']['name']);

            return Http::response(['status' => 'completed',
                'usage' => ['input_tokens' => 180, 'output_tokens' => 20, 'total_tokens' => 200],
                'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode([
                    'claims' => array_map($this->row(...), $quotes), 'date_quote' => '', 'original_claim_date' => null,
                ])]]]]]);
        }]);

        return app(EvidenceVerificationService::class)->apply(['signals' => []], array_replace([
            'media_type' => 'image', 'analysis_task' => 'claim', 'caption_text' => SubmissionTask::CLAIM_INSTRUCTION,
        ], $request))['fact_check_result'];
    }

    public function test_a_saved_answer_is_used_for_a_complete_assertion_inside_ocr_without_research(): void
    {
        $text = "November 2, 2026\n\n".self::CLAIM."\n\nVERA FILES";
        $this->assertNull(ReviewedClaimLookup::lookup($text)); // The old whole-text path misses it.
        $search = $this->mock(ArticleSearch::class);
        $search->shouldReceive('prefetchExactReviews')->once()->andReturn([]);
        $search->shouldNotReceive('retrieve');
        $result = $this->runPoster($text, [self::CLAIM]);
        $this->assertSame('FALSE', $result['verdict']);
        $this->assertSame('refuted', $result['claims'][0]['outcome']);
        $this->assertSame('suan-fabricated-bato-shoot-to-kill-quote', $result['diagnostics']['reviewed_answers']['c1']['reviewed_claim_slug']);
        $this->assertSame('reviewed_database', $result['diagnostics']['candidate_records'][0]['retrieval_status']);
        $this->assertSame('', $result['diagnostics']['candidate_records'][0]['text']);
        $this->assertStringContainsString('fabricated', strtolower($result['summary']));
        $this->assertNotEmpty($result['sources']);
        $this->assertSame($text, $result['extraction']['analysis_text']);
        $this->assertSame(300, $result['diagnostics']['api_usage']['total_tokens']);
        Http::assertSentCount(1);
    }

    public function test_a_saved_false_claim_does_not_decide_an_additional_unresolved_assertion(): void
    {
        $other = 'Orchid school won an international award in 2026.';
        $search = $this->mock(ArticleSearch::class);
        $search->shouldReceive('prefetchExactReviews')->andReturn([]);
        $search->shouldReceive('retrieve')->once()
            ->andReturnUsing(fn ($queries, $budget, $cutoff, $records) => $records);
        $result = $this->runPoster(self::CLAIM."\n\n".$other, [self::CLAIM, $other]);
        $this->assertSame('UNVERIFIED', $result['verdict']);
        $this->assertSame(['refuted', 'insufficient'], array_column($result['claims'], 'outcome'));
        $this->assertNotEmpty($result['sources']);
        $this->assertStringContainsString('Orchid', $result['summary']);
        Http::assertSentCount(1);
    }

    public function test_force_refresh_keeps_research_enabled_for_a_previously_reviewed_assertion(): void
    {
        $search = $this->mock(ArticleSearch::class);
        $search->shouldNotReceive('prefetchExactReviews');
        $search->shouldReceive('retrieve')->once()->andReturn([]);
        $result = $this->runPoster("VERA FILES\n\n".self::CLAIM, [self::CLAIM], ['force_refresh' => true]);
        $this->assertSame([], $result['diagnostics']['reviewed_answers']);
        $this->assertSame('UNVERIFIED', $result['verdict']);
        Http::assertSentCount(1);
    }

    public static function changedScope(): array
    {
        return [
            ['Lordan Suan did not threaten a shoot-to-kill resolution against Bato dela Rosa.'],
            [self::CLAIM.' Only if a court approved it.'],
            [str_replace('Lordan Suan', 'Another legislator', self::CLAIM)],
            [self::CLAIM.' in 2027'],
        ];
    }

    #[DataProvider('changedScope')]
    public function test_other_wording_cannot_inherit_a_saved_rating(string $text): void
    {
        $claim = ['id' => 'c1', 'text' => $text, 'kind' => 'factual', 'requires_context' => false];
        $this->assertSame([], ReviewedClaimLookup::forClaims([$claim], $text, now()->toIso8601String()));
        $claim['text'] = self::CLAIM; // Nor can the model crop a longer sentence to force a hit.
        if (! in_array(self::CLAIM, SubmittedText::sentences($text), true)) {
            $this->assertSame([], ReviewedClaimLookup::forClaims([$claim], $text, now()->toIso8601String()));
        }
    }

    public function test_expiry_disabling_and_a_historical_cutoff_still_prevent_reuse(): void
    {
        $claim = ['id' => 'c1', 'text' => self::CLAIM, 'kind' => 'factual', 'requires_context' => false];
        $this->assertSame([], ReviewedClaimLookup::forClaims([$claim], self::CLAIM, '2026-11-01T00:00:00Z'));
        $record = ReviewedClaim::where('slug', 'suan-fabricated-bato-shoot-to-kill-quote')->firstOrFail();
        $record->update(['active' => false]);
        $this->assertSame([], ReviewedClaimLookup::forClaims([$claim], self::CLAIM, now()->toIso8601String()));
        $record->update(['active' => true, 'expires_at' => now()->subSecond()]);
        $this->assertSame([], ReviewedClaimLookup::forClaims([$claim], self::CLAIM, now()->toIso8601String()));
    }

    public function test_the_actual_wrapped_poster_headline_can_match_a_complete_region_with_surrounding_text(): void
    {
        $headline = "SHOOT-TO-KILL\nKAY SEN. BATO,\nIHAHAIN?!";
        $text = "November 2, 2026\n\n".$headline."\n\nVERA FILES";
        $claim = ['id' => 'c1', 'text' => $headline, 'kind' => 'factual', 'requires_context' => false];
        $answers = ReviewedClaimLookup::forClaims([$claim], $text, now()->toIso8601String());
        $this->assertSame('FALSE', $answers['c1']['verdict']);
        $this->assertSame('suan-fabricated-bato-shoot-to-kill-quote', $answers['c1']['diagnostics']['reviewed_claim_slug']);
        $search = $this->mock(ArticleSearch::class);
        $search->shouldReceive('prefetchExactReviews')->once()->andReturn([]);
        $search->shouldNotReceive('retrieve');
        $result = $this->runPoster($text, [SubmittedText::paragraphs($headline)]);
        $this->assertSame('FALSE', $result['verdict'], json_encode($result['claims']));
        $this->assertSame('refuted', $result['claims'][0]['outcome']);
        Http::assertSentCount(1);
        $claim['dates'] = ['2027'];
        $this->assertSame([], ReviewedClaimLookup::forClaims([$claim], $text."\n\n2027", now()->toIso8601String()));
        unset($claim['dates']);
        $claim['references'] = [['phrase' => 'he', 'referent' => 'a different legislator']];
        $this->assertSame([], ReviewedClaimLookup::forClaims([$claim], $text, now()->toIso8601String()));
    }

    public function test_a_reviewer_owned_variation_is_not_reassigned_when_installing_missing_defaults(): void
    {
        $this->seed(ReviewedClaimSeeder::class);
        $suan = ReviewedClaim::where('slug', 'suan-fabricated-bato-shoot-to-kill-quote')->firstOrFail();
        $suan->delete();
        $other = ReviewedClaim::where('slug', 'hev-abi-fake-bucket-quote')->firstOrFail();
        $variation = $other->variations()->create(['text' => self::CLAIM]);
        $this->seed(ReviewedClaimSeeder::class);
        $this->assertSame($other->id, $variation->fresh()->reviewed_claim_id);
        $this->assertSame(7, ReviewedClaim::count());
    }

    public function test_deployment_installs_defaults_without_overwriting_existing_review_decisions(): void
    {
        $record = ReviewedClaim::where('slug', 'suan-fabricated-bato-shoot-to-kill-quote')->firstOrFail();
        $record->update(['active' => false, 'result' => ['verdict' => 'UNVERIFIED', 'summary' => 'Needs further review.'],
            'expires_at' => '2026-11-02 00:00:00']);
        $record->variations()->delete();
        $before = $record->fresh()->getAttributes();
        $this->seed(ReviewedClaimSeeder::class);
        $this->assertSame($before, $record->fresh()->getAttributes());
        $this->assertSame(0, $record->variations()->count());
        $entrypoint = file_get_contents(base_path('docker/entrypoint.sh'));
        $this->assertStringContainsString('php artisan db:seed --class=ReviewedClaimSeeder --force', $entrypoint);
    }
}
