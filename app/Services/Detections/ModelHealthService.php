<?php

namespace App\Services\Detections;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;
use Throwable;

class ModelHealthService
{
    public function __construct(private readonly DeepfakeCnnClassifier $deepfakeCnnClassifier) {}

    /**
     * @return array<string, mixed>
     */
    public function check(bool $load = false): array
    {
        $checks = [
            'filipino_news_transformer' => $this->checkFilipinoTransformer($load),
            'image_cnn' => $this->checkImageCnn($load),
            'video_face_cnn' => $this->checkVideoCnn($load),
        ];

        return [
            'ok' => collect($checks)->every(fn (array $check): bool => (bool) ($check['ok'] ?? false)),
            'models' => $checks,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkFilipinoTransformer(bool $load): array
    {
        $url = rtrim((string) config('services.filipino_news_classifier.url', ''), '/');

        if ($url === '') {
            return [
                'ok' => false,
                'enabled' => (bool) config('services.filipino_news_classifier.enabled', true),
                'reason' => 'TRUTHGUARD_NLP_URL is not configured.',
            ];
        }

        if (! $load) {
            return [
                'ok' => true,
                'enabled' => (bool) config('services.filipino_news_classifier.enabled', true),
                'url_configured' => true,
            ];
        }

        try {
            $response = Http::acceptJson()
                ->connectTimeout(3)
                ->timeout(15)
                ->get($url.'/health');
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'enabled' => (bool) config('services.filipino_news_classifier.enabled', true),
                'reason' => 'NLP health request failed: '.$exception->getMessage(),
            ];
        }

        return [
            'ok' => $response->successful()
                && (bool) $response->json('ok')
                && (bool) $response->json('loaded'),
            'enabled' => (bool) config('services.filipino_news_classifier.enabled', true),
            'http_status' => $response->status(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkImageCnn(bool $load): array
    {
        $modelPath = $this->configuredPath('services.deepfake_cnn.model_path');
        $configPath = $this->configuredPath('services.deepfake_cnn.config_path');
        $base = $this->checkCnnPaths($modelPath, $configPath);

        if (! $load || ! $base['ok']) {
            return array_merge($base, ['enabled' => (bool) config('services.deepfake_cnn.enabled', false)]);
        }

        $probePath = tempnam(sys_get_temp_dir(), 'truthguard-image-model-');

        if ($probePath === false) {
            return array_merge($base, [
                'ok' => false,
                'enabled' => (bool) config('services.deepfake_cnn.enabled', false),
                'reason' => 'Unable to create image probe file.',
            ]);
        }

        $pngPath = $probePath.'.png';
        @unlink($probePath);
        file_put_contents($pngPath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO2Z3ioAAAAASUVORK5CYII=', true));

        try {
            $result = $this->withDeepfakeEnabled(fn () => $this->deepfakeCnnClassifier->classify(
                new UploadedFile($pngPath, 'truthguard-model-health.png', 'image/png', null, true),
                'image'
            ));
        } finally {
            @unlink($pngPath);
        }

        return array_merge($base, [
            'ok' => is_array($result) && ($result['status'] ?? null) === 'completed',
            'enabled' => (bool) config('services.deepfake_cnn.enabled', false),
            'loaded' => is_array($result) && ($result['status'] ?? null) === 'completed',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function checkVideoCnn(bool $load): array
    {
        $modelPath = $this->configuredPath('services.deepfake_cnn.video_model_path')
            ?? $this->configuredPath('services.deepfake_cnn.model_path');
        $configPath = $this->configuredPath('services.deepfake_cnn.video_config_path')
            ?? $this->configuredPath('services.deepfake_cnn.config_path');
        $base = $this->checkCnnPaths($modelPath, $configPath);

        if (! $load || ! $base['ok']) {
            return array_merge($base, ['enabled' => (bool) config('services.deepfake_cnn.enabled', false)]);
        }

        $probePath = $this->makeVideoProbe();

        if ($probePath === null) {
            return array_merge($base, [
                'ok' => false,
                'enabled' => (bool) config('services.deepfake_cnn.enabled', false),
                'reason' => 'Unable to create video probe file.',
            ]);
        }

        try {
            $result = $this->withDeepfakeEnabled(fn () => $this->deepfakeCnnClassifier->classify(
                new UploadedFile($probePath, 'truthguard-model-health.mp4', 'video/mp4', null, true),
                'video'
            ));
        } finally {
            @unlink($probePath);
        }

        return array_merge($base, [
            'ok' => is_array($result) && ($result['status'] ?? null) === 'completed',
            'enabled' => (bool) config('services.deepfake_cnn.enabled', false),
            'loaded' => is_array($result) && ($result['status'] ?? null) === 'completed',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function checkCnnPaths(?string $modelPath, ?string $configPath): array
    {
        return [
            'ok' => is_string($modelPath) && is_file($modelPath)
                && is_string($configPath) && is_file($configPath)
                && is_file((string) $this->configuredPath('services.deepfake_cnn.script_path')),
            'model_file_exists' => is_string($modelPath) && is_file($modelPath),
            'config_file_exists' => is_string($configPath) && is_file($configPath),
            'script_file_exists' => is_file((string) $this->configuredPath('services.deepfake_cnn.script_path')),
        ];
    }

    private function makeVideoProbe(): ?string
    {
        $probePath = tempnam(sys_get_temp_dir(), 'truthguard-video-model-');

        if ($probePath === false) {
            return null;
        }

        $videoPath = $probePath.'.mp4';
        @unlink($probePath);

        $process = new Process([
            'ffmpeg',
            '-y',
            '-f',
            'lavfi',
            '-i',
            'color=c=black:s=224x224:d=1',
            '-pix_fmt',
            'yuv420p',
            $videoPath,
        ]);
        $process->setTimeout(20);
        $process->run();

        if ($process->isSuccessful() && is_file($videoPath)) {
            return $videoPath;
        }

        $python = (string) config('services.deepfake_cnn.python_binary', 'python');
        $script = <<<'PY'
import sys
import cv2
import numpy as np
path = sys.argv[1]
writer = cv2.VideoWriter(path, cv2.VideoWriter_fourcc(*"mp4v"), 4, (224, 224))
if not writer.isOpened():
    raise SystemExit(1)
frame = np.zeros((224, 224, 3), dtype=np.uint8)
for _ in range(4):
    writer.write(frame)
writer.release()
PY;

        $process = new Process([$python, '-c', $script, $videoPath]);
        $process->setTimeout(20);
        $process->run();

        return $process->isSuccessful() && is_file($videoPath) ? $videoPath : null;
    }

    private function configuredPath(string $key): ?string
    {
        $value = trim((string) config($key, ''));

        if ($value === '') {
            return null;
        }

        return $this->isAbsolutePath($value) ? $value : base_path($value);
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1;
    }

    private function withDeepfakeEnabled(callable $callback): mixed
    {
        $previous = config('services.deepfake_cnn.enabled', false);
        config()->set('services.deepfake_cnn.enabled', true);

        try {
            return $callback();
        } finally {
            config()->set('services.deepfake_cnn.enabled', $previous);
        }
    }
}
