<?php

namespace Tests\Feature;

use App\Models\Detection;
use App\Models\User;
use App\Services\Detections\PipelineFeedback;
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

    public function test_media_correction_keeps_a_reviewable_detector_snapshot(): void
    {
        $user = User::factory()->create(['privacy_policy_accepted_at' => now()]);
        $detection = Detection::create(['user_id' => $user->id, 'source_kind' => 'upload',
            'media_checksum' => str_repeat('a', 64), 'processing_status' => 'completed', 'verdict' => 'review', 'fake_score' => 0,
            'fact_check_result' => ['media_assessment' => ['label' => 'fake', 'fake_probability' => .95,
                'model_version' => 'fixture-model', 'origin_policy' => ['minimum_class_score' => .9],
                'input_assessment' => ['quality_flags' => ['screenshot_or_composite_likely']]]]]);

        $this->actingAs($user)->post(route('detections.feedback', $detection), [
            'category' => 'media_false_positive',
        ])->assertRedirect();

        $details = json_decode(DB::table('detection_feedback')->value('details'), true);
        $this->assertSame('unverified_user_report', $details['review_status']);
        $this->assertSame('real', $details['reported_origin']);
        $this->assertSame(.95, $details['raw_ai_signal']);
        $this->assertSame(['screenshot_or_composite_likely'], $details['quality_flags']);
    }

    public function test_stage_feedback_keeps_a_note_and_snapshot_without_changing_the_result(): void
    {
        $user = User::factory()->create(['privacy_policy_accepted_at' => now()]);
        $result = ['primary_task' => 'claim', 'verdict' => 'UNVERIFIED', 'summary' => 'Original result.'];
        $detection = Detection::create(['user_id' => $user->id, 'source_kind' => 'text',
            'caption_text' => 'A claim', 'processing_status' => 'completed', 'verdict' => 'review',
            'fake_score' => 0, 'fact_check_result' => $result]);

        foreach (PipelineFeedback::options() as $category => $option) {
            $this->actingAs($user)->post(route('detections.feedback', $detection), [
                'category' => $category, 'note' => 'Please check the original document.',
            ])->assertRedirect()->assertSessionHasNoErrors();
            $details = json_decode(DB::table('detection_feedback')->where('category', $category)->value('details'), true);
            $this->assertSame($option['stage'], $details['stage']);
            $this->assertSame('unverified_user_report', $details['review_status']);
            $this->assertSame('Please check the original document.', $details['note']);
            $this->assertSame(hash('sha256', json_encode($result, JSON_THROW_ON_ERROR)), $details['report_sha256']);
        }
        $this->assertSame($result, $detection->fresh()->fact_check_result);
        $this->assertSame(6, DB::table('detection_feedback')->count());

        $admin = User::factory()->create(['is_admin' => true, 'privacy_policy_accepted_at' => now()]);
        $this->actingAs($admin)->getJson('/admin/api/fact-check-reviews')->assertOk()
            ->assertJsonPath('items.0.feedback.0.details.stage', 'extraction')
            ->assertJsonPath('items.0.feedback.0.details.review_status', 'unverified_user_report')
            ->assertJsonPath('items.0.feedback.0.details.note', 'Please check the original document.');
    }

    public function test_feedback_rejects_unknown_categories_and_oversized_notes(): void
    {
        $user = User::factory()->create(['privacy_policy_accepted_at' => now()]);
        $detection = Detection::create(['user_id' => $user->id, 'source_kind' => 'text',
            'processing_status' => 'completed', 'verdict' => 'review', 'fake_score' => 0]);
        $this->actingAs($user)->post(route('detections.feedback', $detection), [
            'category' => 'make_it_true',
        ])->assertSessionHasErrors('category');
        $this->actingAs($user)->post(route('detections.feedback', $detection), [
            'category' => 'missing_evidence', 'note' => str_repeat('x', 1001),
        ])->assertSessionHasErrors('note');
        $this->assertSame(0, DB::table('detection_feedback')->count());
    }
}
