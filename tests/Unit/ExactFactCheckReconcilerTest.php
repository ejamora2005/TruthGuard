<?php

namespace Tests\Unit;

use App\Services\Detections\ExactFactCheckReconciler;
use App\Services\Detections\FactCheckClaimMatcher;
use PHPUnit\Framework\TestCase;

class ExactFactCheckReconcilerTest extends TestCase
{
    public function test_exact_false_review_replaces_a_generic_missing_context_result(): void
    {
        $result = (new ExactFactCheckReconciler(new FactCheckClaimMatcher))->apply([
            'claim' => 'GMA SARADO NA?! HATOL SA KORTE?!',
            'verdict' => 'MISSING_CONTEXT',
            'assessment_label' => 'Missing Context',
            'summary' => 'Important details are missing.',
            'claims' => [[
                'id' => 'c1', 'text' => 'GMA SARADO NA?! HATOL SA KORTE?!', 'outcome' => 'needs_context',
                'relationships' => [],
            ]],
            'sources' => [],
            'ui' => ['show_missing_context' => true, 'missing_context' => 'Clarify GMA.'],
        ], [[
            'id' => 's1', 'source_type' => 'fact_check', 'name' => 'Rappler',
            'url' => 'https://www.rappler.com/newsbreak/fact-check/no-court-order-gma-network-shutdown/',
            'title' => 'No court order for GMA Network shutdown',
            'reviewed_claim' => 'GMA Network will be shut down due to a court order',
            'review_rating' => 'False', 'published_at' => '2026-11-05T00:00:00Z',
        ]]);

        $this->assertSame('FALSE', $result['verdict']);
        $this->assertSame('False', $result['assessment_label']);
        $this->assertFalse($result['ui']['show_missing_context']);
        $this->assertStringContainsString('Rappler', $result['summary']);
        $this->assertStringContainsString('False', $result['summary']);
        $this->assertSame('refuted', $result['claims'][0]['outcome']);
        $this->assertSame('exact', $result['exact_fact_check']['claim_match']['level']);
    }

    public function test_related_review_does_not_replace_the_result(): void
    {
        $original = ['claim' => 'GMA Network shutdown', 'verdict' => 'UNVERIFIED', 'sources' => []];
        $result = (new ExactFactCheckReconciler(new FactCheckClaimMatcher))->apply($original, [[
            'id' => 's1', 'source_type' => 'fact_check', 'name' => 'Rappler',
            'url' => 'https://example.com/earthquake', 'title' => 'Earthquake in Cebu',
            'reviewed_claim' => 'An earthquake struck Cebu', 'review_rating' => 'False',
        ]]);

        $this->assertSame($original, $result);
    }

    public function test_conflicting_exact_ratings_do_not_force_a_verdict(): void
    {
        $original = ['claim' => 'GMA Network shutdown court ruling', 'verdict' => 'UNVERIFIED', 'sources' => []];
        $records = [
            ['id' => 's1', 'source_type' => 'fact_check', 'name' => 'A', 'url' => 'https://a.test/check',
                'reviewed_claim' => 'GMA Network shutdown court ruling', 'review_rating' => 'False'],
            ['id' => 's2', 'source_type' => 'fact_check', 'name' => 'B', 'url' => 'https://b.test/check',
                'reviewed_claim' => 'GMA Network shutdown court ruling', 'review_rating' => 'Confirmed'],
        ];

        $result = (new ExactFactCheckReconciler(new FactCheckClaimMatcher))->apply($original, $records);

        $this->assertSame($original, $result);
    }
}
