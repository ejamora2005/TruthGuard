<?php

namespace Tests\Feature;

use App\Services\Detections\GoogleFactCheckFeedService;
use Mockery\MockInterface;
use Tests\TestCase;

class PublicClaimReviewsTest extends TestCase
{
    private function mockReviews(): void
    {
        $items = collect(range(1, 15))->map(fn ($index) => [
            'id' => 'review-'.$index,
            'headline' => 'Public evidence '.$index,
            'claim' => 'A public claim to review.',
            'publisher' => $index === 15 ? 'Second Publisher' : 'First Publisher',
            'rating' => 'False',
            'url' => 'https://example.com/review/'.$index,
            'date_label' => 'Sep 9, 2026',
        ])->all();
        $this->mock(GoogleFactCheckFeedService::class, function (MockInterface $mock) use ($items) {
            $mock->shouldReceive('latest')->with(500, 7)->andReturn(['items' => $items]);
        });
    }

    public function test_guests_can_browse_and_paginate_public_reviews(): void
    {
        $this->mockReviews();
        $this->get('/claim-reviews')->assertOk()->assertSee('Public evidence 1')
            ->assertDontSee('Public evidence 15')->assertSee('Page 1 of 2')
            ->assertSee(route('home').'#features')->assertSee(route('home').'#pricing')
            ->assertSee('Toggle menu')->assertSee('All rights reserved.');
        $this->get('/claim-reviews?page=2')->assertOk()->assertSee('Public evidence 15');
    }

    public function test_search_and_source_filters_work_together(): void
    {
        $this->mockReviews();
        $this->get('/claim-reviews?'.http_build_query(['source' => 'Second Publisher', 'search' => 'evidence']))
            ->assertOk()->assertSee('Public evidence 15')->assertDontSee('Public evidence 14');
        $this->get('/claim-reviews?search=nomatch')->assertOk()->assertSee('No reviews found');
    }

    public function test_welcome_links_to_reviews_without_an_inline_feed(): void
    {
        $this->mockReviews();
        $this->get('/')->assertOk()->assertDontSee('Public evidence 1')->assertSee(route('reviews.index'))
            ->assertSee("\u{20B1}49")->assertSee("\u{20B1}99")->assertSee('not yet available for purchase')
            ->assertDontSee('Sarah Johnson')->assertDontSee('SLA guarantees')->assertDontSee('$29');
    }
}
