<?php

namespace Tests\Unit;

use App\Models\PublicClaimReview;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PublicClaimReviewTest extends TestCase
{
    public function test_full_source_fields_survive_persistence_without_inferred_rating_fallback(): void
    {
        $review = new PublicClaimReview([
            'headline' => 'Truncated title', 'rating' => 'False',
            'source_payload' => [
                'full_headline' => 'Full publisher title',
                'full_claim' => 'Full claim',
                'source_rating' => null,
                'original_url' => 'https://facebook.com/public/posts/123',
            ],
        ]);
        $item = $review->toFeedItem();
        $this->assertSame('Full publisher title', $item['full_headline']);
        $this->assertSame('Full claim', $item['full_claim']);
        $this->assertNull($item['source_rating']);
        $this->assertSame('https://facebook.com/public/posts/123', $item['original_url']);
    }

    public function test_public_claim_review_exports_dashboard_feed_item_shape(): void
    {
        $review = new PublicClaimReview([
            'feed_item_id' => 'saved-review-001',
            'publisher' => 'Trusted Fact Check',
            'headline' => 'Fact Check: Persistent public review',
            'claim' => 'A saved public claim review should remain visible after refresh.',
            'claimant' => 'Online claim',
            'rating' => 'Miscaptioned',
            'tone' => 'danger',
            'source_domain' => 'example.com',
            'url' => 'https://example.com/fact-check/persistent-public-review',
            'published_at' => Carbon::parse('2026-07-28 12:00:00'),
        ]);

        $item = $review->toFeedItem();

        $this->assertSame('saved-review-001', $item['id']);
        $this->assertSame('Trusted Fact Check', $item['publisher']);
        $this->assertSame('Miscaptioned', $item['rating']);
        $this->assertSame('example.com', $item['source_domain']);
        $this->assertSame('Jul 28, 2026', $item['date_label']);
        $this->assertTrue($item['saved']);
    }
}
