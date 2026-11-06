<?php

namespace Tests\Unit;

use App\Services\Detections\AssessmentScore;
use App\Services\Detections\ReportAnswer;
use Tests\TestCase;

class FactCheckEditorialReviewTest extends TestCase
{
    public function test_it_builds_atomic_findings_and_does_not_count_copied_sources_as_independent(): void
    {
        $result = ReportAnswer::complete([
            'schema_version' => 4, 'primary_task' => 'claim', 'verdict' => 'FALSE',
            'claim' => 'A court approved the proposal.', 'summary' => 'The court record contradicts the claim.',
            'claims' => [[
                'id' => 'c1', 'text' => 'A court approved the proposal.', 'kind' => 'factual', 'outcome' => 'refuted',
                'relationships' => [
                    ['source_id' => 's1', 'stance' => 'contradicting', 'superseded' => false],
                    ['source_id' => 's2', 'stance' => 'contradicting', 'superseded' => false],
                ],
            ]],
            'sources' => [
                ['id' => 's1', 'url' => 'https://judiciary.gov.ph/decision', 'name' => 'Court', 'title' => 'Decision', 'used_for' => 'The petition remained pending.'],
                ['id' => 's2', 'url' => 'https://news.example.com/copy', 'name' => 'News', 'title' => 'Copied report', 'used_for' => 'Repeats the decision.', 'possible_copy_of' => 's1'],
            ],
            'answer_segments' => [['text' => 'The court record contradicts the claim.', 'source_ids' => ['s1']]],
            'score' => AssessmentScore::unavailable('No calibrated score.'), 'ui' => [],
            'assessed_at' => '2026-11-05T12:00:00Z',
        ]);

        $this->assertSame('refuted', $result['atomic_claims'][0]['outcome']);
        $this->assertCount(1, $result['evidence_families']);
        $this->assertSame('passed', $result['verdict_audit']['status']);
        $this->assertTrue($result['freshness']['time_sensitive']);
        $this->assertTrue($result['editorial_review']['required']);
    }

    public function test_verdict_audit_flags_a_true_result_with_an_unresolved_atomic_claim(): void
    {
        $result = ReportAnswer::complete([
            'schema_version' => 4, 'primary_task' => 'claim', 'verdict' => 'TRUE', 'claim' => 'A policy exists.',
            'summary' => 'The source discusses the policy.', 'claims' => [[
                'id' => 'c1', 'text' => 'A policy exists.', 'outcome' => 'insufficient', 'relationships' => [],
            ]],
            'sources' => [['id' => 's1', 'url' => 'https://example.gov.ph/policy', 'name' => 'Agency', 'title' => 'Policy', 'used_for' => 'The page discusses policy.']],
            'answer_segments' => [['text' => 'The source discusses the policy.', 'source_ids' => ['s1']]],
            'score' => AssessmentScore::unavailable('No calibrated score.'), 'ui' => [],
        ]);

        $this->assertSame('failed', $result['verdict_audit']['status']);
        $this->assertSame('pending', $result['editorial_review']['status']);
        $this->assertSame('blocked', $result['publication_gate']['status']);
        $this->assertTrue($result['quality_review']['human_review_recommended']);
    }
}
