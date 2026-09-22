<?php

namespace Tests\Feature;

use App\Models\Detection;
use App\Models\User;
use App\Services\Detections\GoogleFactCheckFeedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserDashboardDetectionIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_dashboard_stays_clean_and_points_users_to_detection_center(): void
    {
        $user = User::factory()->create();

        $detection = Detection::query()->create([
            'user_id' => $user->id,
            'source_kind' => 'upload',
            'platform' => 'web',
            'media_type' => 'image',
            'caption_text' => 'Possible misinformation case',
            'fake_score' => 82,
            'processing_status' => 'completed',
            'preprocessing_summary' => 'Preprocessed successfully.',
            'analysis_summary' => 'Likely manipulated visual.',
            'verification_summary' => 'Cross-source verification found inconsistencies.',
            'explanation_summary' => 'Image metadata and caption context were inconsistent with source reporting.',
            'recommendation' => 'Treat as suspicious pending manual confirmation.',
            'signals' => ['ai_generated'],
            'verification_sources' => [],
            'verdict' => 'fake',
            'notes' => 'Escalate if reposted.',
            'analyzed_at' => now(),
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('dashboard'));

        $response
            ->assertOk()
            ->assertSee('Dashboard Overview')
            ->assertSee('A cleaner view of your detection workspace.')
            ->assertSee(route('history'), false)
            ->assertSee('Latest Scan')
            ->assertDontSee('Detection workspace')
            ->assertDontSee('Analyze text, links, images, videos, and PDFs right from the user dashboard.')
            ->assertSee('Ready to review')
            ->assertDontSee('TG-'.str_pad((string) $detection->id, 4, '0', STR_PAD_LEFT));
    }

    public function test_user_history_page_lists_recorded_detection_data(): void
    {
        $user = User::factory()->create();

        $detection = Detection::query()->create([
            'user_id' => $user->id,
            'source_kind' => 'upload',
            'platform' => 'web',
            'media_type' => 'image',
            'caption_text' => 'Possible misinformation case',
            'fake_score' => 82,
            'processing_status' => 'completed',
            'preprocessing_summary' => 'Preprocessed successfully.',
            'analysis_summary' => 'Likely manipulated visual.',
            'verification_summary' => 'Cross-source verification found inconsistencies.',
            'explanation_summary' => 'Image metadata and caption context were inconsistent with source reporting.',
            'recommendation' => 'Treat as suspicious pending manual confirmation.',
            'signals' => ['ai_generated'],
            'verification_sources' => [],
            'verdict' => 'fake',
            'notes' => 'Escalate if reposted.',
            'analyzed_at' => now(),
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('history'));

        $response
            ->assertOk()
            ->assertSee('History')
            ->assertSee('View and manage your detection history')
            ->assertSee('All History')
            ->assertSee('Search by title or keyword...')
            ->assertSee('Fact check result')
            ->assertDontSee('TG-'.str_pad((string) $detection->id, 4, '0', STR_PAD_LEFT))
            ->assertSee(route('detections.result', $detection), false);
    }

    public function test_detection_result_layout_renders_a_single_back_control(): void
    {
        $user = User::factory()->create();
        $detection = Detection::query()->create([
            'user_id' => $user->id,
            'source_kind' => 'upload',
            'platform' => 'web',
            'media_type' => 'image',
            'caption_text' => 'Possible misinformation case',
            'fake_score' => 24,
            'processing_status' => 'completed',
            'analysis_summary' => 'No strong manipulation indicators were detected.',
            'verification_summary' => 'Sources reviewed.',
            'explanation_summary' => 'The submitted media appears low risk.',
            'verification_sources' => [],
            'verdict' => 'real',
            'analyzed_at' => now(),
        ]);

        $this->mock(GoogleFactCheckFeedService::class, function ($mock): void {
            $mock->shouldReceive('latest')->once()->with(6)->andReturn(['items' => []]);
        });

        $response = $this
            ->actingAs($user)
            ->get(route('detections.result', $detection));

        $response
            ->assertOk()
            ->assertSee('Detection Result')
            ->assertSee(route('detections.create'), false)
            ->assertDontSee('Go back to the previous page');

        $this->assertSame(1, substr_count($response->getContent(), 'class="truthguard-shell-toggle shrink-0"'));
    }

    public function test_admin_can_still_open_the_shared_detection_center(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
        ]);

        $response = $this
            ->actingAs($admin)
            ->get(route('detections.create'));

        $response
            ->assertOk()
            ->assertSee('Detection Center')
            ->assertSee('Analyze');
    }
}
