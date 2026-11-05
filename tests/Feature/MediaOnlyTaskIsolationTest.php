<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeDetectionEvidence;
use App\Models\Detection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaOnlyTaskIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_optional_visible_claim_action_creates_a_separate_fact_check(): void
    {
        Storage::fake('public');
        Queue::fake();
        config()->set('fact_check.queue_enabled', true);
        $user = User::factory()->create(['privacy_policy_accepted_at' => now()]);
        Storage::disk('public')->put('detections/original.jpg', 'image-bytes');
        $detection = Detection::create(['user_id' => $user->id, 'source_kind' => 'upload',
            'media_path' => 'detections/original.jpg', 'media_type' => 'image', 'processing_status' => 'completed',
            'verdict' => 'review', 'fake_score' => 0, 'fact_check_result' => [
                'primary_task' => 'media', 'verdict' => 'LIKELY_AI_GENERATED',
                'media_claim_notice' => ['status' => 'claim_detected', 'text' => 'Shoot-to-kill kay Sen. Bato, ihahain?!'],
            ]]);

        $this->actingAs($user)->post(route('detections.fact-check-media-claim', $detection))->assertRedirect();

        $new = Detection::query()->whereKeyNot($detection->id)->sole();
        $this->assertSame('Shoot-to-kill kay Sen. Bato, ihahain?!', $new->caption_text);
        $this->assertSame('queued', $new->processing_status);
        $this->assertNotSame($detection->media_path, $new->media_path);
        Queue::assertPushed(AnalyzeDetectionEvidence::class, fn ($job) => $job->detectionId === $new->id
            && ($job->context['analysis_task'] ?? null) === 'claim');
    }
}
