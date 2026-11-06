<?php

namespace App\Console\Commands;

use App\Models\Detection;
use App\Services\Detections\AiOriginClassifier;
use App\Services\Detections\AssessmentScore;
use Illuminate\Console\Command;

class CheckMediaCalibration extends Command
{
    protected $signature = 'truthguard:calibration:check {--detection= : Inspect one saved report without changing it}';
    protected $description = 'Read-only calibration deployment checks; prints no credentials or submitted content';

    public function handle(): int
    {
        $resolve = fn (string $path) => preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/)/', $path) ? $path : base_path($path);
        $path = $resolve((string) config('services.media_calibration.path', ''));
        $directory = $resolve((string) config('services.ai_origin.model_path', ''));
        $artifact = is_file($path) && filesize($path) <= 1048576 ? json_decode(file_get_contents($path), true) : null;
        $expected = (string) config('services.media_calibration.sha256', '');
        $identity = ['adapter_version' => AiOriginClassifier::VERSION];
        foreach (['model_version' => 'pytorch_model.pt', 'config_sha256' => 'config.json', 'processor_sha256' => 'preprocessor_config.json'] as $key => $file) {
            $identity[$key] = is_file($directory.'/'.$file)
                ? ($key === 'model_version' ? 'sha256:' : '').hash_file('sha256', $directory.'/'.$file) : null;
        }
        $matches = [];
        foreach ($identity as $key => $value) $matches[$key] = $value !== null && $value === ($artifact['model_identity'][$key] ?? null);
        $probe = $identity + ['status' => 'completed', 'media_type' => 'image', 'label' => 'fake',
            'fake_probability' => .9, 'label_mapping' => ['real', 'fake'],
            'output_contract' => ['activation' => 'sigmoid', 'positive_class' => 'fake', 'binary_exclusive' => true]];
        $score = AssessmentScore::media($probe);
        $output = ['environment' => app()->environment(), 'runtime' => PHP_OS_FAMILY,
            'primary_detector_enabled' => (bool) config('services.ai_origin.enabled'),
            'calibration_enabled' => (bool) config('services.media_calibration.enabled'),
            'artifact_hash_matches' => is_file($path) && preg_match('/^[a-f0-9]{64}$/', $expected) === 1 && hash_equals($expected, hash_file('sha256', $path)),
            'model_identity_matches' => $matches, 'application_probe_validation' => $score['validation'] ?? null,
            'scope' => $artifact['population']['scope'] ?? null,
            'accuracy_note' => 'Configuration checks do not measure classification accuracy or certify other containers.'];
        if ($id = $this->option('detection')) {
            if (! ctype_digit((string) $id) || (int) $id < 1) {
                $this->error('The detection ID must be a positive integer.');
                return self::FAILURE;
            }
            try {
                $record = Detection::find((int) $id);
                $result = $record?->fact_check_result ?? [];
                $media = $result['media_assessment'] ?? [];
                $output['saved_report'] = $record ? ['id' => $record->id, 'task' => $result['primary_task'] ?? null,
                    'verdict' => $result['verdict'] ?? null, 'recorded_score_validation' => $result['score']['validation'] ?? null,
                    'current_mapping_validation' => AssessmentScore::media($media)['validation'] ?? null,
                    'current_badge' => AssessmentScore::badge($result)] : ['found' => false];
            } catch (\Throwable $error) {
                $output['saved_report'] = ['read_failed' => true, 'error_type' => $error::class];
            }
        }
        $output['configuration_passed'] = $output['primary_detector_enabled'] && $output['calibration_enabled']
            && $output['artifact_hash_matches'] && ! in_array(false, $matches, true)
            && in_array($output['application_probe_validation'], ['calibrated', 'calibrated_benchmark'], true);
        $this->line(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return $output['configuration_passed'] ? self::SUCCESS : self::FAILURE;
    }
}
