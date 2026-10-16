<?php

namespace App\Console\Commands;

use App\Services\Detections\EvidenceVerdictCalibration;
use App\Services\Detections\EvidenceVerificationService;
use App\Services\Detections\SubmissionTask;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;

class CaptureEvidenceEvaluation extends Command
{
    protected $signature = 'truthguard:capture-evidence {cases : JSONL inputs; gold labels are never sent to the analyzer} {output : New JSONL predictions file}';

    protected $description = 'Run fresh factual/context checks and archive source records without changing saved detections';

    public function handle(EvidenceVerificationService $service): int
    {
        $output = (string) $this->argument('output');
        if (file_exists($output) || file_exists($output.'.reports.jsonl')) {
            $this->error('Use a new output filename; frozen predictions cannot be overwritten.');

            return self::FAILURE;
        }
        $cases = array_values(array_filter(array_map('trim', file((string) $this->argument('cases')))));
        $seen = [];
        $inputs = [];
        if ($cases === []) {
            $this->error('No evaluation cases were supplied.');

            return self::FAILURE;
        }
        // Validate every input before making paid requests or writing a partial run.
        foreach ($cases as $line) {
            $case = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
            $id = $case['case_id'] ?? '';
            if (! is_string($id) || $id === '' || isset($seen[$id]) || ! is_string($case['text'] ?? null)
                || ! in_array($case['task'] ?? '', ['factual', 'claim', 'context'], true)
                || ! is_string($case['evaluation_cutoff'] ?? null)
                || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/', $case['evaluation_cutoff'])) {
                $this->error('Every case requires a unique ID, text, factual/context task and explicit ISO UTC cutoff.');

                return self::FAILURE;
            }
            $seen[$id] = true;
            $inputs[] = $case;
        }
        $identity = EvidenceVerdictCalibration::identity();
        $handle = fopen($output, 'x');
        $archive = fopen($output.'.reports.jsonl', 'x');
        try {
            foreach ($inputs as $case) {
                $task = $case['task'] === 'factual' ? 'claim' : $case['task'];
                $file = null;
                if (! empty($case['media_path'])) {
                    $path = realpath($case['media_path']);
                    if (! $path || ! hash_equals($case['media_sha256'] ?? '', hash_file('sha256', $path))) {
                        throw new \UnexpectedValueException('Original media hash did not match the evaluation input.');
                    }
                    $file = new UploadedFile($path, basename($path), mime_content_type($path), null, true);
                }
                $context = ['caption_text' => $case['text'], 'source_url' => $case['source_url'] ?? null,
                    'media_type' => $file ? 'image' : 'text', 'evidence_cutoff' => $case['evaluation_cutoff'], 'analysis_task' => $task];
                $context['resolved_request'] = SubmissionTask::resolve($context);
                $started = microtime(true);
                try {
                    // Only submitted content enters the pipeline, never label/reviewer fields.
                    $result = $service->apply(['signals' => []], $context, $file)['fact_check_result'];
                    $answer = $result;
                    if ($result['primary_task'] !== $task) {
                        $answer = collect($result['secondary_assessments'])->firstWhere('primary_task', $task)
                            ?? ['verdict' => 'ANALYSIS_UNAVAILABLE'];
                    }
                } catch (\Throwable $error) {
                    $answer = ['verdict' => 'ANALYSIS_UNAVAILABLE'];
                    $result = ['verdict' => 'ANALYSIS_UNAVAILABLE', 'error_type' => $error::class];
                }
                $prediction = ['case_id' => $case['case_id'], 'task' => $task, 'verdict' => $answer['verdict'],
                    'evaluation_cutoff' => $case['evaluation_cutoff'], 'pipeline_identity' => $identity,
                    'input_sha256' => hash('sha256', $case['text']), 'media_sha256' => $case['media_sha256'] ?? null,
                    'source_url' => $context['source_url'], 'latency_ms' => (int) round((microtime(true) - $started) * 1000)];
                fwrite($handle, json_encode($prediction, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n");
                fwrite($archive, json_encode(['case_id' => $case['case_id'], 'result' => $result], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n");
                fflush($handle);
                fflush($archive);
                $this->line($case['case_id'].': '.$answer['verdict']);
            }
        } finally {
            fclose($handle);
            fclose($archive);
        }
        $this->info('Fresh checks archived. These are diagnostic predictions, not independently reviewed accuracy or calibration.');

        return self::SUCCESS;
    }
}
