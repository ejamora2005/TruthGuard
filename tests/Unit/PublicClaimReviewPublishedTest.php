<?php

namespace Tests\Unit;

use App\Models\PublicClaimReviewAnnouncement;
use App\Notifications\PublicClaimReviewPublished;
use PHPUnit\Framework\TestCase;

class PublicClaimReviewPublishedTest extends TestCase
{
    public function test_notification_payload_contains_public_claim_review_image(): void
    {
        $announcement = new PublicClaimReviewAnnouncement([
            'feed_item_id' => 'review-001',
            'publisher' => 'Rappler',
            'headline' => 'FACT CHECK: Example claim is false',
            'claim' => 'A viral public claim was reviewed by Rappler.',
            'rating' => 'False',
            'url' => 'https://www.rappler.com/newsbreak/fact-check/example',
            'image_url' => 'https://www.rappler.com/example.jpg',
        ]);
        $announcement->id = 123;

        $payload = (new PublicClaimReviewPublished($announcement))->toArray(new \stdClass());

        $this->assertSame('news', $payload['category']);
        $this->assertSame('Latest public claim review', $payload['title']);
        $this->assertSame('https://www.rappler.com/example.jpg', $payload['image_url']);
        $this->assertStringContainsString('Rappler', $payload['message']);
        $this->assertStringContainsString('False', $payload['message']);
    }

    public function test_public_claim_review_update_uses_mail_channel_only(): void
    {
        $announcement = new PublicClaimReviewAnnouncement([
            'feed_item_id' => 'review-002',
            'headline' => 'FACT CHECK: Another verified-news item',
        ]);

        $this->assertSame(['mail'], (new PublicClaimReviewPublished($announcement))->via(new \stdClass()));
    }
}
