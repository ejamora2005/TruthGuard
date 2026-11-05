<?php

namespace Tests\Unit;

use App\Services\Detections\MediaInputAssessment;
use App\Services\Detections\MediaOriginDecisionPolicy;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class MediaOriginDecisionPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set([
            'services.ai_origin.minimum_class_score' => .9,
            'services.ai_origin.supporting_minimum_class_score' => .75,
            'services.ai_origin.require_detector_agreement' => true,
            'services.deepfake_cnn.enabled' => true,
        ]);
    }

    private function finding(string $label, float $score): array
    {
        return ['status' => 'completed', 'media_type' => 'image', 'label' => $label,
            'fake_probability' => $score, 'origin_policy' => []];
    }

    public function test_two_strong_agreeing_detectors_allow_a_selective_decision(): void
    {
        $result = MediaOriginDecisionPolicy::apply(
            $this->finding('fake', .96),
            $this->finding('fake', .88),
            ['status' => 'completed', 'decision_limited' => false, 'reliability_limited' => false],
        );

        $this->assertFalse($result['origin_policy']['inconclusive']);
        $this->assertFalse($result['detector_disagreement']);
        $this->assertSame(MediaOriginDecisionPolicy::VERSION, $result['origin_policy']['version']);
    }

    public function test_disagreement_or_limited_input_abstains_without_averaging_scores(): void
    {
        $result = MediaOriginDecisionPolicy::apply(
            $this->finding('fake', .96),
            $this->finding('real', .08),
            ['status' => 'completed', 'decision_limited' => true, 'reliability_limited' => true],
        );

        $this->assertTrue($result['origin_policy']['inconclusive']);
        $this->assertTrue($result['detector_disagreement']);
        $this->assertContains('detector_disagreement', $result['origin_policy']['reasons']);
        $this->assertContains('input_quality_or_composition_limited', $result['origin_policy']['reasons']);
        $this->assertSame(.96, $result['fake_probability']);
    }

    public function test_input_inspection_records_scope_and_treats_missing_metadata_neutrally(): void
    {
        $path = base_path('tests/Fixtures/evidence/media/notice.png');
        $result = MediaInputAssessment::inspect(new UploadedFile($path, 'notice.png', 'image/png', null, true));

        $this->assertSame('completed', $result['status']);
        $this->assertSame(1000, $result['width']);
        $this->assertTrue($result['provenance']['absence_is_not_evidence']);
        $this->assertArrayHasKey('scope', $result);
        $this->assertArrayHasKey('decision_limited', $result);
    }
}
