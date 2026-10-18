<?php

namespace Tests\Unit;

use App\Services\Detections\AssessmentScore;
use App\Services\Detections\MediaAnalysisReport;
use App\Services\Detections\MediaScoreCalibration;
use App\Services\Detections\ReportAnswer;
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

    private function plattArtifact(array $changes = []): void
    {
        $identity = array_intersect_key($this->media(), array_flip(['model_version', 'config_sha256', 'processor_sha256', 'adapter_version']));
        $this->artifact(array_replace_recursive([
            'method' => MediaScoreCalibration::PLATT_METHOD, 'intercept' => -log(3), 'slope' => 1,
            'linear_predictor_clip' => 40, 'fit_model_identity' => $identity,
            'validation' => ['evaluation_model_identity' => $identity],
        ], $changes));
    }

    public function test_platt_mapping_preserves_raw_classes_and_supports_both_directions(): void
    {
        $this->plattArtifact();
        foreach ([[$this->media(.9), .75], [$this->media(.1, 'real'), 27 / 28]] as [$media, $expected]) {
            $score = AssessmentScore::media($media);
            $this->assertSame('confidence', $score['type']);
            $this->assertSame(MediaScoreCalibration::PLATT_METHOD, $score['method_version']);
            $this->assertEqualsWithDelta($expected, $score['value'], 1e-12);
            $this->assertSame($media['label'], $score['assigned_class']);
            $this->assertSame($media['fake_probability'], $score['raw_positive_score']);
            $this->assertSame('model_score', $score['raw_score']['type']);
        }
        foreach ([0.0, 1.0] as $p) {
            $score = AssessmentScore::media($this->media($p, $p > .5 ? 'fake' : 'real'));
            $this->assertTrue(is_finite($score['value']));
            $this->assertGreaterThan(0, $score['value']);
            $this->assertLessThan(1, $score['value']);
        }
    }

    public function test_platt_transfer_requires_target_evaluation_and_preserves_fit_identity(): void
    {
        $transfer = ['fit_model_identity' => ['adapter_version' => 'fixture-cuda-adapter']];
        $this->plattArtifact($transfer);
        $this->assertSame('model_score', AssessmentScore::media($this->media())['type']);

        $this->plattArtifact(array_replace_recursive($transfer, ['validation' => ['runtime_transfer_validated' => true]]));
        $score = AssessmentScore::media($this->media());
        $this->assertSame('confidence', $score['type']);
        $this->assertSame('fixture-cuda-adapter', $score['calibration_fit_model_identity']['adapter_version']);

        foreach ([['model_version' => 'other-weights'], ['config_sha256' => 'other-config'],
            ['processor_sha256' => 'other-processor']] as $mismatch) {
            $this->plattArtifact(array_replace_recursive($transfer, ['fit_model_identity' => $mismatch,
                'validation' => ['runtime_transfer_validated' => true]]));
            $this->assertSame('model_score', AssessmentScore::media($this->media())['type']);
        }
        $this->plattArtifact(array_replace_recursive($transfer, ['validation' => ['runtime_transfer_validated' => true,
            'evaluation_model_identity' => ['adapter_version' => 'another-target']]]));
        $this->assertSame('model_score', AssessmentScore::media($this->media())['type']);
    }

    public function test_platt_rejects_invalid_coefficients_and_unvalidated_artifacts(): void
    {
        foreach ([['slope' => 0], ['slope' => -1], ['slope' => true], ['slope' => '1'], ['slope' => 100],
            ['intercept' => '0'], ['intercept' => false], ['intercept' => 41], ['linear_predictor_clip' => null],
            ['fit_model_identity' => ['adapter_version' => '']], ['validation' => ['evaluation_model_identity' => null]],
            ['validation' => ['passed' => false]], ['validation' => ['fit_used_test_data' => true]],
            ['counts' => ['test' => ['fake' => 99]]], ['population' => ['training_overlap_checked' => false]],
            ['population' => ['representative_of_deployment' => false]], ['status' => 'candidate_only']] as $invalid) {
            $this->plattArtifact($invalid);
            $this->assertSame('model_score', AssessmentScore::media($this->media())['type']);
        }
    }

    public function test_reviewed_benchmark_mapping_is_a_calibrated_score_and_preserves_the_verdict(): void
    {
        $this->plattArtifact(['output_mode' => 'benchmark_model_score', 'grouping_method' => 'sha256-prompt-dhash64-v1',
            'screened_group_counts' => ['calibration' => ['real' => 100, 'fake' => 100], 'test' => ['real' => 100, 'fake' => 100]],
            'population' => [
                'representative_of_deployment' => false, 'benchmark_reviewed' => true,
                'review_sha256' => str_repeat('a', 64),
            ]]);
        $result = MediaAnalysisReport::result($this->media(), []);
        $score = $result['score'];
        $this->assertSame('model_score', $score['type']);
        $this->assertSame('calibrated_benchmark', $score['validation']);
        $this->assertSame('Calibrated model score: 75%', AssessmentScore::display($score));
        $this->assertStringContainsString('does not establish confidence', $score['explanation']);
        $this->assertStringNotContainsString('uncalibrated', $result['limitations'][0]);
        $this->view('detections.partials.result-score', ['assessment' => $result])
            ->assertSee('Calibrated model score: 75%')->assertSee('Calibrated on benchmark images')
            ->assertDontSee('Confidence:')->assertDontSee('Uncalibrated model output');
        $this->assertSame('Calibrated on benchmark images', AssessmentScore::badge($result)['description']);

        config()->set('services.media_calibration.enabled', false);
        $raw = MediaAnalysisReport::result($this->media(), []);
        $this->assertSame($raw['verdict'], $result['verdict']);
        $this->assertSame($raw['assessment_label'], $result['assessment_label']);
        $this->assertSame($raw['score'], $score['raw_score']);
        // Refreshing saved results must preserve their recorded score without
        // reopening the calibration artifact or running a detector/AI request.
        unlink($this->artifactPath);
        $public = ReportAnswer::forPresentation(json_decode(json_encode($result), true));
        $again = ReportAnswer::forPresentation($public);
        $this->assertSame('calibrated_benchmark', $again['score']['validation']);
        $this->assertSame($score['value'], $again['score']['value']);
        $this->assertSame('Calibrated model score: 75%', $again['score_display']);

        foreach ([['method_version' => 'unknown'], ['validation' => 'uncalibrated'], ['type' => 'confidence']] as $invalid) {
            $changed = $result;
            $changed['score'] = array_replace($score, $invalid);
            $this->assertSame('unavailable', ReportAnswer::forPresentation($changed)['score']['type']);
        }
    }

    public function test_benchmark_mode_still_requires_review_and_independent_validation(): void
    {
        $base = ['output_mode' => 'benchmark_model_score', 'grouping_method' => 'sha256-prompt-dhash64-v1',
            'screened_group_counts' => ['calibration' => ['real' => 100, 'fake' => 100], 'test' => ['real' => 100, 'fake' => 100]],
            'population' => [
                'representative_of_deployment' => false, 'benchmark_reviewed' => true,
                'review_sha256' => str_repeat('a', 64),
            ]];
        foreach ([['population' => ['benchmark_reviewed' => false]], ['population' => ['review_sha256' => '']],
            ['population' => ['training_overlap_checked' => false]], ['validation' => ['passed' => false]],
            ['validation' => ['fit_used_test_data' => true]], ['validation' => ['held_out_improvement' => false]],
            ['screened_group_counts' => ['test' => ['fake' => 99]]], ['grouping_method' => 'unknown'], ['output_mode' => 'unknown'],
            ['method' => MediaScoreCalibration::METHOD]] as $invalid) {
            $this->plattArtifact(array_replace_recursive($base, $invalid));
            $this->assertSame('uncalibrated', AssessmentScore::media($this->media())['validation']);
        }
    }

    public function test_committed_linux_reviewed_artifact_applies_recorded_scores_without_changing_verdicts(): void
    {
        $path = base_path('automation/models/ai-origin-broad/calibration.json');
        $hash = '53944a9bc9f4a302eafe6125977b061692afcc8c1e8b529b3bd901590f439cca';
        $this->assertSame($hash, hash_file('sha256', $path));
        $artifact = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        $reviewPath = base_path('automation/models/ai-origin-broad/calibration-linux-review.json');
        $review = json_decode(file_get_contents($reviewPath), true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame(hash_file('sha256', $reviewPath), $artifact['population']['additional_runtime_review_sha256']);
        $this->assertSame(1200, $review['evaluation']['completed']);
        $this->assertFalse($review['evaluation']['original_24_case_equivalence_passed']);
        $this->assertFalse($review['evaluation']['independent_new_test_sample']);
        config()->set(['services.media_calibration.path' => $path, 'services.media_calibration.sha256' => $hash]);

        // Replay reported numbers through PHP only; do not call the detector or AI.
        foreach ([[.9065289497375488, .8293821072737214], [.83543860912323, .7108527581559477]] as [$raw, $expected]) {
            $media = array_replace($this->media($raw), $artifact['model_identity']);
            $media['origin_policy'] = ['inconclusive' => max($raw, 1 - $raw) < .9];
            $score = AssessmentScore::media($media);
            $this->assertSame('calibrated_benchmark', $score['validation']);
            $this->assertSame('model_score', $score['type']);
            $this->assertEqualsWithDelta($expected, $score['value'], 1e-12);
            $this->assertSame($raw, $score['raw_positive_score']);
            $calibratedResult = MediaAnalysisReport::result($media, []);
            config()->set('services.media_calibration.enabled', false);
            $rawResult = MediaAnalysisReport::result($media, []);
            $this->assertSame($rawResult['verdict'], $calibratedResult['verdict']);
            config()->set('services.media_calibration.enabled', true);
        }
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
        foreach ([['status' => 'fitted_not_validated'], ['status' => 'experimental_benchmark_only'], ['temperature' => 0], ['positive_class' => 'real'],
            ['model_identity' => ['model_version' => 'another-model']], ['model_identity' => ['processor_sha256' => 'changed']],
            ['model_identity' => ['adapter_version' => 'broad-ai-origin-cuda-fp32-v1']],
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
