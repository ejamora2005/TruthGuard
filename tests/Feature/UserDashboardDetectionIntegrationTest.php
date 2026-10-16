<?php

namespace Tests\Feature;

use App\Models\Detection;
use App\Models\User;
use App\Services\Detections\GoogleFactCheckFeedService;
use App\Services\Detections\ReportAnswer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserDashboardDetectionIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(GoogleFactCheckFeedService::class)->shouldReceive('latest')->andReturn(['items' => [], 'configured' => true]);
    }

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
            ->assertViewIs('user.dashboard')
            ->assertSee('Latest public claim reviews')
            ->assertSee(route('history'), false)
            ->assertSee(route('detections.create'), false)
            ->assertDontSee('Detection workspace')
            ->assertDontSee('Analyze text, links, images, videos, and PDFs right from the user dashboard.')
            ->assertDontSee('Possible misinformation case')
            ->assertDontSee('TG-'.str_pad((string) $detection->id, 4, '0', STR_PAD_LEFT));
    }

    public function test_user_history_page_lists_recorded_detection_data(): void
    {
        $user = User::factory()->create();

        $detection = Detection::query()->create([
            'user_id' => $user->id,
            'fact_check_result' => ReportAnswer::complete(['schema_version' => 4, 'primary_task' => 'context',
                'verdict' => 'OUT_OF_CONTEXT', 'summary' => 'The demonstration caption assigns an incorrect event to a historical scene.',
                'sources' => [['id' => 's1', 'url' => 'https://example.gov/demonstration-scene',
                    'used_for' => 'The simulated record establishes the original scene and date.']],
                'answer_segments' => [['text' => 'The demonstration caption assigns an incorrect event to a historical scene.', 'source_ids' => ['s1']]]]),
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
            ->assertSee('Possible misinformation case')
            ->assertSee('Out of Context')
            ->assertDontSee('AI-generated media')
            ->assertSee('All History')
            ->assertSee('Search your assessments')
            ->assertSee('TG-01')
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
            $mock->shouldReceive('latest')->andReturn(['items' => []]);
        });

        $response = $this
            ->actingAs($user)
            ->get(route('detections.result', $detection));

        $response
            ->assertOk()
            ->assertSee('Detection Result')
            ->assertSee(route('detections.create'), false)
            ->assertDontSee('Go back to the previous page');

        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $this->assertSame(1, (new \DOMXPath($dom))->query('//a[@aria-label="Go back"]')->length);
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
            ->assertSee('Fact Check workspace')
            ->assertSee('Analyze');
    }
}
