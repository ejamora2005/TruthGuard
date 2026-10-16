<?php

namespace Tests\Unit;

use App\Services\Detections\AssessmentScore;
use App\Services\Detections\Evidence\EvidenceJudge;
use App\Services\Detections\ReportAnswer;
use Tests\TestCase;

class AssessmentScoreTest extends TestCase
{
    private function media(float $raw = .83, string $label = 'fake'): array
    {
        return ['status' => 'completed', 'media_type' => 'image', 'fake_probability' => $raw, 'label' => $label,
            'label_mapping' => ['real', 'fake'], 'output_contract' => ['activation' => 'sigmoid', 'positive_class' => 'fake', 'binary_exclusive' => true]];
    }

    public function test_binary_class_direction_and_display_rounding_preserve_raw_values(): void
    {
        $score = AssessmentScore::media($this->media(.8349));
        $this->assertSame(.8349, $score['value']);
        $this->assertSame('Model score: 83%', AssessmentScore::display($score));
        $score = AssessmentScore::media($this->media(.1649, 'real'));
        $this->assertEqualsWithDelta(.8351, $score['value'], 1e-12);
        $this->assertSame('Model score: 84%', AssessmentScore::display($score));
        $this->assertSame('Model score: >99%', AssessmentScore::display(AssessmentScore::media($this->media(.99999))));
        $this->assertSame('Model score: <1%', AssessmentScore::display(AssessmentScore::media($this->media(.001))));
    }

    public function test_invalid_missing_logit_reversed_or_video_outputs_cannot_gain_a_percentage(): void
    {
        foreach ([[], ['status' => 'unavailable'], ['fake_probability' => -1], ['fake_probability' => 9], ['fake_probability' => NAN],
            ['label_mapping' => ['fake', 'real']], ['output_contract' => ['activation' => 'linear']], ['media_type' => 'video']] as $override) {
            $score = AssessmentScore::media($override === [] ? [] : array_replace($this->media(), $override));
            $this->assertNull($score['value']);
            $this->assertSame('Score unavailable', AssessmentScore::display($score));
        }
        $this->assertSame('Score unavailable', AssessmentScore::display(['type' => 'confidence', 'value' => .9, 'label' => 'Confidence', 'validation' => 'uncalibrated']));
    }

    public function test_evidence_scores_support_assigned_false_verdict_and_copy_count_does_not_raise_score(): void
    {
        $text = 'The municipality did not issue the notice attributed to it.';
        $row = ['claim_id' => 'c1', 'source_id' => 's1', 'stance' => 'contradicting', 'excerpt' => $text, 'basis' => 'direct_reporting',
            'matches' => array_fill_keys(['event', 'entities', 'action', 'date', 'location', 'quantities', 'qualifiers'], true)];
        $result = ['verdict' => 'FALSE', 'claims' => [['id' => 'c1', 'kind' => 'factual', 'outcome' => 'refuted', 'relationships' => [$row]]]];
        $record = ['id' => 's1', 'text' => $text, 'url' => 'https://reuters.com/a', 'source_type' => 'reputable_news', 'published_at' => null, 'retrieval_status' => 'retrieved'];
        $score = AssessmentScore::evidence($result, [$record]);
        $this->assertSame('FALSE', $score['assigned_verdict']);
        $this->assertEqualsWithDelta(.77, $score['value'], 1e-9);
        $result['claims'][0]['relationships'][] = array_replace($row, ['source_id' => 's2']);
        $copied = array_replace($record, ['id' => 's2', 'url' => 'https://apnews.com/copy', 'possible_copy_of' => 's1']);
        $this->assertSame($score['value'], AssessmentScore::evidence($result, [$record, $copied])['value']);
        $this->assertNull(AssessmentScore::evidence(array_replace($result, ['verdict' => 'UNVERIFIED']), [$record])['value']);
        $this->assertNull(AssessmentScore::evidence(array_replace($result, ['verdict' => 'CONFLICTING_EVIDENCE']), [$record])['value']);
    }

    public function test_context_needs_exact_passages_and_explicit_expiry_not_just_old_publication(): void
    {
        $judge = app(EvidenceJudge::class);
        $claim = ['id' => 'c1', 'text' => 'The warning is in effect today.'];
        $source = ['id' => 's1', 'url' => 'https://pagasa.dost.gov.ph/fixture-bulletin', 'text' => 'The warning is valid until October 12, 2026.', 'published_at' => '2026-10-11'];
        $finding = ['claim_id' => 'c1', 'source_id' => 's1', 'label' => 'OUTDATED', 'excerpt' => $source['text'],
            'submitted_quote' => $claim['text'], 'exact_subject_match' => true, 'presented_as_current' => true, 'valid_until' => 'October 12, 2026', 'explanation' => 'The quoted warning has expired.'];
        $this->assertCount(1, $judge->validateContext([$finding], [$claim], [$source], '2026-10-13T01:00:00Z'));
        $this->assertSame([], $judge->validateContext([$finding], [$claim], [$source], '2026-10-12T12:00:00Z'));
        $this->assertSame([], $judge->validateContext([array_replace($finding, ['valid_until' => null])], [$claim], [$source], '2026-10-13T01:00:00Z'));
        $this->assertSame([], $judge->validateContext([array_replace($finding, ['excerpt' => 'An invented expiry date establishes nothing.'])], [$claim], [$source], '2026-10-13T01:00:00Z'));
        $finding = array_replace($finding, ['label' => 'OUT_OF_CONTEXT', 'valid_until' => null]);
        $this->assertSame([], $judge->validateContext([array_replace($finding, ['exact_subject_match' => false])], [$claim], [$source], '2026-10-13T01:00:00Z'));
    }

    public function test_source_urls_reject_executable_and_private_links(): void
    {
        foreach (['javascript:alert(1)', 'data:text/html,x', 'https://user:password@example.com/a', 'http://127.0.0.1/a', 'http://localhost/a', 'https://example.com/ bad'] as $url) {
            $this->assertNull(ReportAnswer::safeUrl($url));
        }
        $this->assertSame('https://phivolcs.dost.gov.ph/record', ReportAnswer::safeUrl('https://phivolcs.dost.gov.ph/record'));
    }
}
