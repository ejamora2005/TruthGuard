<?php

namespace Tests\Feature;

use App\Models\Detection;
use App\Models\User;
use App\Services\Detections\GoogleFactCheckFeedService;
use App\Services\Detections\RecentFactCheckService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class FactCheckWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_recent_fact_checks_are_user_scoped_newest_first_and_limited_to_three(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        foreach (range(1, 4) as $index) {
            $this->createDetection($user, [
                'caption_text' => "User claim {$index}",
                'analyzed_at' => now()->subMinutes($index),
            ]);
        }

        $this->createDetection($otherUser, [
            'caption_text' => 'Another user private claim',
            'analyzed_at' => now(),
        ]);

        $items = app(RecentFactCheckService::class)->forUser($user->id);

        $this->assertCount(3, $items);
        $this->assertSame(
            ['User claim 1', 'User claim 2', 'User claim 3'],
            $items->pluck('claim')->all(),
        );
        $this->assertNotContains('Another user private claim', $items->pluck('claim'));
    }

    public function test_recent_fact_check_cards_present_status_type_and_sources(): void
    {
        $user = User::factory()->create();

        $this->createDetection($user, [
            'caption_text' => 'Verified source-backed link',
            'source_kind' => 'link',
            'source_url' => 'https://example.com/verified-claim',
            'media_type' => 'unknown',
            'fake_score' => 8,
            'verdict' => 'real',
            'verification_sources' => [
                ['name' => 'Source one', 'url' => 'https://example.com/one'],
                ['name' => 'Source two', 'url' => 'https://example.com/two'],
            ],
        ]);

        $item = app(RecentFactCheckService::class)->forUser($user->id)->first();

        $this->assertSame('Verified', $item['status']);
        $this->assertSame('verified', $item['status_key']);
        $this->assertSame('Link', $item['input_type']);
        $this->assertSame(2, $item['sources_count']);
        $this->assertSame(route('detections.result', $item['id']), $item['url']);
    }

    public function test_workspace_shows_public_claim_reviews_and_dashboard_navigation_on_first_render(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $service = Mockery::mock(GoogleFactCheckFeedService::class);
        $service->shouldReceive('latest')->once()->with(3)->andReturn([
            'configured' => true,
            'items' => [
                [
                    'id' => 'public-review-1',
                    'publisher' => 'Rappler',
                    'headline' => 'Fact Check: Public claim headline',
                    'claim' => 'Reviewed claim summary from the public claim-review feed.',
                    'claimant' => 'Online claim',
                    'rating' => 'False',
                    'tone' => 'danger',
                    'url' => 'https://example.com/review',
                    'host' => 'example.com',
                    'source_domain' => 'example.com',
                    'logo_url' => 'https://example.com/logo.png',
                    'image_url' => 'https://example.com/image.png',
                    'date_label' => 'Aug 05, 2026',
                    'timestamp' => now()->timestamp,
                ],
            ],
        ]);
        $this->app->instance(GoogleFactCheckFeedService::class, $service);

        $component = Volt::test('detections.ai-composer')
            ->assertSee(route('dashboard'), false)
            ->assertSee('truthguard-mobile-capabilities', false)
            ->assertSee('Service status')
            ->assertSee('Supported evidence')
            ->assertSee('TruthGuard can make mistakes. Verify important details before sharing.')
            ->assertSet('recentFactChecksLoaded', true)
            ->assertSet('recentFactChecksError', null)
            ->assertSee('Fact Check: Public claim headline')
            ->assertSee('Reviewed claim summary from the public claim-review feed.')
            ->assertSee('Check claim');

        $this->assertCount(1, $component->get('recentFactChecks'));
    }

    public function test_workspace_recent_fact_checks_has_empty_and_error_states(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $service = Mockery::mock(GoogleFactCheckFeedService::class);
        $service->shouldReceive('latest')->once()->with(3)->andReturn([
            'configured' => true,
            'items' => [],
        ]);
        $this->app->instance(GoogleFactCheckFeedService::class, $service);

        Volt::test('detections.ai-composer')
            ->assertSee('No public claim reviews yet')
            ->assertSee('Open latest feed');

        $service = Mockery::mock(GoogleFactCheckFeedService::class);
        $service->shouldReceive('latest')->once()->with(3)->andThrow(new RuntimeException('Feed unavailable'));
        $this->app->instance(GoogleFactCheckFeedService::class, $service);

        Volt::test('detections.ai-composer')
            ->assertSet('recentFactChecksError', 'Unable to load public claim reviews.')
            ->assertSee('Unable to load public claim reviews.')
            ->assertSee('Try again');
    }

    public function test_claim_limit_is_shared_with_server_validation(): void
    {
        config()->set('truthguard.claims.max_characters', 12);
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from(route('detections.create'))
            ->post(route('detections.store'), [
                'caption_text' => 'This claim is longer than twelve characters.',
            ]);

        $response
            ->assertRedirect(route('detections.create'))
            ->assertSessionHasErrors('caption_text');

        $this->assertDatabaseCount('detections', 0);
    }

    public function test_advanced_result_report_renders_a_clear_ai_analysis_hierarchy(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $detection = $this->createDetection($user, [
            'caption_text' => 'A claim submitted for verification.',
            'analysis_summary' => 'The available evidence conflicts with the submitted claim.',
            'explanation_summary' => 'The strongest public source directly contradicts the central claim.',
            'verification_summary' => 'One trusted public reference was compared.',
            'signals' => [
                'textual' => [
                    ['label' => 'Direct source contradiction', 'weight' => 82],
                ],
            ],
            'verification_sources' => [
                [
                    'name' => 'Example Fact Check',
                    'source_type' => 'fact_check',
                    'rating' => 'False',
                    'summary' => 'Independent review of the claim.',
                    'url' => 'https://example.com/fact-check',
                ],
                [
                    'name' => 'Facebook',
                    'title' => 'A public post repeating the submitted claim',
                    'source_type' => 'social_context',
                    'status' => 'OpenAI web-search match',
                    'summary' => 'This post repeats the claim and includes a link to a correction.',
                    'url' => 'https://facebook.com/public/posts/123',
                ],
            ],
        ]);
        $detection->setRelation('scrapedPosts', collect());

        $html = view('detections.partials.advanced-result-card', [
            'selectedDetection' => $detection,
            'latestFactChecks' => ['items' => [
                [
                    'publisher' => 'Example Publisher',
                    'headline' => 'Related public fact-check with an image',
                    'rating' => 'False',
                    'url' => 'https://example.com/related-check',
                    'image_url' => 'https://example.com/related-image.jpg',
                    'date_label' => 'Today',
                ],
            ]],
        ])->render();

        $this->assertStringContainsString('AI verification report', $html);
        $this->assertStringContainsString('Executive summary', $html);
        $this->assertStringContainsString('Recommended action', $html);
        $this->assertStringContainsString('Decision factors', $html);
        $this->assertStringContainsString('Source verification', $html);
        $this->assertStringContainsString('Example Fact Check', $html);
        $this->assertStringContainsString('Cross-platform social context (1)', $html);
        $this->assertStringContainsString('View related content', $html);
        $this->assertStringContainsString('Related public fact-check with an image', $html);
        $this->assertStringContainsString('height: 160px', $html);
        $this->assertStringNotContainsString('<details', $html);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createDetection(User $user, array $overrides = []): Detection
    {
        return Detection::query()->create(array_merge([
            'user_id' => $user->id,
            'source_kind' => 'upload',
            'platform' => 'web',
            'media_type' => 'image',
            'caption_text' => 'Fact check record',
            'fake_score' => 82,
            'processing_status' => 'completed',
            'preprocessing_summary' => 'Preprocessed successfully.',
            'analysis_summary' => 'Analysis completed.',
            'verification_summary' => 'Sources reviewed.',
            'explanation_summary' => 'The evidence was reviewed.',
            'recommendation' => 'Review before sharing.',
            'signals' => [],
            'verification_sources' => [],
            'verdict' => 'fake',
            'notes' => null,
            'analyzed_at' => now(),
        ], $overrides));
    }
}
