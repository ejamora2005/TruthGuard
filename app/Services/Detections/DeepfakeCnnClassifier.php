<?php

namespace App\Services\Detections;

use App\Support\WindowsProcessEnvironment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

class DeepfakeCnnClassifier
{
    private float $started;

    private ?string $inputPath = null;

    /**
     * @return array<string, mixed>|null
     */
    public function classify(?UploadedFile $uploadedFile, string $mediaType, int $heuristicScore = 0): ?array
    {
        $this->started = microtime(true);
        $this->inputPath = $uploadedFile ? $this->uploadedFilePath($uploadedFile) : null;
        if (! in_array($mediaType, ['image', 'video'], true)) {
            return null;
        }
        if (! (bool) config('services.deepfake_cnn.enabled', false)) {
            return $this->failure($mediaType, 'disabled', 'The media detector is disabled in this deployment.');
        }

        if (! $uploadedFile || ! $uploadedFile->isValid() || ! in_array($mediaType, ['image', 'video'], true)) {
            return $this->failure($mediaType, 'missing_media', 'The original upload is unavailable or could not be read. Upload the file again.');
        }

        $mediaPath = $this->uploadedFilePath($uploadedFile);
        $scriptPath = $this->configuredPath('services.deepfake_cnn.script_path');
        $modelPath = $this->modelPathForMediaType($mediaType);

        if ($mediaPath === null || $scriptPath === null || $modelPath === null) {
            return $this->failure($mediaType, 'configuration_error', 'The media detector or its model is not configured.');
        }
        $inputHash = hash_file('sha256', $mediaPath);
        $inputBytes = filesize($mediaPath);

        if (! is_file($scriptPath) || ! is_file($modelPath)) {
            Log::warning('TruthGuard CNN classifier is enabled but not fully configured.', [
                'script_path' => $scriptPath,
                'model_path' => $modelPath,
            ]);

            return $this->failure($mediaType, 'model_missing', 'The configured media model or inference adapter is missing.');
        }

        $payload = [
            'media_type' => $mediaType,
            'media_path' => $mediaPath,
            'heuristic_screening_score' => $heuristicScore,
        ];

        $process = new Process([
            (string) config('services.deepfake_cnn.python_binary', 'python'),
            $scriptPath,
        ], base_path());
        $process->setTimeout(max(1, (int) config('services.deepfake_cnn.timeout', 90)));
        $process->setInput(json_encode($payload, JSON_THROW_ON_ERROR));
        $process->setEnv($this->processEnvironment($modelPath, $mediaType));

        try {
            $process->run();
        } catch (Throwable $exception) {
            Log::warning('TruthGuard CNN classifier process failed to start.', [
                'message' => $exception->getMessage(),
            ]);

            return $this->failure($mediaType, 'process_failed', 'The media detector could not finish. Check the Python runtime and model dependencies, then retry.');
        }

        if (! $process->isSuccessful()) {
            Log::warning('TruthGuard CNN classifier process returned an error.', [
                'exit_code' => $process->getExitCode(),
                'stderr' => Str::limit($process->getErrorOutput(), 500),
            ]);

            return $this->failure($mediaType, 'process_failed', 'The media detector stopped before returning a result. Retry after checking the detector service.');
        }

        $payload = $this->decodePayload($process->getOutput());

        if ($payload === null || ($payload['status'] ?? null) !== 'completed') {
            $code = $payload['status'] ?? 'invalid_response';
            Log::warning('TruthGuard CNN inference unavailable.', ['status' => $code]);

            return $this->failure($mediaType, $code, match ($code) {
                'model-error' => 'The detector could not decode or process this upload. Try the original image in a supported format.',
                'dependency-error' => 'The detector could not load its runtime or trained weights. Check the model installation and retry.',
                'configuration-error' => 'The model configuration does not match the deployed weights or preprocessing.',
                'missing-media' => 'The original upload could not be read by the detector. Upload the file again.',
                default => 'The detector did not return a valid result. Retry the analysis.',
            });
        }

        if (! is_readable($mediaPath) || ! is_file($mediaPath) || hash_file('sha256', $mediaPath) !== $inputHash) {
            return $this->failure($mediaType, 'input_changed', 'The original upload changed or became unavailable during analysis.');
        }

        if (($payload['label_mapping'] ?? null) !== ['real', 'fake']
            || ($payload['output_contract']['activation'] ?? null) !== 'sigmoid'
            || ($payload['output_contract']['positive_class'] ?? null) !== 'fake'
            || ($payload['output_contract']['binary_exclusive'] ?? null) !== true
            || ($payload['output_contract']['validated_at_inference'] ?? null) !== true
            || ! is_numeric($payload['fake_probability'] ?? null) || ! is_numeric($payload['threshold'] ?? null)
            || ! is_finite((float) $payload['fake_probability']) || $payload['fake_probability'] < 0 || $payload['fake_probability'] > 1
            || $payload['threshold'] < 0 || $payload['threshold'] > 1
            || ($payload['label'] ?? null) !== ($payload['fake_probability'] >= $payload['threshold'] ? 'fake' : 'real')) {
            return $this->failure($mediaType, 'invalid_response', 'The detector returned inconsistent scores or labels. No prediction was substituted.');
        }

        return [
            'status' => 'completed',
            'task' => $mediaType === 'image' ? 'AI-generation screening' : 'Sampled-frame synthetic-media screening',
            'elapsed_ms' => (int) round((microtime(true) - $this->started) * 1000),
            'failure_reason' => null,
            'failure_code' => null,
            'assessed_at' => now()->utc()->toIso8601String(),
            'input_sha256' => $inputHash,
            'input_bytes' => $inputBytes,
            'calibration' => 'not_established',
            'adapter_version' => 'media-v2',
            'config_sha256' => is_file($this->configPathForMediaType($mediaType) ?? '') ? hash_file('sha256', $this->configPathForMediaType($mediaType)) : null,
            'model_name' => $payload['model_name'] ?? 'Trained media CNN',
            'preprocessing' => $payload['preprocessing'] ?? [],
            'label_mapping' => $payload['label_mapping'] ?? [],
            'output_contract' => $payload['output_contract'] ?? [],
            'raw_output' => ['synthetic_class_score' => (float) $payload['fake_probability'], 'decision_threshold' => (float) $payload['threshold']],
            'threshold_source' => $payload['threshold_source'] ?? 'deployment_configuration',
            'model_version' => 'sha256:'.hash_file('sha256', $modelPath),
            'model_family' => (string) ($payload['model_family'] ?? 'cnn'),
            'media_type' => (string) ($payload['media_type'] ?? $mediaType),
            'screening_score' => max(0, min(100, (int) ($payload['screening_score'] ?? 0))),
            'fake_probability' => max(0.0, min(1.0, (float) ($payload['fake_probability'] ?? 0.0))),
            'threshold' => max(0.0, min(1.0, (float) ($payload['threshold'] ?? $this->thresholdForMediaType($mediaType)))),
            'label' => (string) ($payload['label'] ?? 'review'),
            'summary' => trim((string) ($payload['summary'] ?? 'CNN inference completed.')),
            'signals' => is_array($payload['signals'] ?? null) ? $payload['signals'] : [],
            'frames_used' => max(0, (int) ($payload['frames_used'] ?? 0)),
            'aggregation_method' => (string) ($payload['aggregation_method'] ?? ''),
        ];
    }

