<?php

namespace Tests\Feature;

use App\Models\Detection;
use App\Models\User;
use App\Services\Detections\AssessmentScore;
use App\Services\Detections\ReportAnswer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FactCheckRevisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_revision_preserves_previous_result_in_an_immutable_ledger(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'privacy_policy_accepted_at' => now()]);
        $owner = User::factory()->create();
        $original = ReportAnswer::complete(['schema_version' => 4, 'primary_task' => 'claim', 'verdict' => 'UNVERIFIED',
            'claim' => 'A reviewed claim.', 'summary' => 'The available evidence did not resolve the claim.',
            'sources' => [['id' => 's1', 'url' => 'https://example.gov.ph/record', 'name' => 'Agency',
                'title' => 'Official record', 'used_for' => 'The official record contradicts the claim.']],
            'score' => AssessmentScore::unavailable('No calibrated score.'), 'ui' => []]);
        $detection = Detection::create(['user_id' => $owner->id, 'source_kind' => 'text', 'caption_text' => 'A reviewed claim.',
            'processing_status' => 'completed', 'verdict' => 'review', 'fake_score' => 0, 'fact_check_result' => $original]);

        $this->actingAs($admin)->patchJson('/admin/api/fact-check-reviews/'.$detection->id, [
            'verdict' => 'FALSE', 'summary' => 'A subsequent reviewed record directly contradicts the submitted claim.',
            'reason' => 'A decisive official record was added during editorial review.',
        ])->assertOk()->assertJsonPath('result.verdict', 'FALSE');

        $revision = DB::table('fact_check_revisions')->sole();
        $this->assertSame('UNVERIFIED', json_decode($revision->previous_result, true)['verdict']);
        $this->assertSame('FALSE', $detection->fresh()->fact_check_result['verdict']);
        $this->assertNotNull($detection->fresh()->fact_check_result['correction']);
    }
}
