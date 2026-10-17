<?php

namespace App\Services\Detections;

/** Apply only a pinned, independently validated calibrator for this exact model. */
class MediaScoreCalibration
{
    public const METHOD = 'binary-temperature-v1';

    public const PLATT_METHOD = 'binary-platt-logit-v1';

    public static function apply(array $rawScore, array $media): array
    {
        if (! config('services.media_calibration.enabled', false)
            || ($rawScore['type'] ?? '') !== 'model_score' || ($media['media_type'] ?? '') !== 'image') {
            return $rawScore;
        }
        try {
            $path = (string) config('services.media_calibration.path', '');
            if (! preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/)/', $path)) {
                $path = base_path($path);
            }
            $expected = (string) config('services.media_calibration.sha256', '');
            if (! preg_match('/^[a-f0-9]{64}$/', $expected) || ! is_file($path)
                || filesize($path) > 1048576 || ! hash_equals($expected, hash_file('sha256', $path))) {
                return $rawScore;
            }
            $artifact = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
            $method = $artifact['method'] ?? '';
            $outputMode = $artifact['output_mode'] ?? 'confidence';
            $benchmarkScore = $outputMode === 'benchmark_model_score' && $method === self::PLATT_METHOD;
            if (($artifact['schema_version'] ?? null) !== 1
                || ! in_array($method, [self::METHOD, self::PLATT_METHOD], true)
                || ! in_array($outputMode, ['confidence', 'benchmark_model_score'], true)
                || ($outputMode === 'benchmark_model_score' && ! $benchmarkScore)
                || ($artifact['status'] ?? '') !== 'validated_on_supplied_population'
                || ($artifact['positive_class'] ?? '') !== 'fake'
                || ($artifact['label_mapping'] ?? []) !== ['real', 'fake']
                || ($artifact['input_transform'] ?? '') !== 'logit-clipped-1e-6-v1'
                || ($artifact['epsilon'] ?? null) !== 1e-6
                || ($artifact['validation']['passed'] ?? false) !== true
                || ($artifact['validation']['fit_used_test_data'] ?? true) !== false
                || ($artifact['validation']['held_out_improvement'] ?? false) !== true
                || ($artifact['validation']['sufficient_data'] ?? false) !== true
                || ($artifact['validation']['finite_interior_fit'] ?? false) !== true
                || (! $benchmarkScore && ($artifact['population']['representative_of_deployment'] ?? false) !== true)
                || ($artifact['population']['training_overlap_checked'] ?? false) !== true) {
                return $rawScore;
            }
            if ($benchmarkScore && (($artifact['population']['benchmark_reviewed'] ?? false) !== true
                || ! is_string($artifact['population']['review_sha256'] ?? null)
                || ! preg_match('/^[a-f0-9]{64}$/', $artifact['population']['review_sha256'])
                || ($artifact['grouping_method'] ?? '') !== 'sha256-prompt-dhash64-v1')) {
                return $rawScore;
            }
            foreach (['model_version', 'config_sha256', 'processor_sha256', 'adapter_version'] as $field) {
                if (! is_string($media[$field] ?? null) || $media[$field] === ''
                    || ($artifact['model_identity'][$field] ?? null) !== $media[$field]) {
                    return $rawScore;
                }
            }
            $groupCountsKey = $benchmarkScore ? 'screened_group_counts' : 'independent_group_counts';
            foreach (['calibration', 'test'] as $split) {
                foreach (['real', 'fake'] as $label) {
                    if (! is_int($artifact['counts'][$split][$label] ?? null)
                        || $artifact['counts'][$split][$label] < 100
                        || ! is_int($artifact[$groupCountsKey][$split][$label] ?? null)
                        || $artifact[$groupCountsKey][$split][$label] < 100
                        || $artifact[$groupCountsKey][$split][$label] > $artifact['counts'][$split][$label]) {
                        return $rawScore;
                    }
                }
            }
            $scope = $artifact['population']['scope'] ?? null;
            if (! is_string($scope) || trim($scope) === '' || mb_strlen($scope) > 300) {
                return $rawScore;
            }
            $p = min(1 - 1e-6, max(1e-6, (float) $media['fake_probability']));
            $logit = log($p) - log1p(-$p);
            if ($method === self::PLATT_METHOD) {
                $intercept = $artifact['intercept'] ?? null;
                $slope = $artifact['slope'] ?? null;
                if ((! is_int($intercept) && ! is_float($intercept))
                    || (! is_int($slope) && ! is_float($slope))
                    || ! is_finite((float) $intercept) || ! is_finite((float) $slope)
                    || abs($intercept) > 40 || $slope <= 0 || $slope >= 100
                    || ($artifact['linear_predictor_clip'] ?? null) !== 40) {
                    return $rawScore;
                }
                // Preserve the fitting runtime identity. A different adapter is
                // accepted only after untouched evaluation on this target runtime.
                foreach (['model_version', 'config_sha256', 'processor_sha256', 'adapter_version'] as $field) {
                    if (($artifact['validation']['evaluation_model_identity'][$field] ?? null) !== $media[$field]
                        || ! is_string($artifact['fit_model_identity'][$field] ?? null)
                        || $artifact['fit_model_identity'][$field] === ''
                        || ($field !== 'adapter_version' && $artifact['fit_model_identity'][$field] !== $media[$field])) {
                        return $rawScore;
                    }
                }
                if ($artifact['fit_model_identity']['adapter_version'] !== $media['adapter_version']
                    && ($artifact['validation']['runtime_transfer_validated'] ?? false) !== true) {
                    return $rawScore;
                }
                $z = max(-40, min(40, $intercept + $slope * $logit));
                $formula = 'sigmoid(clip(fitted intercept + fitted slope * logit(clipped raw synthetic score), -40, 40))';
            } else {
                $temperature = $artifact['temperature'] ?? null;
                if (! is_numeric($temperature) || ! is_finite((float) $temperature)
                    || $temperature <= .0501 || $temperature >= 99.99) {
                    return $rawScore;
                }
                $z = $logit / (float) $temperature;
                $formula = 'sigmoid(logit(clipped raw synthetic score) / fitted temperature)';
            }
            $calibrated = $z >= 0 ? 1 / (1 + exp(-$z)) : exp($z) / (1 + exp($z));

            $explanation = $benchmarkScore
                ? 'Model class score calibrated and tested on: '.trim($scope)
                    .'. This benchmark does not establish confidence for other image populations or prove provenance.'
                : 'Model class probability calibrated and tested for: '.trim($scope)
                    .'. This estimate does not prove provenance or establish accuracy on other populations.';

            return ['type' => $benchmarkScore ? 'model_score' : 'confidence',
                'value' => $media['label'] === 'fake' ? $calibrated : 1 - $calibrated,
                'label' => $benchmarkScore ? 'Calibrated model score' : 'Confidence',
                'validation' => $benchmarkScore ? 'calibrated_benchmark' : 'calibrated', 'method_version' => $method,
                'explanation' => $explanation,
                'assigned_class' => $media['label'], 'raw_positive_score' => (float) $media['fake_probability'],
                'calibrated_positive_score' => $calibrated, 'model_version' => $media['model_version'],
                'calibration_sha256' => $expected, 'calibration_scope' => trim($scope),
                'formula' => $formula,
                'calibration_fit_model_identity' => $artifact['fit_model_identity'] ?? $artifact['model_identity'],
                'calibration_output_mode' => $outputMode,
                'calibration_population' => $artifact['population'],
                'calibration_validation' => $artifact['validation'], 'raw_score' => $rawScore];
        } catch (\Throwable) {
            // Missing, changed or malformed artifacts never turn a score into confidence.
            return $rawScore;
        }
    }
}
