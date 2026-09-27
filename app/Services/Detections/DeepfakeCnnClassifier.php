<?php

namespace App\Services\Detections;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

class DeepfakeCnnClassifier
{
    /**
     * @return array<string, mixed>|null
     */
    public function classify(?UploadedFile $uploadedFile, string $mediaType, int $heuristicScore = 0): ?array
    {
        if (! (bool) config('services.deepfake_cnn.enabled', false)) {
            return null;
        }

        if (! $uploadedFile || ! $uploadedFile->isValid() || ! in_array($mediaType, ['image', 'video'], true)) {
            return null;
        }

        $mediaPath = $this->uploadedFilePath($uploadedFile);
        $scriptPath = $this->configuredPath('services.deepfake_cnn.script_path');
        $modelPath = $this->modelPathForMediaType($mediaType);

        if ($mediaPath === null || $scriptPath === null || $modelPath === null) {
            return null;
        }

        if (! is_file($scriptPath) || ! is_file($modelPath)) {
            Log::warning('TruthGuard CNN classifier is enabled but not fully configured.', [
                'script_path' => $scriptPath,
                'model_path' => $modelPath,
            ]);

            return null;
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

            return null;
        }

        if (! $process->isSuccessful()) {
            Log::warning('TruthGuard CNN classifier process returned an error.', [
                'exit_code' => $process->getExitCode(),
                'stderr' => Str::limit($process->getErrorOutput(), 500),
            ]);

            return null;
        }

        $payload = $this->decodePayload($process->getOutput());

        if ($payload === null || ($payload['status'] ?? null) !== 'completed') {
            return null;
        }

        return [
            'status' => 'completed',
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

    private function uploadedFilePath(UploadedFile $uploadedFile): ?string
    {
        foreach ([$uploadedFile->getRealPath(), $uploadedFile->getPathname()] as $candidate) {
            if (is_string($candidate) && $candidate !== '' && is_file($candidate)) {
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

        return $environment;
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
