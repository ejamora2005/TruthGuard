<?php

namespace Tests\Feature;

use App\Services\Detections\EvidenceVerificationService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CaptureEvidenceEvaluationTest extends TestCase
{
    public function test_capture_runs_fresh_content_without_leaking_gold_labels_or_overwriting_predictions(): void
    {
        $input = tempnam(sys_get_temp_dir(), 'tg-cases-');
        $output = $input.'.predictions.jsonl';
        $claim = 'The fictional library opened in 2024.';
        file_put_contents($input, json_encode(['case_id' => 'independent-case', 'task' => 'factual', 'text' => $claim,
            'evaluation_cutoff' => '2026-10-15T00:00:00Z', 'label' => 'TRUE', 'review_basis' => 'PRIVATE_REVIEW_SENTINEL'])."\n");
        $this->mock(EvidenceVerificationService::class)->shouldReceive('apply')->once()
            ->withArgs(function ($analysis, $context, $file) use ($claim) {
                $this->assertSame($claim, $context['caption_text']);
                $this->assertStringNotContainsString('PRIVATE_REVIEW_SENTINEL', json_encode($context));
                $this->assertArrayNotHasKey('label', $context);
                $this->assertNull($file);

                return true;
            })->andReturn(['fact_check_result' => ['primary_task' => 'claim', 'verdict' => 'FALSE']]);
        try {
            $this->artisan('truthguard:capture-evidence', ['cases' => $input, 'output' => $output])->assertExitCode(0);
            $prediction = json_decode(trim(file_get_contents($output)), true);
            $this->assertSame('FALSE', $prediction['verdict']);
            $this->assertSame(hash('sha256', $claim), $prediction['input_sha256']);
            $this->assertFileExists($output.'.reports.jsonl');
            $before = hash_file('sha256', $output);
            $this->artisan('truthguard:capture-evidence', ['cases' => $input, 'output' => $output])->assertExitCode(1);
            $this->assertSame($before, hash_file('sha256', $output));
        } finally {
            foreach ([$input, $output, $output.'.reports.jsonl'] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    public function test_all_media_hashes_are_preflighted_before_any_paid_analysis(): void
    {
        $input = tempnam(sys_get_temp_dir(), 'tg-preflight-');
        $output = $input.'.predictions.jsonl';
        $first = ['case_id' => 'valid', 'task' => 'factual', 'text' => 'A valid assertion.',
            'evaluation_cutoff' => '2026-10-15T00:00:00Z'];
        $second = array_replace($first, ['case_id' => 'bad-media', 'media_path' => __FILE__, 'media_sha256' => str_repeat('0', 64)]);
        file_put_contents($input, json_encode($first)."\n".json_encode($second)."\n");
        $this->mock(EvidenceVerificationService::class)->shouldNotReceive('apply');
        try {
            $this->artisan('truthguard:capture-evidence', ['cases' => $input, 'output' => $output])->assertExitCode(1);
            $this->assertFileDoesNotExist($output);
        } finally {
            unlink($input);
        }
    }

    public function test_pilot_capture_records_usage_origin_and_force_research_without_label_leakage(): void
    {
        $input = tempnam(sys_get_temp_dir(), 'tg-pilot-');
        $output = $input.'.predictions.jsonl';
        $case = ['case_id' => 'one', 'task' => 'factual', 'text' => 'The library opened in 2024.',
            'evaluation_cutoff' => '2026-10-15T00:00:00Z', 'expected_verdict' => 'FALSE', 'evidence_urls' => ['PRIVATE_REFERENCE']];
        file_put_contents($input, json_encode($case)."\n".json_encode(array_replace($case, ['case_id' => 'two']))."\n");
        $this->mock(EvidenceVerificationService::class)->shouldReceive('apply')->once()
            ->withArgs(function ($analysis, $context, $file) {
                $this->assertTrue($context['force_refresh']);
                $this->assertStringNotContainsString('PRIVATE_REFERENCE', json_encode($context));
                $this->assertArrayNotHasKey('expected_verdict', $context);

                return true;
            })->andReturn(['fact_check_result' => ['primary_task' => 'claim', 'verdict' => 'FALSE', 'sources' => [['id' => 'one']],
                'diagnostics' => ['reviewed_answers' => ['c1' => ['reviewed_claim_id' => 7]],
                    'api_usage' => ['total_tokens' => 123]]]]);
        try {
            $this->artisan('truthguard:capture-evidence', ['cases' => $input, 'output' => $output,
                '--take' => '1', '--force-research' => true])->assertExitCode(0);
            $rows = file($output, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $this->assertCount(1, $rows);
            $prediction = json_decode($rows[0], true);
            $this->assertSame('saved_answer_components', $prediction['answer_origin']);
            $this->assertSame(123, $prediction['api_usage']['total_tokens']);
            $this->assertSame(1, $prediction['source_count']);
            $this->assertArrayHasKey('runtime_profile', $prediction);
        } finally {
            foreach ([$input, $output, $output.'.reports.jsonl'] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    public function test_dry_run_validates_without_writing_or_calling_the_pipeline(): void
    {
        $input = tempnam(sys_get_temp_dir(), 'tg-dry-');
        $output = $input.'.predictions.jsonl';
        file_put_contents($input, json_encode(['case_id' => 'one', 'task' => 'factual', 'text' => 'A claim.',
            'evaluation_cutoff' => '2026-10-15T00:00:00Z'])."\n");
        $this->mock(EvidenceVerificationService::class)->shouldNotReceive('apply');
        try {
            $this->artisan('truthguard:capture-evidence', ['cases' => $input, 'output' => $output, '--dry-run' => true])->assertExitCode(0);
            $this->assertFileDoesNotExist($output);
            $this->assertFileDoesNotExist($output.'.reports.jsonl');
        } finally {
            unlink($input);
        }
    }

    public function test_live_capture_records_the_actual_cutoff_without_silently_reusing_an_earlier_timestamp(): void
    {
        $this->travelTo(Carbon::parse('2026-11-06T09:00:00Z'));
        $input = tempnam(sys_get_temp_dir(), 'tg-live-cutoff-');
        $output = $input.'.predictions.jsonl';
        $declared = '2026-11-06T06:00:00Z';
        file_put_contents($input, json_encode(['case_id' => 'live', 'task' => 'factual', 'text' => 'A current check.',
            'cutoff_policy' => 'capture_time', 'evaluation_cutoff' => $declared])."\n");
        $this->mock(EvidenceVerificationService::class)->shouldReceive('apply')->once()
            ->withArgs(function ($analysis, $context, $file) {
                $this->assertSame('2026-11-06T09:00:00+00:00', $context['evidence_cutoff']);

                return true;
            })->andReturn(['fact_check_result' => ['primary_task' => 'claim', 'verdict' => 'UNVERIFIED']]);
        try {
            $this->artisan('truthguard:capture-evidence', ['cases' => $input, 'output' => $output])->assertExitCode(0);
            $prediction = json_decode(file_get_contents($output), true);
            $archive = json_decode(file_get_contents($output.'.reports.jsonl'), true);
            $this->assertSame('capture_time', $prediction['cutoff_policy']);
            $this->assertSame($declared, $prediction['declared_evaluation_cutoff']);
            $this->assertSame($prediction['evaluation_cutoff'], $archive['evaluation_cutoff']);
            $this->assertSame($archive['captured_at'], $archive['evaluation_cutoff']);
        } finally {
            foreach ([$input, $output, $output.'.reports.jsonl'] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }
}
