<?php

namespace Tests\Unit;

use App\Services\Detections\EvidenceSummary;
use App\Services\Detections\ReportAnswer;
use App\Services\Detections\ResultContract;
use App\Services\Detections\ResultWording;
use Tests\TestCase;

class EvidenceSummaryTest extends TestCase
{
    public function test_internal_review_narration_does_not_discard_a_valid_claim_or_citation(): void
    {
        $claim = 'The municipal library opened in 2024.';
        $result = ['primary_task' => 'claim', 'verdict' => 'TRUE', 'summary' => 'The evidence supports the claim.',
            'sources' => [['id' => 's1', 'name' => 'Municipal records', 'url' => 'https://example.gov.ph/library']],
            'claims' => [['text' => $claim, 'kind' => 'factual', 'outcome' => 'supported', 'relationships' => [[
                'source_id' => 's1', 'stance' => 'supporting', 'explanation' => 'The source_id field matches the reviewed fixture.',
            ]]]]];
        $result['answer_segments'] = EvidenceSummary::segments($result);
        $result['summary'] = implode(' ', array_column($result['answer_segments'], 'text'));
        $public = ResultContract::forPresentation(ReportAnswer::complete($result));
        $this->assertSame('TRUE', $public['verdict']);
        $this->assertStringContainsString($claim, $public['summary']);
        $this->assertSame(['s1'], $public['answer_segments'][0]['source_ids']);
        $this->assertStringNotContainsString('source_id', $public['summary']);
        $this->assertStringNotContainsString('fixture', $public['summary']);
    }

    public function test_a_partially_established_report_leads_with_the_sourced_finding_and_names_the_unresolved_assertion(): void
    {
        $result = ['verdict' => 'UNVERIFIED', 'primary_task' => 'claim',
            'summary' => 'The reviewed evidence does not establish every factual claim. Missing evidence is not evidence that a claim is false.',
            'sources' => [['id' => 's1', 'name' => 'Municipal records']], 'claims' => [
                ['text' => 'The library opened in 2024.', 'kind' => 'factual', 'outcome' => 'supported', 'relationships' => [[
                    'source_id' => 's1', 'stance' => 'supporting', 'explanation' => 'The library opened on May 1, 2024.', 'superseded' => false,
                ]]],
                ['text' => 'The library has 40 reading rooms.', 'kind' => 'factual', 'outcome' => 'insufficient', 'relationships' => []],
            ]];
        $segments = EvidenceSummary::segments($result);
        $this->assertStringContainsString('May 1, 2024', $segments[0]['text']);
        $this->assertSame(['s1'], $segments[0]['source_ids']);
        $this->assertStringContainsString('40 reading rooms', $segments[1]['text']);
        $this->assertSame([], $segments[1]['source_ids']);
        $worded = ResultWording::ensure($result);
        $this->assertSame('UNVERIFIED', $worded['verdict']);
        $this->assertSame($result['sources'], $worded['sources']);
        $this->assertStringContainsString('May 1, 2024', $worded['generated_wording']['explanation']);
        $this->assertStringContainsString('40 reading rooms', $worded['generated_wording']['explanation']);
        $this->assertStringNotContainsString('every factual claim', $worded['generated_wording']['explanation']);
    }
}