    private function failure(string $mediaType, string $code, string $reason): array
    {
        $modelPath = $this->modelPathForMediaType($mediaType);

        return ['status' => 'unavailable', 'media_type' => $mediaType,
            'task' => $mediaType === 'image' ? 'AI-generation screening' : 'Sampled-frame synthetic-media screening',
            'model_version' => $modelPath && is_file($modelPath) ? 'sha256:'.hash_file('sha256', $modelPath) : null,
            'adapter_version' => 'media-v2', 'input_sha256' => $this->inputPath && is_file($this->inputPath) && is_readable($this->inputPath) ? hash_file('sha256', $this->inputPath) : null,
            'label' => null, 'fake_probability' => null, 'threshold' => null,
            'failure_code' => $code, 'failure_reason' => $reason,
            'elapsed_ms' => (int) round((microtime(true) - $this->started) * 1000),
            'assessed_at' => now()->utc()->toIso8601String()];
    }

    private function uploadedFilePath(UploadedFile $uploadedFile): ?string
    {
        foreach ([$uploadedFile->getRealPath(), $uploadedFile->getPathname()] as $candidate) {
            if (is_string($candidate) && $candidate !== '' && is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function configuredPath(string $key): ?string
    {
        $value = trim((string) config($key, ''));

        if ($value === '') {
            return null;
        }

        return $this->isAbsolutePath($value) ? $value : base_path($value);
    }

    private function modelPathForMediaType(string $mediaType): ?string
    {
        if ($mediaType === 'video') {
            return $this->configuredPath('services.deepfake_cnn.video_model_path')
                ?? $this->configuredPath('services.deepfake_cnn.model_path');
        }

        return $this->configuredPath('services.deepfake_cnn.model_path');
    }

    private function configPathForMediaType(string $mediaType): ?string
    {
        if ($mediaType === 'video') {
            return $this->configuredPath('services.deepfake_cnn.video_config_path')
                ?? $this->configuredPath('services.deepfake_cnn.config_path');
        }

        return $this->configuredPath('services.deepfake_cnn.config_path');
    }

    private function thresholdForMediaType(string $mediaType): float
    {
        if ($mediaType === 'video') {
            return (float) config('services.deepfake_cnn.video_threshold', config('services.deepfake_cnn.threshold', 0.5));
        }

        return (float) config('services.deepfake_cnn.threshold', 0.5);
    }

    private function inputRangeForMediaType(string $mediaType): string
    {
        if ($mediaType === 'video') {
            return trim((string) config('services.deepfake_cnn.video_input_range', config('services.deepfake_cnn.input_range', '')));
        }

        return trim((string) config('services.deepfake_cnn.input_range', ''));
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1;
    }

    /**
     * @return array<string, string>
     */
    private function processEnvironment(string $modelPath, string $mediaType): array
    {
        $environment = [
            'DEEPFAKE_CNN_MODEL_PATH' => $modelPath,
            'DEEPFAKE_CNN_IMAGE_SIZE' => (string) config('services.deepfake_cnn.image_size', 224),
            'DEEPFAKE_CNN_THRESHOLD' => (string) $this->thresholdForMediaType($mediaType),
            'DEEPFAKE_CNN_VIDEO_FRAME_LIMIT' => (string) config('services.deepfake_cnn.video_frame_limit', 8),
            'DEEPFAKE_CNN_VIDEO_FRAME_STRIDE' => (string) config('services.deepfake_cnn.video_frame_stride', 12),
            'DEEPFAKE_CNN_REAL_LABEL' => (string) config('services.deepfake_cnn.real_label', 'real'),
            'DEEPFAKE_CNN_FAKE_LABEL' => (string) config('services.deepfake_cnn.fake_label', 'fake'),
        ];

        if ($mediaType === 'video') {
            $environment['DEEPFAKE_CNN_VIDEO_FRAME_LIMIT'] = (string) config('services.deepfake_cnn.video_frame_limit', 16);
            $environment['DEEPFAKE_CNN_VIDEO_AGGREGATION_METHOD'] = (string) config('services.deepfake_cnn.video_aggregation_method', 'top5_mean');
            $environment['DEEPFAKE_CNN_VIDEO_FACE_CROP'] = (string) ((bool) config('services.deepfake_cnn.video_face_crop', true) ? 'true' : 'false');
            $environment['DEEPFAKE_CNN_VIDEO_FACE_MARGIN'] = (string) config('services.deepfake_cnn.video_face_margin', 0.45);
        }

        $configPath = $this->configPathForMediaType($mediaType);

        if ($configPath !== null && is_file($configPath)) {
            $environment['DEEPFAKE_CNN_CONFIG_PATH'] = $configPath;
        }

        $inputRange = $this->inputRangeForMediaType($mediaType);

        if ($inputRange !== '') {
            $environment['DEEPFAKE_CNN_INPUT_RANGE'] = $inputRange;
        }

        return $environment + WindowsProcessEnvironment::variables();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodePayload(string $output): ?array
    {
        $output = trim($output);

        if ($output === '') {
            return null;
        }

        $decoded = json_decode($output, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        $start = strpos($output, '{');
        $end = strrpos($output, '}');

        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $decoded = json_decode(substr($output, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : null;
    }
}
