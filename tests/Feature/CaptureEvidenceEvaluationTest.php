<?php

namespace Tests\Feature;

use App\Services\Detections\EvidenceVerificationService;
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
}
