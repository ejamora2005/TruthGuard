<?php

namespace Tests\Unit;

use App\Services\Detections\AssessmentScore;
use App\Services\Detections\MediaAnalysisReport;
use App\Services\Detections\ReportAnswer;
use Tests\TestCase;

class AssessmentScoreBadgeTest extends TestCase
{
    private function media(float $positive, bool $inconclusive = false): array
    {
        config()->set('services.media_calibration.enabled', false);

        return ReportAnswer::complete(MediaAnalysisReport::result([
            'status' => 'completed', 'media_type' => 'image', 'label' => $positive > .5 ? 'fake' : 'real',
            'fake_probability' => $positive, 'threshold' => .5, 'label_mapping' => ['real', 'fake'],
            'origin_policy' => ['inconclusive' => $inconclusive],
            'output_contract' => ['activation' => 'sigmoid', 'positive_class' => 'fake', 'binary_exclusive' => true],
        ], ['status' => 'not_requested']));
    }

    public function test_completed_image_displays_its_recorded_class_percentage_without_calling_it_confidence(): void
    {
        foreach ([[.97, 'Model score: 97%'], [.07, 'Model score: 93%']] as [$value, $title]) {
            $badge = AssessmentScore::badge($this->media($value));
            $this->assertSame($title, $badge['title']);
            $this->assertSame('Uncalibrated model output', $badge['description']);
            $this->assertTrue($badge['has_percentage']);
        }
    }

    public function test_inconclusive_image_can_show_raw_ai_signal_without_changing_its_score_or_verdict(): void
    {
        $saved = $this->media(.55, true);
        $before = $saved;
        $badge = AssessmentScore::badge($saved);
        $this->assertSame('AI-generation score: 55%', $badge['title']);
        $this->assertSame('Uncalibrated; origin remains inconclusive', $badge['description']);
        $this->assertSame($before, $saved);
        $this->assertSame('INCONCLUSIVE', $saved['verdict']);
        $this->assertNull($saved['score']['value']);
    }

    public function test_unverified_and_missing_context_show_evidence_status_without_a_made_up_percentage(): void
    {
        foreach (['UNVERIFIED' => 'Evidence insufficient', 'MISSING_CONTEXT' => 'Missing claim context'] as $verdict => $title) {
            $badge = AssessmentScore::badge(['schema_version' => 4, 'primary_task' => 'claim',
                'verdict' => $verdict, 'summary' => 'The available evidence did not resolve this claim.']);
            $this->assertSame($title, $badge['title']);
            $this->assertFalse($badge['has_percentage']);
            $this->assertStringNotContainsString('%', $badge['title']);
        }
    }

    public function test_only_a_saved_calibrated_score_is_labeled_confidence(): void
    {
        $saved = $this->media(.97);
        // Synthetic saved record tests rendering only, not real calibration quality.
        $saved['score'] = ['type' => 'confidence', 'label' => 'Confidence', 'value' => .82,
            'validation' => 'calibrated', 'method_version' => 'synthetic-calibration-fixture',
            'explanation' => 'Synthetic calibration fixture.'];
        $this->assertSame('Confidence: 82%', AssessmentScore::badge($saved)['title']);
        $saved['score']['validation'] = 'uncalibrated';
        $badge = AssessmentScore::badge($saved);
        $this->assertFalse($badge['has_percentage']);
        $this->assertStringNotContainsString('82%', $badge['title']);
    }

    public function test_factual_evidence_percentage_is_distinguished_from_confidence(): void
    {
        $badge = AssessmentScore::badge(['schema_version' => 4, 'primary_task' => 'claim', 'verdict' => 'FALSE',
            'summary' => 'The reviewed record contradicts the exact assertion.',
            'sources' => [['id' => 's1', 'url' => 'https://example.gov/record', 'name' => 'Example agency',
                'used_for' => 'The reviewed record contradicts the exact assertion.']],
            'score' => ['type' => 'evidence_strength', 'label' => 'Evidence strength', 'value' => .85,
                'method_version' => AssessmentScore::RUBRIC, 'validation' => 'provisional_unvalidated',
                'explanation' => 'A provisional evidence heuristic.']]);
        $this->assertSame('Evidence strength: 85%', $badge['title']);
        $this->assertSame('Provisional evidence assessment', $badge['description']);
    }

    public function test_failed_detector_does_not_get_a_number_from_an_unusable_raw_value(): void
    {
        $saved = $this->media(.97);
        $saved['verdict'] = 'ANALYSIS_UNAVAILABLE';
        $saved['media_assessment']['status'] = 'unavailable';
        $badge = AssessmentScore::badge($saved);
        $this->assertSame('Analysis incomplete', $badge['title']);
        $this->assertFalse($badge['has_percentage']);
    }
}
