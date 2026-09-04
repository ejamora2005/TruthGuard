<?php

namespace Tests\Unit;

use App\Services\Detections\TrustedFactCheckVerdictResolver;
use PHPUnit\Framework\TestCase;

class TrustedFactCheckVerdictResolverTest extends TestCase
{
    public function test_trusted_false_rating_overrides_low_risk_analysis(): void
    {
        $resolver = new TrustedFactCheckVerdictResolver();

        $analysis = [
            'verdict' => 'real',
            'fake_score' => 18,
            'signals' => [
                'visual' => [
                    ['label' => 'Image quality appears normal', 'weight' => 12],
                ],
            ],
        ];

        $resolved = $resolver->apply($analysis, [], [
            [
                'name' => 'Rappler',
                'source_type' => 'fact_check',
                'rating' => 'False',
                'summary' => 'Google Fact Check found a related claim review rated False.',
                'purpose' => 'Fact check article about the matching claim.',
                'url' => 'https://www.rappler.com/newsbreak/fact-check/example',
            ],
        ]);

        $this->assertSame('fake', $resolved['verdict']);
        $this->assertSame(94, $resolved['fake_score']);
        $this->assertStringContainsString('Based on the source', $resolved['explanation_summary']);
        $this->assertStringContainsString('not true', $resolved['explanation_summary']);
        $this->assertSame(TrustedFactCheckVerdictResolver::POLICY_VERSION, $resolved['signals']['verification_policy'][0]['version']);
    }

    public function test_ai_generated_misinformation_label_is_preserved(): void
    {
        $resolver = new TrustedFactCheckVerdictResolver();

        $resolved = $resolver->apply([
            'verdict' => 'real',
            'fake_score' => 18,
            'signals' => [],
        ], [], [
            [
                'name' => 'Trusted Fact Check',
                'source_type' => 'fact_check',
                'rating' => 'False',
                'summary' => 'The matched article says the image is AI-generated, misinformation.',
                'purpose' => 'Fact check article about synthetic media.',
                'url' => 'https://www.reuters.com/fact-check/example',
            ],
        ]);

        $this->assertSame('fake', $resolved['verdict']);
        $this->assertStringContainsString('AI-generated, misinformation', $resolved['explanation_summary']);
        $this->assertSame('AI-generated, misinformation', $resolved['signals']['trusted_fact_check'][0]['rating']);
    }

    public function test_trusted_true_rating_keeps_report_low_risk(): void
    {
        $resolver = new TrustedFactCheckVerdictResolver();

        $resolved = $resolver->apply([
            'verdict' => 'review',
            'fake_score' => 58,
            'signals' => [],
        ], [], [
            [
                'name' => 'Reuters Fact Check',
                'source_type' => 'fact_check',
                'rating' => 'True',
                'url' => 'https://www.reuters.com/fact-check/example',
            ],
        ]);

        $this->assertSame('real', $resolved['verdict']);
        $this->assertSame(8, $resolved['fake_score']);
        $this->assertStringContainsString('supports the claim', $resolved['analysis_summary']);
    }

    public function test_context_rating_stays_in_review(): void
    {
        $resolver = new TrustedFactCheckVerdictResolver();

        $resolved = $resolver->apply([
            'verdict' => 'real',
            'fake_score' => 20,
            'signals' => [],
        ], [], [
            [
                'name' => 'Vera Files',
                'source_type' => 'fact_check',
                'rating' => 'Missing context',
                'url' => 'https://verafiles.org/articles/example',
            ],
        ]);

        $this->assertSame('review', $resolved['verdict']);
        $this->assertSame(62, $resolved['fake_score']);
        $this->assertStringContainsString('needs more context', $resolved['explanation_summary']);
    }
}
