<?php

namespace Tests\Feature;

use App\Models\Detection;
use App\Models\User;
use App\Services\Detections\ActiveDetectionManager;
use App\Services\Detections\AnalysisStopped;
use App\Services\Detections\Evidence\ReportApiBudget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResearchCompletionLivenessTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_long_research_has_a_heartbeat_and_is_not_expired_by_submission_age(): void
    {
        config()->set('fact_check.unrestricted_research', true);
        $user = User::factory()->create();
        $record = Detection::create(['user_id' => $user->id, 'source_kind' => 'text', 'media_type' => 'text',
            'caption_text' => 'Fictional library opening.', 'processing_status' => 'processing', 'signals' => [], 'fake_score' => 0, 'verdict' => 'review']);
        $record->forceFill(['created_at' => now()->subHours(2), 'updated_at' => now()->subMinutes(5)])->saveQuietly();
        $budget = app(ReportApiBudget::class);
        $budget->trackDetection($record);
        $budget->assertActive();
        $this->assertTrue($record->fresh()->updated_at->greaterThan(now()->subMinute()));
        $this->assertSame(0, app(ActiveDetectionManager::class)->expireStaleForUser($user->id));
        $this->assertSame('processing', $record->fresh()->processing_status);

        $record->forceFill(['processing_status' => 'failed', 'signals' => ['analysis_cancellation' => ['reason' => 'user_stopped']]])->saveQuietly();
        $this->expectException(AnalysisStopped::class);
        $budget->assertActive();
    }

    public function test_unrestricted_mode_still_closes_abandoned_checks(): void
    {
        config()->set('fact_check.unrestricted_research', true);
        $user = User::factory()->create();
        $record = Detection::create(['user_id' => $user->id, 'source_kind' => 'text', 'media_type' => 'text',
            'processing_status' => 'processing', 'caption_text' => 'Fictional library opening.', 'signals' => [], 'fake_score' => 0, 'verdict' => 'review']);
        $record->forceFill(['created_at' => now()->subHours(2), 'updated_at' => now()->subMinutes(20)])->saveQuietly();
        $this->assertSame(1, app(ActiveDetectionManager::class)->expireStaleForUser($user->id));
        $this->assertSame('failed', $record->fresh()->processing_status);
    }
}
