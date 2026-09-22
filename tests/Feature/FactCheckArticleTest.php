<?php

namespace Tests\Feature;

use App\Models\Detection;
use App\Models\User;
use App\Services\Detections\GoogleFactCheckFeedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FactCheckArticleTest extends TestCase
{
    use RefreshDatabase;

    private function feed(array $overrides = [], array $related = []): void
    {
        $this->mock(GoogleFactCheckFeedService::class, function ($mock) use ($overrides, $related) {
            $item = array_merge([
                'id' => 'article-one',
                'headline' => 'Short headline',
                'full_headline' => 'Complete publisher headline',
                'claim' => 'Short claim',
                'full_claim' => 'Complete claim supplied by the publisher.',
                'source_rating' => 'Partly accurate',
                'rating' => 'False',
                'publisher' => 'Example publisher',
                'url' => 'https://example.com/article',
            ], $overrides);

            $mock->shouldReceive('find')->with('article-one')->andReturn($item);
            $mock->shouldReceive('relatedTo')->andReturn($related);
        });
    }

    public function test_article_uses_full_text_and_supplied_rating_without_inventing_post_link(): void
    {
        $this->feed();
        $this->actingAs(User::factory()->create())->get('/dashboard/fact-checks/article-one')
            ->assertOk()->assertSee('Complete publisher headline')
            ->assertSee('Complete claim supplied by the publisher.')
            ->assertSee('Source rating: Partly accurate')->assertDontSee('Source rating: False')
            ->assertSee('Claim Sources & References')
            ->assertSee('Claim wording source')
            ->assertSee('Read Fact Check Source')->assertDontSee('View Original Post')
            ->assertDontSee('View full image')->assertDontSee('Back to Verification Report');
    }

    public function test_article_shows_related_fact_checks_in_dropdown(): void
    {
        $this->feed([], [[
            'id' => 'article-two',
            'headline' => 'Related source about the same claim wording',
            'claim' => 'A related claim summary.',
            'source_rating' => 'False',
            'publisher' => 'Rappler',
            'url' => 'https://rappler.com/newsbreak/fact-check/related-claim',
            'image_url' => 'https://example.com/related.jpg',
            'date_label' => 'Sep 22, 2026',
            'timestamp' => now()->timestamp,
        ]]);

        $this->actingAs(User::factory()->create())->get('/dashboard/fact-checks/article-one')
            ->assertOk()
            ->assertSee('Claim Sources & References')
            ->assertSee('Related source')
            ->assertSee('Related source about the same claim wording')
            ->assertSee('https://rappler.com/newsbreak/fact-check/related-claim', false);
    }

    public function test_article_links_to_original_post_when_explicit_original_url_exists(): void
    {
        $this->feed(['original_url' => 'https://facebook.com/public/posts/123']);

        $this->actingAs(User::factory()->create())->get('/dashboard/fact-checks/article-one')
            ->assertOk()
            ->assertSee('Original Post')
            ->assertSee('View Original Post')
            ->assertSee('https://facebook.com/public/posts/123')
            ->assertSee('Read Fact Check');
    }

    public function test_unsafe_article_urls_and_absent_source_ratings_are_hidden(): void
    {
        $this->feed(['url' => 'javascript:alert(1)', 'source_rating' => null]);
        $this->actingAs(User::factory()->create())->get('/dashboard/fact-checks/article-one')
            ->assertOk()->assertDontSee('javascript:')->assertDontSee('Read Original Fact Check')
            ->assertDontSee('Source rating:');
    }

    public function test_report_return_link_requires_detection_ownership(): void
    {
        $this->feed();
        $owner = User::factory()->create();
        $detection = Detection::query()->create([
            'user_id' => $owner->id, 'source_kind' => 'upload', 'media_type' => 'text',
            'fake_score' => 20, 'verdict' => 'real', 'processing_status' => 'completed',
            'analyzed_at' => now(),
        ]);
        $url = '/dashboard/fact-checks/article-one?detection='.$detection->id;
        $this->actingAs($owner)->get($url)
            ->assertOk()
            ->assertSee(route('detections.result', $detection), false)
            ->assertDontSee('Back to Verification Report');
        $this->actingAs(User::factory()->create())->get($url)->assertNotFound();
    }
}
