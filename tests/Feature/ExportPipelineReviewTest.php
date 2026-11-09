<?php

namespace Tests\Feature;

use App\Models\Detection;
use App\Models\User;
use App\Services\Detections\EvidenceVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ExportPipelineReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_saved_report_and_feedback_are_exported_without_research_or_mutation(): void
    {
        $user = User::factory()->create();
        $result = ['primary_task' => 'claim', 'verdict' => 'UNVERIFIED', 'summary' => 'A synthetic result.'];
        $detection = Detection::create(['user_id' => $user->id, 'source_kind' => 'text',
            'caption_text' => 'Synthetic claim', 'processing_status' => 'completed',
            'verdict' => 'review', 'fake_score' => 0, 'fact_check_result' => $result]);
        DB::table('detection_feedback')->insert(['detection_id' => $detection->id, 'user_id' => $user->id,
            'category' => 'incorrect_extraction', 'details' => json_encode(['review_status' => 'unverified_user_report']),
            'created_at' => now(), 'updated_at' => now()]);
        $this->mock(EvidenceVerificationService::class)->shouldNotReceive('apply');
        $output = sys_get_temp_dir().'/tg-pipeline-'.bin2hex(random_bytes(8)).'.jsonl';
        try {
            $this->artisan('truthguard:export-pipeline-review', ['output' => $output, '--flagged' => true])->assertExitCode(0);
            $row = json_decode(trim(file_get_contents($output)), true);
            $this->assertSame('detection-'.$detection->id, $row['case_id']);
            $this->assertSame($result, $row['result']);
            $this->assertSame('unverified_user_report', $row['feedback'][0]['details']['review_status']);
            $this->assertSame('saved_report_not_fresh_research', $row['answer_origin']);
            $this->assertArrayNotHasKey('human_reviewed', $row);
            $this->assertSame($result, $detection->fresh()->fact_check_result);
            $before = hash_file('sha256', $output);
            $this->artisan('truthguard:export-pipeline-review', ['output' => $output, '--flagged' => true])->assertExitCode(1);
            $this->assertSame($before, hash_file('sha256', $output));
        } finally {
            if (is_file($output)) {
                unlink($output);
            }
        }
    }

    public function test_selection_is_explicit_and_does_not_silently_export_all_users(): void
    {
        $output = sys_get_temp_dir().'/tg-pipeline-'.bin2hex(random_bytes(8)).'.jsonl';
        $this->artisan('truthguard:export-pipeline-review', ['output' => $output])->assertExitCode(1);
        $this->artisan('truthguard:export-pipeline-review', ['output' => $output,
            '--detection' => ['1'], '--take' => '0'])->assertExitCode(1);
        $this->artisan('truthguard:export-pipeline-review', ['output' => $output,
            '--detection' => ['invalid']])->assertExitCode(1);
        $this->assertFileDoesNotExist($output);
    }

    public function test_media_only_and_incomplete_reports_are_not_fact_check_benchmark_cases(): void
    {
        $user = User::factory()->create();
        $media = Detection::create(['user_id' => $user->id, 'source_kind' => 'upload',
            'processing_status' => 'completed', 'verdict' => 'review', 'fake_score' => 0,
            'fact_check_result' => ['primary_task' => 'media', 'verdict' => 'LIKELY_AI_GENERATED']]);
        $output = sys_get_temp_dir().'/tg-pipeline-'.bin2hex(random_bytes(8)).'.jsonl';
        $this->artisan('truthguard:export-pipeline-review', ['output' => $output,
            '--detection' => [(string) $media->id]])->assertExitCode(1);
        $this->assertFileDoesNotExist($output);
    }
}
