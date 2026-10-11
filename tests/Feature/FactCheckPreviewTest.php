<?php
namespace Tests\Feature;

use App\Jobs\FetchFactCheckPreview;
use App\Models\PublicClaimReview;
use App\Services\Detections\GoogleFactCheckFeedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FactCheckPreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google_fact_check.key' => '', 'services.google_fact_check.feed_direct_sources_enabled' => false,
            'services.google_fact_check.feed_images_enabled' => true, 'services.google_fact_check.feed_image_limit' => 2]);
        Http::preventStrayRequests();
    }

    private function review(string $id): PublicClaimReview
    {
        return PublicClaimReview::create(['feed_item_id' => $id, 'headline' => 'Example review',
            'url' => 'https://factcheck.afp.com/'.$id, 'published_at' => now()]);
    }

    public function test_missing_previews_beyond_the_inline_limit_are_queued(): void
    {
        Queue::fake();
        foreach (range(1, 6) as $i) $this->review('review-'.$i);
        app(GoogleFactCheckFeedService::class)->latest(6);
        Queue::assertPushed(FetchFactCheckPreview::class, 6);
    }

    public function test_afp_metadata_skips_generic_logo_and_saves_article_image(): void
    {
        $review = $this->review('article');
        Http::fake(['factcheck.afp.com/*' => Http::response('<meta property="og:image" content="/logo.png"><meta property="og:image" content="https://factcheck.afp.com/sites/default/files/medias/factcheck/photo.jpg">')]);
        (new FetchFactCheckPreview($review->feed_item_id))->handle(app(GoogleFactCheckFeedService::class));
        $this->assertSame('https://factcheck.afp.com/sites/default/files/medias/factcheck/photo.jpg', $review->fresh()->image_url);
    }

    public function test_responsive_picture_and_lazy_loaded_images_are_supported(): void
    {
        foreach ([
            '<article><picture><source data-srcset="/small.webp 400w, /large.webp 1200w"><img src="/placeholder.png"></picture></article>' => '/large.webp',
            '<article><img src="/tiny.gif" data-src="/evidence.jpg"></article>' => '/evidence.jpg',
        ] as $html => $expected) {
            $review = $this->review(md5($html));
            Http::fake([$review->url => Http::response($html)]);
            app(GoogleFactCheckFeedService::class)->refreshSavedPreview($review->feed_item_id);
            $this->assertSame('https://factcheck.afp.com'.$expected, $review->fresh()->image_url);
        }
    }

    public function test_missing_images_are_cached_and_existing_images_are_preserved(): void
    {
        Queue::fake();
        $review = $this->review('unavailable');
        Http::fake(['factcheck.afp.com/*' => Http::response('', 503)]);
        $service = app(GoogleFactCheckFeedService::class);
        $service->refreshSavedPreview($review->feed_item_id);
        $service->latest(6);
        Queue::assertNothingPushed();
        $review->update(['image_url' => 'https://factcheck.afp.com/photo.jpg']);
        $service->refreshSavedPreview($review->feed_item_id);
        $this->assertSame('https://factcheck.afp.com/photo.jpg', $review->fresh()->image_url);
        Http::assertSentCount(1);
    }

    public function test_feed_refresh_does_not_erase_a_saved_article_image(): void
    {
        Queue::fake();
        config(['services.google_fact_check.feed_images_enabled' => false,
            'services.google_fact_check.feed_direct_sources_enabled' => true,
            'services.google_fact_check.feed_direct_sources' => [[
                'publisher' => 'Example', 'domain' => 'publisher.test',
                'url' => 'https://publisher.test/wp-json/wp/v2/posts',
            ]]]);
        Http::fake(['publisher.test/*' => Http::response([[
            'link' => 'https://publisher.test/review', 'date_gmt' => now()->toIso8601String(),
            'title' => ['rendered' => 'A public review'],
            'excerpt' => ['rendered' => 'Example claim'],
        ]])]);
        $service = app(GoogleFactCheckFeedService::class);
        $service->latest(5);
        $review = PublicClaimReview::firstOrFail();
        $review->update(['image_url' => 'https://publisher.test/article.jpg']);
        $service->latest(5);
        $this->assertSame('https://publisher.test/article.jpg', $review->fresh()->image_url);
    }
}
