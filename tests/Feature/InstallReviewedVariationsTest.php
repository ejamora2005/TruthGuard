<?php

namespace Tests\Feature;

use App\Models\ClaimVariation;
use App\Models\ReviewedClaim;
use App\Services\Detections\ReviewedClaimLookup;
use Database\Seeders\ReviewedClaimSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InstallReviewedVariationsTest extends TestCase
{
    use RefreshDatabase;

    private const SLUG = 'suan-fabricated-bato-shoot-to-kill-quote';

    private const HEADLINE = 'SHOOT-TO-KILL KAY SEN, BATO, IHAHAIN?!';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 6)->startOfDay());
        Http::preventStrayRequests();
        $this->seed(ReviewedClaimSeeder::class);
    }

    private function legacyReview(): ReviewedClaim
    {
        $review = ReviewedClaim::where('slug', self::SLUG)->firstOrFail();
        $review->variations()->whereIn('text_hash', array_map(fn ($text) => hash('sha256', ReviewedClaimLookup::normalize($text)), [
            self::HEADLINE, 'Posts on Rep. Suan’s ‘shoot-to-kill’ resolution vs Bato dela Rosa',
            'Posts on Rep. Suan’s ‘shoot-to-kill’ resolution vs Bato dela Rosa FAKE',
        ]))->delete();

        return $review;
    }

    public function test_explicit_install_repairs_the_production_variation_gap_without_overwriting_the_review(): void
    {
        $review = $this->legacyReview();
        $this->assertSame(5, $review->variations()->count());
        $before = $review->fresh()->getRawOriginal();
        $this->assertNull(ReviewedClaimLookup::lookup(self::HEADLINE));
        $this->artisan('truthguard:install-reviewed-variations', ['slug' => self::SLUG])
            ->expectsOutputToContain('3 missing approved variations')->assertSuccessful();
        $this->assertSame(5, $review->variations()->count());
        // Ordinary deployment seeding deliberately preserves reviewer deletions.
        $this->seed(ReviewedClaimSeeder::class);
        $this->assertSame(5, $review->variations()->count());
        $this->artisan('truthguard:install-reviewed-variations', ['slug' => self::SLUG, '--apply' => true])
            ->expectsOutputToContain('3 approved variations installed')->assertSuccessful();
        $this->assertSame(8, $review->variations()->count());
        $this->assertSame('FALSE', ReviewedClaimLookup::lookup(self::HEADLINE)['verdict']);
        $this->assertSame($before, $review->fresh()->getRawOriginal());
        $this->artisan('truthguard:install-reviewed-variations', ['slug' => self::SLUG, '--apply' => true])
            ->expectsOutputToContain('0 approved variations installed')->assertSuccessful();
        Http::assertNothingSent();
    }

    public static function changedReviews(): array
    {
        return ['disabled' => [['active' => false]], 'expired' => [['expires_at' => '2026-11-05']],
            'changed proposition' => [['claim' => 'A different person made a different statement.']],
            'changed verdict' => [['result' => ['verdict' => 'UNVERIFIED', 'sources' => [['url' => 'https://example.com']]]]],
            'missing sources' => [['result' => ['verdict' => 'FALSE', 'sources' => []]]]];
    }

    #[DataProvider('changedReviews')]
    public function test_install_does_not_override_review_lifecycle_or_verdict_changes(array $changes): void
    {
        $review = $this->legacyReview();
        $review->update($changes);
        $before = $review->fresh()->getRawOriginal();
        $this->artisan('truthguard:install-reviewed-variations', ['slug' => self::SLUG, '--apply' => true])->assertFailed();
        $this->assertSame(5, $review->variations()->count());
        $this->assertSame($before, $review->fresh()->getRawOriginal());
        Http::assertNothingSent();
    }

    public function test_a_variation_owned_by_another_review_aborts_the_entire_install(): void
    {
        $review = $this->legacyReview();
        $other = ReviewedClaim::where('slug', 'hev-abi-fake-bucket-quote')->firstOrFail();
        $variation = $other->variations()->create(['text' => 'Posts on Rep. Suan’s ‘shoot-to-kill’ resolution vs Bato dela Rosa FAKE']);
        $before = ClaimVariation::count();
        $this->artisan('truthguard:install-reviewed-variations', ['slug' => self::SLUG, '--apply' => true])
            ->expectsOutputToContain('belongs to another review')->assertFailed();
        $this->assertSame($before, ClaimVariation::count());
        $this->assertSame(5, $review->variations()->count());
        $this->assertSame($other->id, $variation->fresh()->reviewed_claim_id);
        $this->assertNull(ReviewedClaimLookup::lookup(self::HEADLINE));
    }

    public function test_unknown_or_missing_reviews_are_not_created_by_the_variation_installer(): void
    {
        $before = ReviewedClaim::count();
        $this->artisan('truthguard:install-reviewed-variations', ['slug' => 'unknown', '--apply' => true])->assertFailed();
        $this->assertSame($before, ReviewedClaim::count());
        ReviewedClaim::where('slug', self::SLUG)->delete();
        $this->artisan('truthguard:install-reviewed-variations', ['slug' => self::SLUG, '--apply' => true])->assertFailed();
        $this->assertSame($before - 1, ReviewedClaim::count());
    }
}
