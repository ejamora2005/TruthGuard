<?php

namespace App\Console\Commands;

use App\Models\Detection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExportPipelineReview extends Command
{
    protected $signature = 'truthguard:export-pipeline-review {output : New JSONL report archive}
        {--detection=* : Saved detection IDs to include}
        {--flagged : Include only reports with user feedback}
        {--take=100 : Maximum number of saved reports to export}';

    protected $description = 'Export saved factual/context reports and unverified feedback for stage review without API calls';

    public function handle(): int
    {
        $ids = $this->option('detection');
        $take = (string) $this->option('take');
        $output = (string) $this->argument('output');
        if (($ids === [] && ! $this->option('flagged')) || ! ctype_digit($take) || (int) $take < 1
            || (int) $take > 1000 || array_filter($ids, fn ($id) => ! ctype_digit((string) $id) || (int) $id < 1)) {
            $this->error('Choose --detection=ID or --flagged, and a --take value between 1 and 1000.');

            return self::FAILURE;
        }
        if (file_exists($output) || ! is_dir(dirname($output)) || ! is_writable(dirname($output))) {
            $this->error('Choose a new output filename in an existing writable directory.');

            return self::FAILURE;
        }
        $query = Detection::query()->where('processing_status', 'completed')->orderBy('id');
        if ($ids !== []) {
            $query->whereIn('id', $ids);
        }
        if ($this->option('flagged')) {
            $query->whereIn('id', DB::table('detection_feedback')->select('detection_id'));
        }
        $rows = [];
        foreach ($query->limit((int) $take)->get() as $detection) {
            $result = $detection->fact_check_result;
            if (! is_array($result) || ! in_array($result['primary_task'] ?? '', ['claim', 'context'], true)) {
                continue;
            }
            $feedback = DB::table('detection_feedback')->where('detection_id', $detection->id)->orderBy('id')->get()
                ->map(fn ($row) => [
                    'category' => $row->category,
                    'details' => is_string($row->details ?? null) ? json_decode($row->details, true) : null,
                    'reported_at' => $row->updated_at,
                ])->all();
            $rows[] = [
                'case_id' => 'detection-'.$detection->id,
                'captured_at' => now()->utc()->toIso8601String(),
                'evaluation_cutoff' => $result['evidence_cutoff'] ?? null,
                'input' => ['text' => $detection->caption_text, 'notes' => $detection->notes,
                    'media_path' => $detection->media_path, 'source_url' => $detection->source_url,
                    'media_checksum' => $detection->media_checksum],
                'answer_origin' => 'saved_report_not_fresh_research',
                'feedback' => $feedback,
                'result' => $result,
            ];
        }
        if ($rows === []) {
            $this->error('No completed factual/context reports matched the selection.');

            return self::FAILURE;
        }
        $handle = fopen($output, 'x');
        if ($handle === false) {
            $this->error('Could not create the archive.');

            return self::FAILURE;
        }
        try {
            foreach ($rows as $row) {
                fwrite($handle, json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n");
            }
        } finally {
            fclose($handle);
        }
        $this->info(count($rows).' saved reports exported. Feedback is unverified; no accuracy has been measured.');

        return self::SUCCESS;
    }
}
