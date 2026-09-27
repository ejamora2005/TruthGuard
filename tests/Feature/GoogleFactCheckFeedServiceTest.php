<?php

namespace Tests\Feature;

use App\Models\PublicClaimReview;
use App\Services\Detections\GoogleFactCheckFeedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleFactCheckFeedServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_direct_publisher_feed_refreshes_when_google_api_key_is_missing(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-27 08:00:00'));
        Cache::flush();

        config([
            'services.google_fact_check.key' => '',
            'services.google_fact_check.feed_cache_seconds' => 5,
            'services.google_fact_check.feed_images_enabled' => false,
            'services.google_fact_check.feed_direct_sources_enabled' => true,
            'services.google_fact_check.feed_direct_sources' => [
                [
                    'publisher' => 'Test Publisher',
                    'domain' => 'publisher.test',
                    'url' => 'https://publisher.test/wp-json/wp/v2/posts?categories=1&per_page=10',
                ],
            ],
        ]);

        PublicClaimReview::query()->create([
            'feed_item_id' => 'saved-sep-25',
            'publisher' => 'Saved Publisher',
            'headline' => 'Fact Check: Saved public review',
            'claim' => 'A saved public claim review.',
            'claimant' => 'Online claim',
            'rating' => 'False',
            'tone' => 'danger',
            'source_domain' => 'saved.test',
            'url' => 'https://saved.test/fact-check/old',
            'published_at' => Carbon::parse('2026-09-25 10:00:00'),
            'first_seen_at' => Carbon::parse('2026-09-25 10:30:00'),
            'last_seen_at' => Carbon::parse('2026-09-25 10:30:00'),
        ]);

        Http::fake([
            'https://publisher.test/wp-json/*' => Http::response([
                [
                    'link' => 'https://publisher.test/fact-check/new-review',
                    'date_gmt' => '2026-09-27T02:00:00',
                    'title' => ['rendered' => 'New public claim review'],
                    'excerpt' => ['rendered' => '<p>A new verified claim was checked.</p>'],
                    'meta' => [
                        'claim_reviewed' => 'A new verified claim was checked.',
                        'claim_author_name' => 'Online post',
                        'review_rating' => 'False',
                    ],
                ],
            ]),
            '*' => Http::response([], 404),
        ]);

        $feed = app(GoogleFactCheckFeedService::class)->latest(5, 7);

        $this->assertTrue($feed['configured']);
        $this->assertSame('Publisher feeds', $feed['source_label']);
        $this->assertTrue($feed['updated_at']->equalTo(Carbon::now()));
        $this->assertSame('Sep 27, 2026', $feed['items'][0]['date_label']);
        $this->assertSame('Fact Check: New public claim review', $feed['items'][0]['headline']);
        $this->assertDatabaseHas('public_claim_reviews', [
            'url' => 'https://publisher.test/fact-check/new-review',
        ]);
    }
}
