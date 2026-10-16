<?php

namespace Tests\Unit;

use App\Services\Detections\AssessmentScore;
use App\Services\Detections\EvidenceVerdictCalibration;
use Tests\TestCase;

class EvidenceVerdictCalibrationTest extends TestCase
{
    private function assessment(string $verdict = 'FALSE'): array
    {
        return ['primary_task' => 'claim', 'verdict' => $verdict, 'score' => AssessmentScore::unavailable('Synthetic test only.')];
    }

    private function profile(): array
    {
        return ['schema_version' => 1, 'method' => EvidenceVerdictCalibration::METHOD,
            'status' => 'validated_on_supplied_population', 'pipeline_identity' => EvidenceVerdictCalibration::identity(),
            'fit_used_test_data' => false,
            'population' => ['scope' => 'Synthetic software fixture only', 'representative_of_deployment' => true, 'independent_review' => true],
            'cells' => ['claim|FALSE' => ['passed' => true,
                'calibration' => ['count' => 100, 'correct' => 80], 'test' => ['count' => 100, 'correct' => 80]]]];
    }

    private function withProfile(array $profile, callable $check): void
    {
        $path = tempnam(sys_get_temp_dir(), 'tg-evidence-calibration-');
        try {
            file_put_contents($path, json_encode($profile, JSON_THROW_ON_ERROR));
            config()->set(['fact_check.verdict_calibration.enabled' => true, 'fact_check.verdict_calibration.path' => $path,
                'fact_check.verdict_calibration.sha256' => hash_file('sha256', $path)]);
            $check();
        } finally {
            unlink($path);
        }
    }

    public function test_false_verdict_confidence_means_review_agreement_and_does_not_flip_the_verdict(): void
    {
        $this->withProfile($this->profile(), function () {
            $result = EvidenceVerdictCalibration::apply($this->assessment());
            $this->assertSame('FALSE', $result['verdict']);
            $this->assertSame('confidence', $result['score']['type']);
            $this->assertEqualsWithDelta(81 / 102, $result['score']['value'], 1e-12);
            $this->assertStringContainsString('not the probability the claim is true', $result['score']['explanation']);
            $this->assertSame($result, EvidenceVerdictCalibration::apply($result));
            $this->assertSame($this->assessment('UNVERIFIED'), EvidenceVerdictCalibration::apply($this->assessment('UNVERIFIED')));
            $this->assertSame($this->assessment('TRUE'), EvidenceVerdictCalibration::apply($this->assessment('TRUE')));
        });
    }

    public function test_undersized_failed_or_changed_profiles_do_not_enable_confidence(): void
    {
        foreach (['small', 'failed', 'pipeline', 'test_failure', 'tampered'] as $case) {
            $profile = $this->profile();
            if ($case === 'small') {
                $profile['cells']['claim|FALSE']['calibration']['count'] = 99;
            } elseif ($case === 'failed') {
                $profile['status'] = 'fitted_not_validated';
            } elseif ($case === 'pipeline') {
                $profile['pipeline_identity'] = str_repeat('b', 64);
            } elseif ($case === 'test_failure') {
                $profile['cells']['claim|FALSE']['test']['correct'] = 20;
            }
            $this->withProfile($profile, function () use ($case) {
                if ($case === 'tampered') {
                    file_put_contents(config('fact_check.verdict_calibration.path'), ' ', FILE_APPEND);
                }
                $this->assertSame($this->assessment(), EvidenceVerdictCalibration::apply($this->assessment()));
            });
        }
    }
}
