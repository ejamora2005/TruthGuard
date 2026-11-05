<?php

namespace Tests\Feature;

use App\Models\Detection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DetectionFeedbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_flag_a_specific_result_problem_once(): void
    {
        $user = User::factory()->create(['privacy_policy_accepted_at' => now()]);
        $detection = Detection::create(['user_id' => $user->id, 'source_kind' => 'text',
            'caption_text' => 'A claim', 'processing_status' => 'completed', 'verdict' => 'review', 'fake_score' => 0]);

        $this->actingAs($user)->post(route('detections.feedback', $detection), [
            'category' => 'wrong_interpretation',
        ])->assertRedirect();

        $this->assertSame(1, DB::table('detection_feedback')->count());
    }

    public function test_user_cannot_flag_another_users_report(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create(['privacy_policy_accepted_at' => now()]);
        $detection = Detection::create(['user_id' => $owner->id, 'source_kind' => 'text',
            'caption_text' => 'A claim', 'processing_status' => 'completed', 'verdict' => 'review', 'fake_score' => 0]);

        $this->actingAs($other)->post(route('detections.feedback', $detection), [
            'category' => 'verdict_review',
        ])->assertForbidden();
    }
}
