<?php

namespace App\Services\Detections;

use App\Support\WindowsProcessEnvironment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/** Offline primary image-origin classifier. Video uses its separate detector. */
class AiOriginClassifier
{
    public const VERSION = 'broad-ai-origin-v1';

    public function classify(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        if (! $path || ! is_file($path) || ! is_readable($path)) {
            return $this->unavailable('missing_media');
        }
        $modelPath = (string) config('services.ai_origin.model_path', 'automation/models/ai-origin-broad');
        if (! preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/)/', $modelPath)) {
            $modelPath = base_path($modelPath);
        }
        foreach (['pytorch_model.pt', 'config.json', 'preprocessor_config.json', 'siglip_config.json', 'source.json'] as $required) {
            if (! is_file($modelPath.'/'.$required)) {
                return $this->unavailable('model_missing');
            }
        }
        $hash = hash_file('sha256', $path);
        $binary = (string) config('services.ai_origin.python_binary', '.venv-ai-origin/Scripts/python.exe');
        if (str_contains($binary, '/') && ! preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/)/', $binary)) {
            $binary = base_path($binary);
        }
        $process = new Process([$binary, base_path('automation/deepfake-models/broad_ai_origin_adapter.py')], base_path());
        $threads = (string) max(1, (int) config('services.ai_origin.cpu_threads', 4));
        $process->setEnv(['AI_ORIGIN_MODEL_PATH' => $modelPath, 'USE_TF' => '0', 'USE_FLAX' => '0',
            'AI_ORIGIN_CPU_THREADS' => $threads, 'OMP_NUM_THREADS' => $threads, 'MKL_NUM_THREADS' => $threads,
            'HF_HUB_OFFLINE' => '1', 'TRANSFORMERS_OFFLINE' => '1', 'HF_HUB_DISABLE_TELEMETRY' => '1']
            + WindowsProcessEnvironment::variables());
        $process->setTimeout((int) config('services.ai_origin.timeout', 120));
        $process->setInput(json_encode(['media_path' => $path], JSON_THROW_ON_ERROR));
        try {
            $process->run();
            $result = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);
            if (! $process->isSuccessful() || ! is_array($result) || ($result['status'] ?? null) !== 'completed'
                || ($result['input_sha256'] ?? '') !== $hash || ($result['label_mapping'] ?? []) !== ['real', 'fake']
                || ($result['output_contract']['activation'] ?? '') !== 'sigmoid'
                || ($result['output_contract']['positive_class'] ?? '') !== 'fake'
                || ($result['model_family'] ?? '') !== 'siglip2_dinov2'
                || ($result['output_contract']['validated_at_inference'] ?? false) !== true
                || ! is_numeric($result['fake_probability'] ?? null) || ! is_finite((float) $result['fake_probability'])
                || $result['fake_probability'] < 0 || $result['fake_probability'] > 1
                || ($result['threshold'] ?? null) !== 0.5
                || ($result['label'] ?? '') !== ($result['fake_probability'] > .5 ? 'fake' : 'real')) {
                Log::warning('AI-origin specialist returned an unusable response.', ['exit_code' => $process->getExitCode()]);

                return $this->unavailable('invalid_response');
            }
            $result['assessed_at'] = now()->utc()->toIso8601String();
            $result['task'] = 'AI-generation classification';
            $result['method_reference'] = 'https://huggingface.co/Bombek1/ai-image-detector-siglip-dinov2';
            // Provisional selective classification, not calibrated confidence.
            // Freeze before held-out checks; keep the model's published 0.5
            // decision threshold and every raw class prediction unchanged.
            $result['origin_policy'] = ['version' => 'origin-selective-v1', 'minimum_class_score' => .9,
                'inconclusive' => max($result['fake_probability'], 1 - $result['fake_probability']) < .9];

            return $result;
        } catch (\Throwable $error) {
            Log::warning('AI-origin specialist could not complete.', ['error_type' => $error::class]);

            return $this->unavailable('process_or_response_failed');
        }
    }

    private function unavailable(string $code): array
    {
        return ['status' => 'unavailable', 'media_type' => 'image', 'label' => null, 'fake_probability' => null,
            'failure_code' => $code, 'failure_reason' => 'The AI-generation classifier could not complete.',
            'adapter_version' => self::VERSION, 'assessed_at' => now()->utc()->toIso8601String()];
    }
}
