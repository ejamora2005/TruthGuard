<?php

namespace Tests\Unit;

use App\Services\Detections\FactCheckRatingNormalizer;
use App\Services\Detections\OpenAiDetectionReportService;
use ReflectionClass;
use Tests\TestCase;

class OpenAiDetectionReportServiceTest extends TestCase
{
    public function test_ai_generated_media_finding_overrides_low_risk_verdict(): void
    {
        $resolved = $this->mergeReport([
            'verdict' => 'real',
            'fake_score' => 18,
            'signals' => [
                'visual' => [],
                'content_extraction' => [
                    [
                        'label' => 'OpenAI read visible claim text from the image: This image is AI generated, not a real photograph.',
                        'weight' => 42,
                    ],
                ],
            ],
            'verification_sources' => [
                [
                    'name' => 'Google Fact Check Explorer',
                    'source_type' => 'reference',
                    'url' => 'https://toolbox.google.com/factcheck/explorer',
                ],
            ],
        ], [
            'analysis_summary' => 'The uploaded image text states it is AI generated.',
            'verification_summary' => 'No matched trusted source rating was supplied.',
            'explanation_summary' => 'The image says it is AI generated, not a real photograph.',
            'recommendation' => 'Do not present the image as an authentic photograph.',
            'basis' => [
                'The image text states the image is AI generated, not a real photograph.',
            ],
            'limitations' => [],
        ]);

        $this->assertSame('fake', $resolved['verdict']);
        $this->assertSame(94, $resolved['fake_score']);
        $this->assertStringContainsString('not be treated as a real photograph', $resolved['explanation_summary']);
        $this->assertStringContainsString('AI-generated media', $resolved['explanation_summary']);
        $this->assertStringNotContainsString('Likely Fake', $resolved['explanation_summary']);
        $this->assertSame(
            FactCheckRatingNormalizer::AI_GENERATED_MISINFORMATION,
            $resolved['signals']['ai_media_assessment'][0]['rating'],
        );
    }

    public function test_source_backed_fact_check_rating_is_not_overridden_by_synthetic_wording(): void
    {
        $resolved = $this->mergeReport([
            'verdict' => 'real',
            'fake_score' => 8,
            'signals' => [
                'trusted_fact_check' => [
                    [
                        'label' => 'Matched fact-check source rated this claim Confirmed.',
                        'rating' => 'Confirmed',
                        'weight' => 88,
                    ],
                ],
            ],
            'verification_sources' => [
                [
                    'name' => 'Trusted Fact Check',
                    'source_type' => 'fact_check',
                    'rating' => 'Confirmed',
                    'url' => 'https://example.com/fact-check',
                ],
            ],
        ], [
            'analysis_summary' => 'The source discusses synthetic media context.',
            'verification_summary' => 'A trusted source rating was supplied.',
            'explanation_summary' => 'The report preserves the source-backed verdict.',
            'recommendation' => 'Review the linked source.',
            'basis' => [
                'The article discusses synthetic media context.',
            ],
            'limitations' => [],
        ]);

        $this->assertSame('real', $resolved['verdict']);
        $this->assertSame(8, $resolved['fake_score']);
        $this->assertArrayNotHasKey('ai_media_assessment', $resolved['signals']);
    }

    public function test_report_payload_requires_grounded_social_web_search(): void
    {
        config()->set('services.openai.social_search_enabled', true);
        config()->set('services.openai.social_search_context_size', 'medium');
        config()->set('services.openai.social_search_domains', ['facebook.com', 'x.com', 'tiktok.com']);

        $service = new OpenAiDetectionReportService();
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('payload');
        $method->setAccessible(true);

        $payload = $method->invoke($service, [
            'verdict' => 'review',
            'fake_score' => 50,
            'signals' => [],
            'verification_sources' => [],
        ], [
            'caption_text' => 'A public claim shared on social media.',
            'media_type' => 'unknown',
            'platform' => 'web',
        ], null);

        $this->assertSame('web_search', $payload['tools'][0]['type']);
        $this->assertSame(['facebook.com', 'x.com', 'tiktok.com'], $payload['tools'][0]['filters']['allowed_domains']);
        $this->assertSame('required', $payload['tool_choice']);
        $this->assertSame(['web_search_call.action.sources'], $payload['include']);
        $this->assertArrayHasKey('social_matches', $payload['text']['format']['schema']['properties']);
    }

    public function test_grounded_social_search_sources_are_added_to_the_report(): void
    {
        config()->set('services.openai.social_search_domains', ['facebook.com', 'x.com']);

        $resolved = $this->mergeReport([
            'verdict' => 'review',
            'fake_score' => 50,
            'signals' => [],
            'verification_sources' => [
                [
                    'name' => 'Facebook social context',
                    'status' => 'Cross-platform social search',
                    'source_type' => 'social_context',
                    'url' => 'https://facebook.com/search/posts/?q=claim',
                ],
            ],
        ], [
            'analysis_summary' => 'Related public posts were checked.',
            'verification_summary' => 'A related Facebook post was found.',
            'explanation_summary' => 'The post provides context but is not proof by itself.',
            'recommendation' => 'Review the original post.',
            'basis' => [],
            'limitations' => [],
            'social_matches' => [
                [
                    'title' => 'Public post discussing the same claim',
                    'platform' => 'Facebook',
                    'url' => 'https://www.facebook.com/public-page/posts/123?tracking=1',
                    'summary' => 'The post repeats the claim and links to a later correction.',
                ],
            ],
        ], [
            'output' => [
                [
                    'type' => 'web_search_call',
                    'action' => [
                        'sources' => [
                            [
                                'title' => 'Public post discussing the same claim',
                                'url' => 'https://www.facebook.com/public-page/posts/123',
                            ],
                            [
                                'title' => 'Untrusted invented result',
                                'url' => 'https://example.com/not-social',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $socialSources = collect($resolved['verification_sources'])->where('source_type', 'social_context')->values();

        $this->assertCount(1, $socialSources);
        $this->assertSame('Facebook', $socialSources[0]['name']);
        $this->assertSame('OpenAI web-search match', $socialSources[0]['status']);
        $this->assertStringContainsString('later correction', $socialSources[0]['summary']);
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function mergeReport(array $analysis, array $report, array $responseData = []): array
    {
        $service = new OpenAiDetectionReportService();
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('mergeReport');
        $method->setAccessible(true);

        return $method->invoke($service, $analysis, $report, array_replace_recursive([
            'usage' => [
                'input_tokens' => 100,
                'output_tokens' => 40,
                'total_tokens' => 140,
            ],
        ], $responseData));
    }
}
