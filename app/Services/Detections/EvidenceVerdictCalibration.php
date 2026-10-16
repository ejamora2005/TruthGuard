<?php

namespace App\Services\Detections;

/** Empirical agreement with independent review, never a probability a claim is true. */
class EvidenceVerdictCalibration
{
    public const METHOD = 'reviewed-verdict-reliability-v1';

    public static function identity(): string
    {
        $files = ['EvidenceVerdictCalibration.php', 'EvidenceVerificationService.php', 'AssessmentVerdict.php', 'ResultContract.php', 'ReportAnswer.php',
            'SubmissionTask.php', 'FactCheckEvidencePolicy.php', 'Evidence/ClaimPlanner.php', 'Evidence/EvidenceJudge.php',
            'Evidence/ArticleSearch.php', 'Evidence/OpenAiWebSearch.php', 'Evidence/SafeArticleFetcher.php',
            'Evidence/SubmissionExtractor.php', 'Evidence/SubmittedText.php', 'Evidence/RetrievalBudget.php', 'Evidence/StructuredAnalysis.php', 'Evidence/ResponsesTransport.php'];
        $hashes = [];
        foreach ($files as $file) {
            $hashes[$file] = hash_file('sha256', __DIR__.'/'.$file);
        }
        $policy = config('fact_check');
        unset($policy['verdict_calibration']);

        return hash('sha256', json_encode(['files' => $hashes, 'policy' => $policy,
            'analysis_model' => config('services.openai.model')], JSON_THROW_ON_ERROR));
    }

    public static function apply(array $result): array
    {
        $task = $result['primary_task'] ?? '';
        $verdict = $result['verdict'] ?? '';
        // Uncertainty is an answer, but must not acquire a truth-confidence badge.
        if (! config('fact_check.verdict_calibration.enabled', false)
            || ! in_array($task, ['claim', 'context'], true)
            || ! in_array($verdict, ['TRUE', 'FALSE', 'PARTLY_TRUE', 'MISLEADING', 'OUTDATED', 'OUT_OF_CONTEXT', 'SATIRE_PARODY', 'OPINION_NOT_FACT_CHECKABLE'], true)) {
            return $result;
        }
        try {
            $path = config('fact_check.verdict_calibration.path');
            $sha = config('fact_check.verdict_calibration.sha256');
            if (! is_string($path) || ! is_file($path) || filesize($path) > 1048576
                || ! is_string($sha) || ! preg_match('/^[a-f0-9]{64}$/', $sha)
                || ! hash_equals($sha, hash_file('sha256', $path))) {
                return $result;
            }
            $artifact = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
            $cell = $artifact['cells'][$task.'|'.$verdict] ?? [];
            $scope = $artifact['population']['scope'] ?? null;
            if (($artifact['method'] ?? '') !== self::METHOD || ($artifact['schema_version'] ?? null) !== 1
                || ($artifact['status'] ?? '') !== 'validated_on_supplied_population'
                || ($artifact['pipeline_identity'] ?? '') !== self::identity()
                || ($artifact['fit_used_test_data'] ?? true) !== false
                || ($artifact['population']['representative_of_deployment'] ?? false) !== true
                || ($artifact['population']['independent_review'] ?? false) !== true
                || ! is_string($scope) || trim($scope) === '' || mb_strlen($scope) > 300
                || ($cell['passed'] ?? false) !== true) {
                return $result;
            }
            foreach (['calibration', 'test'] as $split) {
                if (! is_int($cell[$split]['count'] ?? null) || $cell[$split]['count'] < 100
                    || ! is_int($cell[$split]['correct'] ?? null) || $cell[$split]['correct'] < 10
                    || $cell[$split]['count'] - $cell[$split]['correct'] < 10) {
                    return $result;
                }
            }
            $p = ($cell['calibration']['correct'] + 1) / ($cell['calibration']['count'] + 2);
            $n = $cell['test']['count'];
            $observed = $cell['test']['correct'] / $n;
            $center = ($observed + 1.96 ** 2 / (2 * $n)) / (1 + 1.96 ** 2 / $n);
            $radius = 1.96 * sqrt($observed * (1 - $observed) / $n + 1.96 ** 2 / (4 * $n ** 2)) / (1 + 1.96 ** 2 / $n);
            if (abs($p - $observed) > .05 || $p < $center - $radius || $p > $center + $radius || 2 * $radius > .2) {
                return $result;
            }
            $result['score'] = ['type' => 'confidence', 'label' => 'Confidence', 'value' => $p,
                'method_version' => self::METHOD, 'validation' => 'calibrated',
                'explanation' => 'Estimated agreement of this task and verdict with independent reviewers for: '.trim($scope)
                    .'. This is verdict reliability, not the probability the claim is true or the media is authentic.',
                'calibration_sha256' => $sha, 'pipeline_identity' => $artifact['pipeline_identity'],
                'raw_score' => ($result['score']['method_version'] ?? '') === self::METHOD
                    ? ($result['score']['raw_score'] ?? null) : ($result['score'] ?? null), 'validation_sample' => $cell];
        } catch (\Throwable) {
            // An absent or stale validation artifact never enables confidence.
        }

        return $result;
    }
}
