<?php

namespace Tests\Feature;

use App\Models\Detection;
use App\Models\User;
use App\Services\Detections\FactCheckQueueHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FactCheckQueueHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_stale_evidence_work_for_operations(): void
    {
        config()->set('fact_check.pending_stale_minutes', 10);
        $user = User::factory()->create();
        $detection = Detection::create([
            'user_id' => $user->id, 'source_kind' => 'text', 'caption_text' => 'Queued claim',
            'processing_status' => 'queued', 'verdict' => 'review', 'fake_score' => 0,
        ]);
        $detection->forceFill(['updated_at' => now()->subMinutes(20)])->saveQuietly();
        DB::table('jobs')->insert([
            'queue' => 'evidence', 'payload' => '{}', 'attempts' => 0,
            'reserved_at' => null, 'available_at' => now()->subMinutes(20)->timestamp,
            'created_at' => now()->subMinutes(20)->timestamp,
        ]);

        $health = app(FactCheckQueueHealth::class)->summary();
        $this->assertSame('attention_required', $health['status']);
        $this->assertSame(1, $health['pending_evidence_jobs']);
        $this->assertSame(1, $health['stalled_assessments']);
    }
}
