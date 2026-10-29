<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeDetectionEvidence;
use App\Models\Detection;
use App\Models\User;
use App\Services\Detections\GoogleFactCheckFeedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PendingReportJsonTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_visit_with_no_submission_does_not_start_or_resume_analysis(): void
    {
        Queue::fake();
        $this->mock(GoogleFactCheckFeedService::class)
            ->shouldReceive('latest')
            ->once()
            ->andReturn(['items' => []]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('detections.create'))
            ->assertOk()
            ->assertViewHas('selectedDetection', null)
            ->assertViewMissing('pendingDetection');

        $this->assertDatabaseCount('detections', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_queued_submission_returns_a_poll_url_without_redirecting_or_repeating_analysis(): void
    {
        Queue::fake();
        config()->set('fact_check.queue_enabled', true);
        $this->actingAs(User::factory()->create());
        $response = $this->postJson(route('detections.store'), ['caption_text' => 'The city cancelled classes today.']);
        $detection = Detection::sole();
        $response->assertStatus(202)->assertHeaderMissing('Location')
            ->assertJsonPath('processing_status', 'queued')
            ->assertJsonPath('result_url', route('detections.result', $detection));

        foreach (['queued', 'processing'] as $status) {
            $detection->forceFill(['processing_status' => $status])->saveQuietly();
            $this->getJson(route('detections.result', $detection))->assertJsonPath('processing_status', $status);
        }
        Queue::assertPushed(AnalyzeDetectionEvidence::class, 1);
        $this->assertDatabaseCount('detections', 1);
        Http::assertNothingSent();
    }

    public function test_unfinished_report_links_resume_the_closable_composer_instead_of_a_pending_page(): void
    {
        Queue::fake();
        config()->set('fact_check.queue_enabled', true);
        $this->mock(GoogleFactCheckFeedService::class)->shouldReceive('latest')->andReturn(['items' => []]);
        $this->actingAs(User::factory()->create());
        $response = $this->post(route('detections.store'), ['caption_text' => 'The city cancelled classes today.']);
        $detection = Detection::sole();
        $resume = route('detections.create', ['pending' => $detection->id]);
        $response->assertRedirect($resume);

        foreach (['queued', 'processing', 'failed'] as $status) {
            $detection->forceFill(['processing_status' => $status])->saveQuietly();
            $this->get(route('detections.result', $detection))->assertRedirect($resume);
            $this->get($resume)->assertOk()->assertViewHas('pendingDetection', fn ($pending) => $pending->is($detection))
                ->assertSee('Hide progress')->assertSee('Minimize progress')
                ->assertSee('View progress')->assertDontSee('Your assessment is in progress');
        }
        $detection->forceFill(['processing_status' => 'completed'])->saveQuietly();
        $this->get($resume)->assertRedirect(route('detections.result', $detection));
        Queue::assertPushed(AnalyzeDetectionEvidence::class, 1);
        Http::assertNothingSent();
    }

    public function test_pending_report_resume_and_polling_are_owner_scoped(): void
    {
        $detection = Detection::create(['user_id' => User::factory()->create()->id,
            'source_kind' => 'link', 'media_type' => 'text', 'processing_status' => 'queued',
            'verdict' => 'review', 'fake_score' => 0]);
        $this->actingAs(User::factory()->create());
        $this->get(route('detections.create', ['pending' => $detection->id]))->assertNotFound();
        $this->getJson(route('detections.result', $detection))->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_returning_to_fact_check_automatically_resumes_the_owners_active_assessment(): void
    {
        $user = User::factory()->create();
        $older = Detection::create(['user_id' => $user->id, 'source_kind' => 'link', 'media_type' => 'text',
            'processing_status' => 'processing', 'verdict' => 'review', 'fake_score' => 0]);
        $latest = Detection::create(['user_id' => $user->id, 'source_kind' => 'link', 'media_type' => 'text',
            'processing_status' => 'queued', 'verdict' => 'review', 'fake_score' => 0]);
        Detection::create(['user_id' => User::factory()->create()->id, 'source_kind' => 'link', 'media_type' => 'text',
            'processing_status' => 'queued', 'verdict' => 'review', 'fake_score' => 0]);

        $this->actingAs($user)->get(route('detections.create'))
            ->assertOk()
            ->assertViewHas('pendingDetection', fn ($pending) => $pending->is($latest));

        $this->assertNotSame($older->id, $latest->id);
    }

    public function test_logout_leaves_unfinished_assessments_running_for_the_worker(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $queued = Detection::create(['user_id' => $user->id, 'source_kind' => 'link', 'media_type' => 'text',
            'caption_text' => 'A claim in progress', 'processing_status' => 'queued', 'verdict' => 'review', 'fake_score' => 0]);
        $processing = Detection::create(['user_id' => $user->id, 'source_kind' => 'link', 'media_type' => 'text',
            'processing_status' => 'processing', 'verdict' => 'review', 'fake_score' => 0]);
        $otherQueued = Detection::create(['user_id' => $other->id, 'source_kind' => 'link', 'media_type' => 'text',
            'processing_status' => 'queued', 'verdict' => 'review', 'fake_score' => 0]);

        $this->actingAs($user)->post(route('logout'))->assertRedirect(route('login', absolute: false));

        $this->assertSame('queued', $queued->fresh()->processing_status);
        $this->assertSame('processing', $processing->fresh()->processing_status);
        $this->assertNull($queued->fresh()->fact_check_result);
        $this->assertNull($processing->fresh()->fact_check_result);
        $this->assertSame('queued', $otherQueued->fresh()->processing_status);
        $this->assertGuest();
    }

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
