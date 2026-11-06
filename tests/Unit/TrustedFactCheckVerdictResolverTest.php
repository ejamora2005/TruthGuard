<?php

namespace Tests\Unit;

use App\Services\Detections\TrustedFactCheckVerdictResolver;
use PHPUnit\Framework\TestCase;

class TrustedFactCheckVerdictResolverTest extends TestCase
{
    public function test_trusted_false_rating_overrides_low_risk_analysis(): void
    {
        $resolver = new TrustedFactCheckVerdictResolver;

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
                'claim_match' => ['level' => 'exact'],
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
        $resolver = new TrustedFactCheckVerdictResolver;

        $resolved = $resolver->apply([
            'verdict' => 'real',
            'fake_score' => 18,
            'signals' => [],
        ], [], [
            [
                'name' => 'Trusted Fact Check',
                'source_type' => 'fact_check',
                'rating' => 'False',
                'claim_match' => ['level' => 'exact'],
                'summary' => 'The matched article says the image is AI-generated, misinformation.',
                'purpose' => 'Fact check article about synthetic media.',
                'url' => 'https://www.reuters.com/fact-check/example',
            ],
        ]);

        $this->assertSame('fake', $resolved['verdict']);
        $this->assertStringContainsString('False', $resolved['explanation_summary']);
        $this->assertSame('False', $resolved['signals']['trusted_fact_check'][0]['rating']);
    }

    public function test_trusted_true_rating_keeps_report_low_risk(): void
    {
        $resolver = new TrustedFactCheckVerdictResolver;

        $resolved = $resolver->apply([
            'verdict' => 'review',
            'fake_score' => 58,
            'signals' => [],
        ], [], [
            [
                'name' => 'Reuters Fact Check',
                'source_type' => 'fact_check',
                'rating' => 'True',
                'claim_match' => ['level' => 'exact'],
                'url' => 'https://www.reuters.com/fact-check/example',
            ],
        ]);

        $this->assertSame('real', $resolved['verdict']);
        $this->assertSame(8, $resolved['fake_score']);
        $this->assertStringContainsString('supports the claim', $resolved['analysis_summary']);
    }

    public function test_context_rating_stays_in_review(): void
    {
        $resolver = new TrustedFactCheckVerdictResolver;

        $resolved = $resolver->apply([
            'verdict' => 'real',
            'fake_score' => 20,
            'signals' => [],
        ], [], [
            [
                'name' => 'Vera Files',
                'source_type' => 'fact_check',
                'rating' => 'Missing context',
                'claim_match' => ['level' => 'exact'],
                'url' => 'https://verafiles.org/articles/example',
            ],
        ]);

        $this->assertSame('review', $resolved['verdict']);
        $this->assertSame(62, $resolved['fake_score']);
        $this->assertStringContainsString('needs more context', $resolved['explanation_summary']);
    }

    public function test_related_fact_check_cannot_control_the_verdict(): void
    {
        $resolver = new TrustedFactCheckVerdictResolver;
        $decision = $resolver->decide([], [[
            'name' => 'Rappler',
            'source_type' => 'fact_check',
            'rating' => 'False',
            'url' => 'https://www.rappler.com/fact-check/related-story',
            'claim_match' => ['level' => 'related'],
        ]]);

        $this->assertNull($decision);
    }

    public function test_low_risk_result_without_authoritative_confirmation_is_marked_unconfirmed(): void
    {
        $resolver = new TrustedFactCheckVerdictResolver;

        $resolved = $resolver->apply([
            'verdict' => 'real',
            'fake_score' => 18,
            'signals' => [
                'verification_policy' => [['version' => TrustedFactCheckVerdictResolver::POLICY_VERSION]],
            ],
        ], [], [
            [
                'name' => 'Facebook social context',
                'source_type' => 'social_context',
                'url' => 'https://facebook.com/search/posts?q=claim',
            ],
        ]);

        $this->assertSame('review', $resolved['verdict']);
        $this->assertSame(50, $resolved['fake_score']);
        $this->assertStringContainsString('No official confirmation', $resolved['analysis_summary']);
        $this->assertStringContainsString('true or false', $resolved['explanation_summary']);
        $this->assertSame(
            'No official confirmation found',
            $resolved['signals']['verification_status'][0]['rating'],
        );
    }

    public function test_conflicting_exact_reviews_cannot_be_resolved_by_false_rating_priority(): void
    {
        $source = ['name' => 'Rappler', 'source_type' => 'fact_check', 'claim_match' => ['level' => 'exact'],
            'url' => 'https://rappler.com/review', 'rating' => 'False'];
        $other = array_replace($source, ['name' => 'AFP', 'url' => 'https://factcheck.afp.com/review', 'rating' => 'True']);
        $result = (new TrustedFactCheckVerdictResolver)->apply(['verdict' => 'real', 'fake_score' => 8], [], [$source, $other]);
        $this->assertSame('review', $result['verdict']);
        $this->assertStringContainsString('Human review', $result['analysis_summary']);
    }

    public function test_unmatched_or_spoofed_publisher_sources_cannot_control_a_verdict(): void
    {
        $source = ['name' => 'Rappler', 'source_type' => 'fact_check', 'rating' => 'False', 'url' => 'https://rappler.com/review'];
        $resolver = new TrustedFactCheckVerdictResolver;
        $this->assertNull($resolver->decide([], [$source]));
        $source['claim_match'] = ['level' => 'exact'];
        $source['url'] = 'https://rappler.com.attacker.test/review';
        $this->assertNull($resolver->decide([], [$source]));
    }
}
