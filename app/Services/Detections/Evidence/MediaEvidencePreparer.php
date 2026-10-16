<?php

namespace App\Services\Detections\Evidence;

use App\Support\WindowsProcessEnvironment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Symfony\Component\Process\Process;

class MediaEvidencePreparer
{
    public function prepare(UploadedFile $file, string $kind): array
    {
        $unavailable = ['inputs' => [], 'status' => 'media_preparation_unavailable'];
        try {
            $process = new Process([
                (string) config('fact_check.media.python_binary', config('services.deepfake_cnn.python_binary', 'python')),
                base_path('automation/evidence/prepare_media.py'),
            ], base_path());
            $process->setEnv(WindowsProcessEnvironment::variables());
            $configuredBytes = (int) config('services.openai.image_max_bytes', 4194304);
            $maxBytes = max(1, $configuredBytes > 0 ? $configuredBytes : 4194304);
            $process->setInput(json_encode(['path' => $file->getRealPath(), 'kind' => $kind, 'max_bytes' => $maxBytes], JSON_THROW_ON_ERROR));
            $process->setTimeout(max(1, (int) config('fact_check.media.timeout', 30)))->run();
            if (! $process->isSuccessful()) {
                return $unavailable;
            }
            $data = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($data)) {
                return $unavailable;
            }
            $inputs = [];
            foreach (array_slice($data['frames'] ?? [], 0, 3) as $frame) {
                $encoded = $frame['data'] ?? '';
                if (! is_string($encoded) || strlen($encoded) > (int) ceil($maxBytes * 4 / 3) + 4) {
                    return $unavailable;
                }
                $bytes = base64_decode($encoded, true);
                if ($bytes === false || strlen($bytes) > $maxBytes || ! str_starts_with($bytes, "\xff\xd8\xff")) {
                    return $unavailable;
                }
                $inputs[] = ['type' => 'input_image', 'image_url' => 'data:image/jpeg;base64,'.$encoded];
            }

            return Arr::only($data, ['status', 'reason', 'resized', 'duration_seconds', 'sample_times_seconds', 'original_dimensions', 'prepared_dimensions']) + ['inputs' => $inputs];
        } catch (\Throwable) {
            return $unavailable;
        }
    }
}
