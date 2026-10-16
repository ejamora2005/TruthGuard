<?php

namespace Tests\Feature;

use App\Models\Detection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PendingReportJsonTest extends TestCase
{
    use RefreshDatabase;

    public function test_polling_does_not_return_a_stale_completed_report_while_new_work_is_pending_or_failed(): void
    {
        $user = User::factory()->create();
        $detection = Detection::create([
            'user_id' => $user->id,
            'source_kind' => 'upload',
            'media_type' => 'image',
            'processing_status' => 'queued',
            'verdict' => 'review',
            'fake_score' => 0,
            'signals' => ['analysis_progress' => [['label' => 'Checking dates and official records', 'source_domains' => ['www.comelec.gov.ph']]]],
            'fact_check_result' => ['assessment_label' => 'Stale answer', 'sources' => [['id' => 'old-source']]],
        ]);

        foreach (['queued', 'processing', 'failed'] as $status) {
            $detection->forceFill(['processing_status' => $status])->save();
            $this->actingAs($user)
                ->getJson(route('detections.result', $detection))
                ->assertOk()
                ->assertJsonPath('processing_status', $status)
                ->assertJsonPath('source_domains', ['www.comelec.gov.ph'])
                ->assertJsonMissing(['assessment_label' => 'Stale answer'])
                ->assertJsonMissing(['id' => 'old-source']);
        }
    }
}
