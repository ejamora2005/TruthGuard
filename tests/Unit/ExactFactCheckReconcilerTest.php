<?php

namespace Tests\Unit;

use App\Services\Detections\ExactFactCheckReconciler;
use App\Services\Detections\FactCheckClaimMatcher;
use App\Services\Detections\FactCheckEvidencePolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ExactFactCheckReconcilerTest extends TestCase
{
    public function test_exact_false_review_replaces_a_generic_missing_context_result(): void
    {
        $result = (new ExactFactCheckReconciler(new FactCheckClaimMatcher, new FactCheckEvidencePolicy))->apply([
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
            'retrieval_status' => 'retrieved', 'text' => 'There is no court order shutting down GMA Network. The viral claim is false.',
        ]]);

        $this->assertSame('FALSE', $result['verdict']);
        $this->assertSame('False', $result['assessment_label']);
        $this->assertFalse($result['ui']['show_missing_context']);
        $this->assertStringContainsString('Rappler', $result['summary']);
        $this->assertStringContainsString('False', $result['summary']);
        $this->assertSame('refuted', $result['claims'][0]['outcome']);
        $this->assertSame('exact', $result['exact_fact_check']['claim_match']['level']);
        $this->assertStringContainsString('There is no court order', $result['sources'][0]['excerpt']);
    }

    public function test_related_review_does_not_replace_the_result(): void
    {
        $original = ['claim' => 'GMA Network shutdown', 'verdict' => 'UNVERIFIED', 'sources' => []];
        $result = (new ExactFactCheckReconciler(new FactCheckClaimMatcher, new FactCheckEvidencePolicy))->apply($original, [[
            'id' => 's1', 'source_type' => 'fact_check', 'name' => 'Rappler',
            'url' => 'https://example.com/earthquake', 'title' => 'Earthquake in Cebu',
            'reviewed_claim' => 'An earthquake struck Cebu', 'review_rating' => 'False',
        ]]);

        $this->assertSame($original, $result);
    }

    public function test_conflicting_exact_ratings_do_not_force_a_verdict(): void
    {
        $original = ['claim' => 'GMA Network shutdown court ruling', 'verdict' => 'TRUE', 'sources' => [],
            'claims' => [['id' => 'c1', 'outcome' => 'supported']], 'generated_wording' => ['verdict' => 'TRUE']];
        $records = [
            ['id' => 's1', 'source_type' => 'fact_check', 'name' => 'Rappler', 'url' => 'https://rappler.com/check',
                'retrieval_status' => 'retrieved', 'text' => 'The court ruling did not shut down GMA Network.',
                'reviewed_claim' => 'GMA Network shutdown court ruling', 'review_rating' => 'False'],
            ['id' => 's2', 'source_type' => 'fact_check', 'name' => 'AFP', 'url' => 'https://factcheck.afp.com/check',
                'retrieval_status' => 'retrieved', 'text' => 'The court ruling did shut down GMA Network.',
                'reviewed_claim' => 'GMA Network shutdown court ruling', 'review_rating' => 'Confirmed'],
        ];

        $result = (new ExactFactCheckReconciler(new FactCheckClaimMatcher, new FactCheckEvidencePolicy))->apply($original, $records);

        $this->assertSame('CONFLICTING_EVIDENCE', $result['verdict']);
        $this->assertSame('conflicting', $result['claims'][0]['outcome']);
        $this->assertTrue($result['ui']['show_source_conflict']);
        $this->assertCount(2, $result['sources']);
        $this->assertArrayNotHasKey('generated_wording', $result);
    }

    #[DataProvider('inadmissibleReviews')]
    public function test_unread_or_materially_different_reviews_cannot_decide(array $changes): void
    {
        $original = ['claim' => 'Suan filed a resolution against Bato', 'verdict' => 'UNVERIFIED', 'sources' => []];
        $record = array_replace(['id' => 's1', 'name' => 'Rappler', 'source_type' => 'fact_check',
            'url' => 'https://rappler.com/fact-check/test', 'retrieval_status' => 'retrieved',
            'reviewed_claim' => $original['claim'], 'review_rating' => 'False', 'text' => 'The reviewed resolution claim was false.'], $changes);

        $this->assertSame($original, app(ExactFactCheckReconciler::class)->apply($original, [$record]));
    }

    public static function inadmissibleReviews(): array
    {
        return [
            [['retrieval_status' => 'http_403']], [['retrieval_status' => 'after_cutoff']], [['text' => '']],
            [['url' => 'https://rappler.com.attacker.test/review']],
            [['final_url' => 'https://unapproved.test/review']],
            [['reviewed_claim' => 'Suan did not file a resolution against Bato']],
            [['reviewed_claim' => 'Suan filed a resolution against Duterte', 'title' => 'Suan filed a resolution against Bato']],
        ];
    }

    public function test_published_rating_is_not_reclassified_from_claim_words(): void
    {
        $claim = 'The photo is not AI-generated';
        $result = app(ExactFactCheckReconciler::class)->apply(['claim' => $claim, 'verdict' => 'UNVERIFIED'], [[
            'id' => 's1', 'name' => 'Rappler', 'source_type' => 'fact_check', 'url' => 'https://rappler.com/review',
            'retrieval_status' => 'retrieved', 'text' => 'The publisher confirmed this exact claim.',
            'reviewed_claim' => $claim, 'review_rating' => 'True',
        ]]);
        $this->assertSame('TRUE', $result['verdict']);
    }

    public function test_a_review_replaces_its_existing_source_card_and_cannot_decide_multiple_claims(): void
    {
        $claim = 'Suan filed a resolution against Bato';
        $original = ['claim' => $claim, 'verdict' => 'TRUE', 'claims' => [['id' => 'c1', 'text' => $claim]],
            'sources' => [['id' => 's1', 'name' => 'Rappler', 'used_for' => 'An earlier model explanation.']]];
        $record = ['id' => 's1', 'name' => 'Rappler', 'source_type' => 'fact_check', 'url' => 'https://rappler.com/review',
            'retrieval_status' => 'retrieved', 'text' => 'The publisher found this resolution claim false.',
            'reviewed_claim' => $claim, 'review_rating' => 'False'];
        $result = app(ExactFactCheckReconciler::class)->apply($original, [$record]);
        $this->assertSame('FALSE', $result['verdict']);
        $this->assertCount(1, $result['sources']);
        $this->assertSame('False', $result['sources'][0]['published_rating']);
        $this->assertStringNotContainsString('earlier model', $result['sources'][0]['used_for']);

        $original['claims'][] = ['id' => 'c2', 'text' => 'Another factual assertion'];
        $this->assertSame($original, app(ExactFactCheckReconciler::class)->apply($original, [$record]));
    }
}
