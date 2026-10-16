<?php

namespace Tests\Unit;

use App\Services\Detections\FactCheckRatingNormalizer;
use App\Services\Detections\OpenAiDetectionReportService;
use ReflectionClass;
use Tests\TestCase;

class OpenAiDetectionReportServiceTest extends TestCase
{
    public function test_voice_response_is_unverifiable_without_an_approved_source(): void
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
        $this->assertStringStartsWith('Verdict: Unverifiable', $resolved['explanation_summary']);
        $this->assertStringContainsString('not enough to confirm or disprove', $resolved['explanation_summary']);
        $this->assertStringContainsString('Sources: No approved sources supplied.', $resolved['explanation_summary']);
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

    public function test_voice_response_uses_the_required_format_and_only_approved_sources(): void
    {
        $resolved = $this->mergeReport([
            'verdict' => 'real',
            'fake_score' => 8,
            'signals' => [],
            'verification_sources' => [
                [
                    'name' => 'PAGASA',
                    'source_type' => 'weather',
                    'url' => 'https://bagong.pagasa.dost.gov.ph/',
                ],
                [
                    'name' => 'AFP Fact Check',
                    'source_type' => 'fact_check',
                    'rating' => 'Confirmed',
                    'url' => 'https://factcheck.afp.com/example',
                ],
                [
                    'name' => 'Unverified post',
                    'source_type' => 'social_context',
                    'url' => 'https://example.com/post',
                ],
            ],
        ], [
            'analysis_summary' => 'Analysis completed.',
            'verification_summary' => 'Approved sources were supplied.',
            'explanation_summary' => 'Fallback explanation.',
            'recommendation' => 'Review the sources.',
            'voice_explanation' => 'The approved records support the claim. This is the relevant evidence. A third sentence is included. This fourth sentence must be removed.',
            'basis' => [],
            'limitations' => [],
        ]);

        $this->assertSame(
            "Verdict: True\n\nThe approved records support the claim. This is the relevant evidence. A third sentence is included.\n\nSources: PAGASA, AFP Fact Check.",
            $resolved['explanation_summary'],
        );
        $this->assertStringNotContainsString('Unverified post', $resolved['explanation_summary']);
    }

    public function test_report_payload_uses_only_the_application_verification_snapshot(): void
    {
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

        $this->assertArrayNotHasKey('tools', $payload);
        $this->assertArrayNotHasKey('tool_choice', $payload);
        $this->assertArrayHasKey('voice_explanation', $payload['text']['format']['schema']['properties']);
        $this->assertArrayNotHasKey('social_matches', $payload['text']['format']['schema']['properties']);
    }

    public function test_report_does_not_add_social_search_sources(): void
    {
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
        $this->assertSame('Facebook social context', $socialSources[0]['name']);
        $this->assertSame('Cross-platform social search', $socialSources[0]['status']);
        $this->assertStringNotContainsString('OpenAI web-search match', json_encode($resolved['verification_sources']));
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
