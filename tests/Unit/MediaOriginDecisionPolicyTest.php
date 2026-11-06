<?php

namespace Tests\Unit;

use App\Services\Detections\MediaAnalysisReport;
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

    public function test_required_agreement_cannot_be_disabled_by_an_unavailable_detector(): void
    {
        config()->set('services.deepfake_cnn.enabled', false);
        $result = MediaOriginDecisionPolicy::apply($this->finding('fake', .99), null, ['decision_limited' => false]);
        $this->assertTrue($result['origin_policy']['agreement_required']);
        $this->assertTrue($result['origin_policy']['inconclusive']);
        $this->assertContains('supporting_detector_unavailable', $result['origin_policy']['reasons']);
    }

    public function test_borderline_compressed_screenshot_remains_inconclusive_with_agreement(): void
    {
        $result = MediaOriginDecisionPolicy::apply($this->finding('fake', .89), $this->finding('fake', .95),
            ['status' => 'completed', 'decision_limited' => true, 'quality_flags' => ['heavy_compression_or_resizing', 'screenshot_or_composite_likely']]);
        $report = MediaAnalysisReport::result($result, []);
        $this->assertSame('INCONCLUSIVE', $report['verdict']);
        $this->assertSame(.89, $result['fake_probability']);
        $this->assertContains('primary_below_decision_threshold', $result['origin_policy']['reasons']);
    }

    public function test_label_must_match_its_own_confidence_threshold(): void
    {
        $report = MediaAnalysisReport::result($this->finding('fake', .05), []);
        $this->assertSame('INCONCLUSIVE', $report['verdict']);
    }

    public function test_primary_detector_abstention_is_preserved_even_with_strong_agreement(): void
    {
        $primary = $this->finding('fake', .99);
        $primary['origin_policy']['inconclusive'] = true;
        $result = MediaOriginDecisionPolicy::apply($primary, $this->finding('fake', .99),
            ['status' => 'completed', 'decision_limited' => false]);
        $this->assertTrue($result['origin_policy']['inconclusive']);
        $this->assertContains('primary_detector_abstained', $result['origin_policy']['reasons']);
    }

    public function test_borderline_sampled_video_score_cannot_become_a_positive_origin_verdict(): void
    {
        $media = array_replace($this->finding('fake', .89), ['media_type' => 'video', 'frames_used' => 4]);
        $this->assertSame('INCONCLUSIVE', MediaAnalysisReport::result($media, [])['verdict']);
    }
}
