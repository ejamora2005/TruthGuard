<?php

namespace Tests\Feature;

use App\Models\ClaimVariation;
use App\Models\ReviewedClaim;
use App\Services\Detections\ReviewedClaimLookup;
use App\Services\Detections\EvidenceVerificationService;
use App\Services\Detections\Evidence\SubmissionExtractor;
use App\Services\Detections\Evidence\ArticleSearch;
use Illuminate\Support\Facades\Http;
use Database\Seeders\ReviewedClaimSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewedClaimLookupTest extends TestCase
{
    use RefreshDatabase;

    public function test_pipeline_uses_reviewed_text_before_extraction_or_research(): void
    {
        $this->seed(ReviewedClaimSeeder::class);
        $this->mock(SubmissionExtractor::class)->shouldNotReceive('extract');
        $this->mock(ArticleSearch::class)->shouldNotReceive('retrieve');
        $analysis = app(EvidenceVerificationService::class)->apply(['signals' => []], [
            'caption_text' => 'Hev Abi cursed at Marcos over his bucket advice.',
            'analysis_task' => 'claim', 'media_type' => 'text',
        ]);
        $this->assertSame('FALSE', $analysis['fact_check_result']['verdict']);
        $this->assertSame('exact_normalized_variation', $analysis['fact_check_result']['diagnostics']['match_method']);
        Http::assertNothingSent();
    }

    public function test_reviewed_answers_are_seeded_idempotently_and_match_approved_variations(): void
    {
        $this->seed(ReviewedClaimSeeder::class);
        $count = ClaimVariation::count();
        $this->seed(ReviewedClaimSeeder::class);
        $this->assertSame(7, ReviewedClaim::count());
        $this->assertSame($count, ClaimVariation::count());
        $result = ReviewedClaimLookup::lookup('  HEV ABI cursed at Marcos over his bucket advice!!! ');
        $this->assertSame('FALSE', $result['verdict']);
        $this->assertSame('exact_normalized_variation', $result['diagnostics']['match_method']);
        $this->assertSame(0, $result['diagnostics']['api_usage']['total_tokens']);
        $this->assertNotEmpty($result['sources']);
        $this->assertSame('MISLEADING', ReviewedClaimLookup::lookup('Matatapos ang mundo sa November 13, 2026.')['verdict']);
        $suan = ReviewedClaimLookup::lookup('Lordan Suan threatened a shoot-to-kill resolution against Bato dela Rosa.');
        $this->assertSame('FALSE', $suan['verdict']);
        $this->assertCount(6, $suan['sources']);
        $this->assertSame('VERA Files', $suan['sources'][0]['name']);
        $this->assertSame(0, $suan['diagnostics']['api_usage']['total_tokens']);
        $headline = ReviewedClaimLookup::lookup("SHOOT-TO-KILL\nKAY SEN. BATO,\nIHAHAIN?!");
        $this->assertSame('FALSE', $headline['verdict']);
        $this->assertSame('suan-fabricated-bato-shoot-to-kill-quote', $headline['diagnostics']['reviewed_claim_slug']);
        $postHeadline = ReviewedClaimLookup::lookup('Posts on Rep. Suan’s ‘shoot-to-kill’ resolution vs Bato dela Rosa');
        $this->assertSame('FALSE', $postHeadline['verdict']);
        $this->assertSame(0, $postHeadline['diagnostics']['api_usage']['total_tokens']);
        $this->assertCount(6, $postHeadline['sources']);
        $this->assertNull(ReviewedClaimLookup::lookup('Bato'));
        $this->assertNull(ReviewedClaimLookup::lookup('Lordan Suan did not threaten a shoot-to-kill resolution against Bato dela Rosa.'));
    }

    public function test_different_dates_negations_questions_and_partial_claims_do_not_reuse_answers(): void
    {
        $this->seed(ReviewedClaimSeeder::class);
        foreach (['Hev Abi', 'Hev Abi did not curse at Marcos over his bucket advice.',
            'The world will end on November 13, 2027.',
            'When was the November 13 2026 scientist paper about the end of the world published?',
            'Marcos announced plans to cancel the 2029 elections.',
            'Hev Abi cursed at Marcos over his bucket advice. Also classes are cancelled today.'] as $text) {
            $this->assertNull(ReviewedClaimLookup::lookup($text), $text);
        }
    }

    public function test_disabled_and_expired_reviews_are_not_reused(): void
    {
        $this->seed(ReviewedClaimSeeder::class);
        $claim = ReviewedClaim::where('slug', 'hev-abi-fake-bucket-quote')->firstOrFail();
        $claim->update(['active' => false]);
        $this->assertNull(ReviewedClaimLookup::lookup($claim->claim));
        $claim->update(['active' => true, 'expires_at' => now()->subSecond()]);
        $this->assertNull(ReviewedClaimLookup::lookup($claim->claim));
    }

    public function test_reviewed_science_image_variant_reuses_db_answer_without_search(): void
    {
        $this->seed(ReviewedClaimSeeder::class);
        $text = 'BIG BREAKING: World will end in Two weeks. World will end on Friday 13 November 2026: According to an old study published in journal Science.';
        // Also cover existing deployed DBs before the new variation is seeded.
        ClaimVariation::where('text', $text)->delete();
        $this->mock(SubmissionExtractor::class)->shouldReceive('extract')->once()->andReturn([
            'analysis_text' => $text, 'status' => 'completed', 'input_scope' => 'image text',
            'usage' => ['total_tokens' => 123, 'input_tokens' => 100, 'output_tokens' => 23],
        ]);
        $this->mock(ArticleSearch::class)->shouldNotReceive('retrieve');
        $analysis = app(EvidenceVerificationService::class)->apply(['signals' => []], [
            'media_type' => 'image', 'analysis_task' => 'claim',
        ]);
        $this->assertSame('MISLEADING', $analysis['fact_check_result']['verdict']);
        $this->assertSame('approved_november_13_variant', $analysis['fact_check_result']['diagnostics']['match_method']);
        $this->assertSame(123, $analysis['fact_check_result']['diagnostics']['api_usage']['total_tokens']);
        $this->assertCount(5, $analysis['fact_check_result']['sources']);
        $this->assertNull(ReviewedClaimLookup::lookup(str_replace('2026', '2027', $text)));
        $this->assertNull(ReviewedClaimLookup::lookup($text.' Also classes are cancelled tomorrow.'));
        ReviewedClaim::where('slug', 'november-13-2026-doomsday')->update(['active' => false]);
        $this->assertNull(ReviewedClaimLookup::lookup($text));
        Http::assertNothingSent();
    }
}
