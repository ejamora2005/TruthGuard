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
        $this->assertSame(6, ReviewedClaim::count());
        $this->assertSame($count, ClaimVariation::count());
        $result = ReviewedClaimLookup::lookup('  HEV ABI cursed at Marcos over his bucket advice!!! ');
        $this->assertSame('FALSE', $result['verdict']);
        $this->assertSame('exact_normalized_variation', $result['diagnostics']['match_method']);
        $this->assertSame(0, $result['diagnostics']['api_usage']['total_tokens']);
        $this->assertNotEmpty($result['sources']);
        $this->assertSame('MISLEADING', ReviewedClaimLookup::lookup('Matatapos ang mundo sa November 13, 2026.')['verdict']);
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
}
