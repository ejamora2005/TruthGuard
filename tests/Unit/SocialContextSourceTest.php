<?php

namespace Tests\Unit;

use App\Services\Detections\GoogleFactCheckFeedService;
use App\Services\Detections\LiveVerificationEvidenceService;
use App\Services\Detections\TrustedFactCheckVerdictResolver;
use ReflectionClass;
use Tests\TestCase;

class SocialContextSourceTest extends TestCase
{
    public function test_social_context_sources_search_other_platforms_before_verdict_context(): void
    {
        config()->set('playwright.social_context_enabled', true);
        config()->set('playwright.social_context_max_sources', 4);
        config()->set('playwright.social_context_sources', [
            [
                'name' => 'Facebook',
                'platform' => 'facebook',
                'url_template' => 'https://www.facebook.com/search/posts/?q={query}',
            ],
            [
                'name' => 'X',
                'platform' => 'x',
                'url_template' => 'https://x.com/search?q={query}',
            ],
            [
                'name' => 'TikTok',
                'platform' => 'tiktok',
                'url_template' => 'https://www.tiktok.com/search?q={query}',
            ],
        ]);

        $service = new LiveVerificationEvidenceService(
            new TrustedFactCheckVerdictResolver(),
            new GoogleFactCheckFeedService(),
        );

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('buildCrossPlatformSocialSources');
        $method->setAccessible(true);

        $sources = $method->invoke(
            $service,
            'Breaking flood warning in Manila. Share now before this gets deleted.',
            [
                'caption_text' => 'Breaking flood warning in Manila. Share now before this gets deleted.',
                'platform' => 'facebook',
            ],
        );

        $this->assertContains('X social context', array_column($sources, 'name'));
        $this->assertContains('TikTok social context', array_column($sources, 'name'));
        $this->assertNotContains('Facebook social context', array_column($sources, 'name'));
        $this->assertContains('social_context', array_column($sources, 'source_type'));
    }

    public function test_social_context_sources_are_skipped_without_specific_uploaded_evidence_context(): void
    {
        config()->set('playwright.social_context_enabled', true);
        config()->set('playwright.social_context_min_terms', 2);
        config()->set('playwright.social_context_sources', [
            [
                'name' => 'X',
                'platform' => 'x',
                'url_template' => 'https://x.com/search?q={query}',
            ],
        ]);

        $service = new LiveVerificationEvidenceService(
            new TrustedFactCheckVerdictResolver(),
            new GoogleFactCheckFeedService(),
        );

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('buildCrossPlatformSocialSources');
        $method->setAccessible(true);

        $sources = $method->invoke(
            $service,
            'TruthGuard reviewed the image and available source context.',
            ['platform' => 'facebook'],
        );

        $this->assertSame([], $sources);
    }
}
