<?php

namespace Tests\Unit;

use App\Services\Detections\MediaAnalysisReport;
use App\Services\Detections\ReportAnswer;
use Tests\TestCase;

class MediaDetectorPredictionTest extends TestCase
{
    private function saved(string $label, float $value, string $kind = 'image'): array
    {
        return MediaAnalysisReport::result(['status' => 'completed', 'media_type' => $kind,
            'label' => $label, 'fake_probability' => $value, 'frames_used' => 12,
            'detector_disagreement' => true, 'origin_policy' => ['inconclusive' => true, 'reasons' => ['detector_disagreement']]], []);
    }

    public function test_prediction_is_shown_for_both_classes_despite_disagreement_or_low_signal(): void
    {
        foreach ([['fake', .9999], ['real', .01], ['fake', .55]] as [$label, $value]) {
            foreach (['image', 'video'] as $kind) {
                $saved = $this->saved($label, $value, $kind);
                $public = ReportAnswer::forPresentation($saved);
                $expected = $label === 'fake' ? 'LIKELY_AI_GENERATED' : 'LIKELY_NOT_AI_GENERATED';
                $this->assertSame($expected, $public['verdict']);
                $this->assertStringContainsString('The detector', $public['summary']);
                $this->assertStringContainsString('The two detectors gave different results', $public['summary']);
                $this->assertStringNotContainsString('threshold', $public['summary']);
                $this->assertSame('INCONCLUSIVE', $saved['verdict']);
                $again = ReportAnswer::forPresentation($public);
                $this->assertSame($expected, $again['verdict']);
            }
        }
    }

    public function test_no_face_frames_and_failed_detectors_are_not_given_a_binary_prediction(): void
    {
        $saved = $this->saved('real', .1, 'video');
        $saved['media_assessment']['frames_used'] = 0;
        $this->assertSame('INCONCLUSIVE', ReportAnswer::forPresentation($saved)['verdict']);
        $saved['media_assessment']['status'] = 'unavailable';
        $this->assertArrayNotHasKey('media_prediction', ReportAnswer::forPresentation($saved));
    }
}
