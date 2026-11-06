<?php

namespace App\Console\Commands;

use App\Services\Detections\Evidence\ReportApiBudget;
use App\Services\Detections\EvidenceVerdictCalibration;
use App\Services\Detections\EvidenceVerificationService;
use App\Services\Detections\SubmissionTask;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;

class CaptureEvidenceEvaluation extends Command
{
    protected $signature = 'truthguard:capture-evidence {cases : JSONL inputs; gold labels are never sent to the analyzer} {output : New JSONL predictions file}
        {--take= : Run only the first N inputs as a pilot}
        {--dry-run : Validate all inputs without research requests or output files}
        {--force-research : Bypass saved-answer shortcuts and request fresh sources}';

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
                || ! in_array($case['cutoff_policy'] ?? 'fixed', ['fixed', 'capture_time'], true)
                || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/', $case['evaluation_cutoff'])) {
                $this->error('Every case requires a unique ID, text, factual/context task and explicit ISO UTC cutoff.');

                return self::FAILURE;
            }
            $seen[$id] = true;
            try {
                $date = date_parse($case['evaluation_cutoff']);
                if ($date['error_count'] || $date['warning_count'] || Carbon::parse($case['evaluation_cutoff'])->gt(now())) {
                    throw new \UnexpectedValueException('Invalid or future evaluation cutoff.');
                }
                if (! empty($case['media_path'])) {
                    $path = realpath($case['media_path']);
                    if (! $path || ! is_file($path) || ! is_string($case['media_sha256'] ?? null)
                        || ! hash_equals($case['media_sha256'], hash_file('sha256', $path))) {
                        throw new \UnexpectedValueException('Original media hash did not match the evaluation input.');
                    }
                    $case['media_path'] = $path;
                }
            } catch (\Throwable $error) {
                $this->error($id.': '.$error->getMessage());

                return self::FAILURE;
            }
            $inputs[] = $case;
        }
        if ($this->option('take') !== null) {
            if (! ctype_digit((string) $this->option('take')) || (int) $this->option('take') < 1) {
                $this->error('--take must be a positive case count.');

                return self::FAILURE;
            }
            $inputs = array_slice($inputs, 0, (int) $this->option('take'));
        }
        if ($this->option('dry-run')) {
            $this->info(count($inputs).' inputs validated. No API calls or predictions written.');

            return self::SUCCESS;
        }
        if (! is_dir(dirname($output)) || ! is_writable(dirname($output))) {
            $this->error('The output directory must already exist and be writable.');

            return self::FAILURE;
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
                $cutoffPolicy = $case['cutoff_policy'] ?? 'fixed';
                $effectiveCutoff = $cutoffPolicy === 'capture_time' ? now()->utc()->toIso8601String() : $case['evaluation_cutoff'];
                $context = ['caption_text' => $case['text'], 'source_url' => $case['source_url'] ?? null,
                    'media_type' => $file ? 'image' : 'text', 'evidence_cutoff' => $effectiveCutoff, 'analysis_task' => $task];
                if ($this->option('force-research')) {
                    $context['force_refresh'] = true;
                }
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
                $usage = data_get($result, 'diagnostics.api_usage');
                $budget = app(ReportApiBudget::class)->snapshot();
                $origin = match (true) {
                    (bool) data_get($result, 'diagnostics.known_fact_check') => 'saved_answer',
                    ! empty(data_get($result, 'diagnostics.reviewed_answers')) => 'saved_answer_components',
                    (bool) data_get($result, 'diagnostics.result_cache_hit') => 'result_cache',
                    isset($result['exact_fact_check']) => 'published_review',
                    default => 'research',
                };
                $prediction = ['case_id' => $case['case_id'], 'task' => $task, 'verdict' => $answer['verdict'],
                    'evaluation_cutoff' => $effectiveCutoff, 'pipeline_identity' => $identity,
                    'cutoff_policy' => $cutoffPolicy, 'declared_evaluation_cutoff' => $case['evaluation_cutoff'],
                    'input_sha256' => hash('sha256', $case['text']), 'media_sha256' => $case['media_sha256'] ?? null,
                    'source_url' => $context['source_url'], 'latency_ms' => (int) round((microtime(true) - $started) * 1000),
                    'answer_origin' => $origin, 'force_research' => (bool) $this->option('force-research'),
                    'api_usage' => $usage, 'api_budget' => $budget,
                    'source_count' => count($answer['sources'] ?? []),
                    'outcome_reason' => data_get($result, 'diagnostics.outcome_reason'),
                    'runtime_profile' => [
                        'model' => config('services.openai.model'),
                        'planning_model' => config('services.openai.planning_model'),
                        'extraction_model' => config('services.openai.extraction_model'),
                        'web_search_model' => config('fact_check.web_search.model'),
                        'max_claims' => config('fact_check.max_claims'),
                        'max_model_calls' => config('fact_check.max_model_calls'),
                        'report_budget' => config('fact_check.report_budget'),
                    ]];
                fwrite($handle, json_encode($prediction, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n");
                fwrite($archive, json_encode(['case_id' => $case['case_id'], 'evaluation_cutoff' => $effectiveCutoff,
                    'captured_at' => now()->utc()->toIso8601String(), 'result' => $result], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n");
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
