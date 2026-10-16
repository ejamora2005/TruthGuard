<?php

namespace Tests\Unit;

use App\Services\Detections\AssessmentScore;
use App\Services\Detections\MediaAnalysisReport;
use Tests\TestCase;

/** Synthetic calibration artifacts test loading/math only, not model accuracy. */
class MediaScoreCalibrationTest extends TestCase
{
    private string $artifactPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artifactPath = tempnam(sys_get_temp_dir(), 'truthguard-calibration-');
        config()->set(['services.media_calibration.enabled' => true, 'services.media_calibration.path' => $this->artifactPath]);
    }

    protected function tearDown(): void
    {
        if (is_file($this->artifactPath)) {
            unlink($this->artifactPath);
        }
        parent::tearDown();
    }

    private function media(float $p = .9, string $label = 'fake'): array
    {
        return ['status' => 'completed', 'media_type' => 'image', 'fake_probability' => $p, 'label' => $label,
            'label_mapping' => ['real', 'fake'], 'model_version' => 'fixture-weights', 'config_sha256' => 'fixture-config',
            'processor_sha256' => 'fixture-processor', 'adapter_version' => 'fixture-adapter',
            'output_contract' => ['activation' => 'sigmoid', 'positive_class' => 'fake', 'binary_exclusive' => true]];
    }

    private function artifact(array $changes = []): void
    {
        $artifact = array_replace_recursive([
            'schema_version' => 1, 'status' => 'validated_on_supplied_population', 'method' => 'binary-temperature-v1',
            'temperature' => 2, 'input_transform' => 'logit-clipped-1e-6-v1', 'epsilon' => 1e-6,
            'positive_class' => 'fake', 'label_mapping' => ['real', 'fake'],
            'model_identity' => array_intersect_key($this->media(), array_flip(['model_version', 'config_sha256', 'processor_sha256', 'adapter_version'])),
            'population' => ['scope' => 'Synthetic unit-test population only', 'representative_of_deployment' => true, 'training_overlap_checked' => true],
            'counts' => ['calibration' => ['real' => 100, 'fake' => 100], 'test' => ['real' => 100, 'fake' => 100]],
            'independent_group_counts' => ['calibration' => ['real' => 100, 'fake' => 100], 'test' => ['real' => 100, 'fake' => 100]],
            'validation' => ['passed' => true, 'fit_used_test_data' => false, 'held_out_improvement' => true,
                'sufficient_data' => true, 'finite_interior_fit' => true],
        ], $changes);
        file_put_contents($this->artifactPath, json_encode($artifact, JSON_THROW_ON_ERROR));
        config()->set('services.media_calibration.sha256', hash_file('sha256', $this->artifactPath));
    }

    public function test_matching_pinned_validated_artifact_calibrates_both_class_directions(): void
    {
        $this->artifact();
        foreach ([[$this->media(), .9], [$this->media(.1, 'real'), .1]] as [$media, $raw]) {
            $score = AssessmentScore::media($media);
            $this->assertSame('confidence', $score['type']);
            $this->assertEqualsWithDelta(.75, $score['value'], 1e-12);
            $this->assertSame($raw, $score['raw_positive_score']);
            $this->assertSame('model_score', $score['raw_score']['type']);
            $this->assertSame('Confidence: 75%', AssessmentScore::display($score));
            $this->view('detections.partials.result-score', ['assessment' => ['score' => $score]])
                ->assertSee('Confidence: 75%')->assertSee('Calibrated on validation data')
                ->assertDontSee('Uncalibrated model output');
        }
        $result = MediaAnalysisReport::result($this->media(), []);
        $this->assertStringContainsString('Synthetic unit-test population', $result['limitations'][0]);
        $this->assertStringNotContainsString('uncalibrated', $result['limitations'][0]);
    }

    public function test_invalid_unvalidated_or_incompatible_artifacts_never_gain_confidence(): void
    {
        foreach ([['status' => 'fitted_not_validated'], ['temperature' => 0], ['positive_class' => 'real'],
            ['model_identity' => ['model_version' => 'another-model']], ['model_identity' => ['processor_sha256' => 'changed']],
            ['validation' => ['fit_used_test_data' => true]], ['validation' => ['held_out_improvement' => false]],
            ['counts' => ['test' => ['real' => 2]]], ['independent_group_counts' => ['test' => ['fake' => 1]]],
            ['population' => ['representative_of_deployment' => false]]] as $override) {
            $this->artifact($override);
            $this->assertSame('model_score', AssessmentScore::media($this->media())['type']);
        }
        file_put_contents($this->artifactPath, 'not json');
        config()->set('services.media_calibration.sha256', hash_file('sha256', $this->artifactPath));
        $this->assertSame('model_score', AssessmentScore::media($this->media())['type']);
    }

    public function test_changed_unpinned_missing_disabled_and_video_inputs_remain_uncalibrated(): void
    {
        $this->artifact();
        file_put_contents($this->artifactPath, ' ', FILE_APPEND);
        $this->assertSame('model_score', AssessmentScore::media($this->media())['type']);
        $this->artifact();
        config()->set('services.media_calibration.sha256', '');
        $this->assertSame('model_score', AssessmentScore::media($this->media())['type']);
        $this->artifact();
        config()->set('services.media_calibration.enabled', false);
        $this->assertSame('model_score', AssessmentScore::media($this->media())['type']);
        config()->set('services.media_calibration.enabled', true);
        $this->assertNull(AssessmentScore::media(array_replace($this->media(), ['media_type' => 'video']))['value']);
        config()->set('services.media_calibration.path', $this->artifactPath.'.missing');
        $this->assertSame('model_score', AssessmentScore::media($this->media())['type']);
    }

    public function test_an_inconclusive_origin_finding_does_not_gain_a_calibrated_percentage(): void
    {
        $this->artifact();
        $media = $this->media();
        $media['origin_policy'] = ['inconclusive' => true];
        $result = MediaAnalysisReport::result($media, []);
        $this->assertSame('INCONCLUSIVE', $result['verdict']);
        $this->assertNull($result['score']['value']);
    }
}
