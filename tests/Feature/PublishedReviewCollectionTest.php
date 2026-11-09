<?php

namespace Tests\Feature;

use App\Models\PublicClaimReview;
use App\Models\ReviewedClaim;
use App\Services\Detections\Evidence\ArticleSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublishedReviewCollectionTest extends TestCase
{
    use RefreshDatabase;

    private function page(string $url): array
    {
        return ['url' => $url, 'title' => 'Synthetic fact-check of a vehicle allegation', 'status' => 'retrieved',
            'text' => 'The published fact-check distinguishes the mini fire vehicles from a different procurement.',
            'published_at' => '2026-10-08T00:00:00Z', 'updated_at' => null, 'retrieved_at' => now()->toIso8601String(),
            'http_status' => 200, 'content_hash' => 'fixture-content-hash',
            'claim_reviews' => [['reviewed_claim' => 'The DILG procured mini fire trucks worth 10 million pesos each.',
                'review_rating' => 'False']]];
    }

    public function test_retrieved_publisher_metadata_is_collected_once_without_approving_a_saved_answer(): void
    {
        $search = app(ArticleSearch::class);
        $page = $this->page('https://www.rappler.com/newsbreak/fact-check/synthetic-vehicle-claim/');
        $search->reviewedSubmission($page, now()->toIso8601String());
        $search->reviewedSubmission($page, now()->toIso8601String());
        $this->assertSame(1, PublicClaimReview::count());
        $this->assertSame(0, ReviewedClaim::count());
        $saved = PublicClaimReview::firstOrFail();
        $this->assertSame('False', $saved->source_payload['source_rating']);
        $this->assertSame('publisher_claim_review', $saved->source_payload['label_status']);
        $this->assertSame('retrieved_article_metadata', $saved->source_payload['collection_method']);
        $this->assertSame('fixture-content-hash', $saved->source_payload['content_hash']);

        PublicClaimReview::create(['feed_item_id' => 'unrelated-same-amount', 'claim' => 'At least three posts say a reward of 10 million pesos was offered for the president arrest.',
            'headline' => 'An unrelated reward claim', 'publisher' => 'Another publisher',
            'url' => 'https://example.com/fact-check/reward', 'published_at' => '2026-10-08T00:00:00Z']);
        $rows = $search->collectedReviews([['text' => '10 million pesos bawat isa?',
            'discovery_context' => 'Small red three-wheeled fire-response vehicles.']], now()->toIso8601String());
        $this->assertCount(1, $rows);
        $this->assertSame('collected_review', reset($rows)['provider']);
        $this->assertSame([], $search->collectedReviews([['text' => 'How much did each pictured vehicle actually cost?']], now()->toIso8601String()));
    }

    public function test_an_unfamiliar_publisher_is_collectible_but_does_not_gain_trusted_fact_check_status(): void
    {
        $record = app(ArticleSearch::class)->reviewedSubmission($this->page('https://independent-publisher.example/fact-check/vehicles'), now()->toIso8601String());
        $this->assertSame(1, PublicClaimReview::count());
        $this->assertSame('unapproved', $record['source_type']);
        $this->assertSame(0, ReviewedClaim::count());
    }

    public function test_unreadable_and_incomplete_metadata_never_create_published_reviews(): void
    {
        $search = app(ArticleSearch::class);
        $page = $this->page('https://www.rappler.com/newsbreak/fact-check/synthetic-unreadable/');
        $page['status'] = 'restricted';
        $search->reviewedSubmission($page, now()->toIso8601String());
        $page['status'] = 'retrieved';
        $page['claim_reviews'][0]['review_rating'] = '';
        $search->reviewedSubmission($page, now()->toIso8601String());
        $this->assertSame(0, PublicClaimReview::count());
    }
}
