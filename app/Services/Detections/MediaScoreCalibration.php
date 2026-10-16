<?php

namespace App\Services\Detections;

/** Apply only a pinned, independently validated calibrator for this exact model. */
class MediaScoreCalibration
{
    public const METHOD = 'binary-temperature-v1';

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
            if (($artifact['schema_version'] ?? null) !== 1
                || ($artifact['method'] ?? '') !== self::METHOD
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
                || ($artifact['population']['representative_of_deployment'] ?? false) !== true
                || ($artifact['population']['training_overlap_checked'] ?? false) !== true) {
                return $rawScore;
            }
            foreach (['model_version', 'config_sha256', 'processor_sha256', 'adapter_version'] as $field) {
                if (! is_string($media[$field] ?? null) || $media[$field] === ''
                    || ($artifact['model_identity'][$field] ?? null) !== $media[$field]) {
                    return $rawScore;
                }
            }
            foreach (['calibration', 'test'] as $split) {
                foreach (['real', 'fake'] as $label) {
                    if (! is_int($artifact['counts'][$split][$label] ?? null)
                        || $artifact['counts'][$split][$label] < 100
                        || ! is_int($artifact['independent_group_counts'][$split][$label] ?? null)
                        || $artifact['independent_group_counts'][$split][$label] < 100) {
                        return $rawScore;
                    }
                }
            }
            $temperature = $artifact['temperature'] ?? null;
            $scope = $artifact['population']['scope'] ?? null;
            if (! is_numeric($temperature) || ! is_finite((float) $temperature)
                || $temperature <= .0501 || $temperature >= 99.99
                || ! is_string($scope) || trim($scope) === '' || mb_strlen($scope) > 300) {
                return $rawScore;
            }
            $p = min(1 - 1e-6, max(1e-6, (float) $media['fake_probability']));
            $z = (log($p) - log1p(-$p)) / (float) $temperature;
            $calibrated = $z >= 0 ? 1 / (1 + exp(-$z)) : exp($z) / (1 + exp($z));

            return ['type' => 'confidence', 'value' => $media['label'] === 'fake' ? $calibrated : 1 - $calibrated,
                'label' => 'Confidence', 'validation' => 'calibrated', 'method_version' => self::METHOD,
                'explanation' => 'Model class probability calibrated and tested for: '.trim($scope)
                    .'. This estimate does not prove provenance or establish accuracy on other populations.',
                'assigned_class' => $media['label'], 'raw_positive_score' => (float) $media['fake_probability'],
                'calibrated_positive_score' => $calibrated, 'model_version' => $media['model_version'],
                'calibration_sha256' => $expected, 'calibration_scope' => trim($scope),
                'formula' => 'sigmoid(logit(clipped raw synthetic score) / fitted temperature)',
                'calibration_validation' => $artifact['validation'], 'raw_score' => $rawScore];
        } catch (\Throwable) {
            // Missing, changed or malformed artifacts never turn a score into confidence.
            return $rawScore;
        }
    }
}
