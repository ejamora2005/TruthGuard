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

    public function test_workspace_keeps_the_composer_script_inside_its_html_attribute(): void
    {
        $this->actingAs(User::factory()->create());
        $service = Mockery::mock(GoogleFactCheckFeedService::class);
        $service->shouldReceive('latest')->once()->with(3)->andReturn(['items' => []]);
        $this->app->instance(GoogleFactCheckFeedService::class, $service);

        $html = Volt::test('detections.ai-composer')->html();
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $xpath = new \DOMXPath($document);
        $shell = $xpath->query('//section[contains(@class, "truthguard-detection-shell")]')->item(0);

        $this->assertNotNull($shell);
        $this->assertTrue(
            str_contains($shell->getAttribute('x-data'), 'resizeComposer() {'),
            'The browser must receive the complete composer script in the x-data attribute.',
        );
        $this->assertSame('init(); $nextTick(() => resizeComposer())', $shell->getAttribute('x-init'));
        $this->assertStringNotContainsString('phraseLength', $shell->textContent);
        $this->assertStringNotContainsString('voiceComparableWord', $shell->textContent);
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
            'media_path' => 'detections/report-evidence.jpg',
            'analysis_summary' => 'The available evidence conflicts with the submitted claim.',
            'explanation_summary' => 'The strongest public source directly contradicts the central claim.',
            'verification_summary' => 'One trusted public reference was compared.',
            'signals' => [
                'ai_basis' => [['label' => 'Compared the submitted claim with public sources']],
                'ai_limitations' => [['label' => 'Original capture date could not be verified']],
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

        $this->assertStringContainsString('Media analysis report', $html);
        $this->assertStringContainsString('Assessment', $html);
        $this->assertStringContainsString('Submitted Evidence', $html);
        $this->assertStringContainsString('/storage/detections/report-evidence.jpg', $html);
        $this->assertStringContainsString('Enlarge submitted image', $html);
        $this->assertStringContainsString('.tg-ai-report .tg-ai-hero { order: 2; }', $html);
        $this->assertStringContainsString('.tg-ai-report .tg-report-evidence { order: 1; }', $html);
        $this->assertStringContainsString('Claim being checked', $html);
        $this->assertStringContainsString('A claim submitted for verification.', $html);
        $this->assertStringContainsString('Source result', $html);
        $this->assertStringContainsString('False', $html);
        $this->assertStringContainsString('Risk score', $html);
        $this->assertStringContainsString('Estimated misinformation or manipulation risk.', $html);
        $this->assertStringContainsString('Recommended action', $html);
        $this->assertStringContainsString('Decision factors', $html);
        $this->assertStringContainsString('Source verification', $html);
        $this->assertStringContainsString('href="#source-verification"', $html);
        $this->assertStringContainsString('id="source-verification"', $html);
        $this->assertStringContainsString('Example Fact Check', $html);
        $this->assertStringContainsString('Cross-platform social context (1)', $html);
        $this->assertStringContainsString('View related content', $html);
        $this->assertStringContainsString('Related public fact-check with an image', $html);
        $this->assertStringContainsString('height: 160px', $html);
        $this->assertStringContainsString('<details', $html);
        $this->assertStringContainsString('How TruthGuard Reached This Result', $html);
        $this->assertStringContainsString('How OpenAI helped', $html);
        $this->assertStringContainsString('No OpenAI assistance was recorded for this saved report.', $html);
        $this->assertStringContainsString('Original capture date could not be verified', $html);
    }

    public function test_result_report_handles_missing_claim_text_and_records_openai_used_state_safely(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $detection = $this->createDetection($user, [
            'caption_text' => null,
            'notes' => null,
            'signals' => [
                'openai_usage' => [
                    'provider' => 'OpenAI',
                    'model' => 'configured-model',
                    'total_tokens' => 140,
                ],
            ],
            'verification_sources' => [],
        ]);

        $html = view('detections.partials.advanced-result-card', [
            'selectedDetection' => $detection,
        ])->render();

        $this->assertStringContainsString('No clear factual claim was detected.', $html);
        $this->assertStringContainsString('Status: Used for this report', $html);
        $this->assertStringContainsString('Model: configured-model', $html);
        $this->assertStringContainsString('Token usage recorded for this report: 140', $html);
        $this->assertStringNotContainsString('No source-backed rating was recorded', $html);
    }

    public function test_result_report_does_not_call_a_social_context_only_real_result_verified(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $detection = $this->createDetection($user, [
            'verdict' => 'real',
            'fake_score' => 18,
            'analysis_summary' => 'The supplied verdict remains Likely Real.',
            'verification_summary' => 'Related public discussion was found.',
            'explanation_summary' => 'Likely Real because the image appears to reference a real controversy.',
            'recommendation' => 'Treat this as lower risk.',
            'signals' => [
                'verification_policy' => [['version' => 'test']],
            ],
            'verification_sources' => [[
                'name' => 'Facebook social context',
                'source_type' => 'social_context',
                'summary' => 'Related discussion only.',
                'url' => 'https://facebook.com/search/posts?q=claim',
            ]],
        ]);

        $html = view('detections.partials.advanced-result-card', [
            'selectedDetection' => $detection,
        ])->render();

        $this->assertStringContainsString('No official confirmation found', $html);
        $this->assertStringContainsString('No official or trusted source confirmed this claim.', $html);
        $this->assertStringContainsString('No official confirmation was found for the exact claim.', $html);
        $this->assertStringNotContainsString('Likely Real</h2>', $html);
        $this->assertStringNotContainsString('The supplied verdict remains Likely Real.', $html);
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
