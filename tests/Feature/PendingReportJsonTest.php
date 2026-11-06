<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeDetectionEvidence;
use App\Models\Detection;
use App\Models\User;
use App\Services\Detections\ActiveDetectionManager;
use App\Services\Detections\GoogleFactCheckFeedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PendingReportJsonTest extends TestCase
{
    use RefreshDatabase;

    public function test_lightweight_progress_expires_stale_work_and_is_owner_scoped(): void
    {
        $user = User::factory()->create();
        $detection = Detection::create(['user_id' => $user->id, 'source_kind' => 'upload',
            'media_type' => 'image', 'processing_status' => 'processing', 'verdict' => 'review', 'fake_score' => 0]);
        $this->actingAs(User::factory()->create())->getJson(route('detections.progress', $detection))->assertNotFound();
        $this->actingAs($user)->getJson(route('detections.progress', $detection))
            ->assertOk()->assertJsonPath('processing_status', 'processing')->assertJsonPath('elapsed_seconds', 0);
        $this->travel(16)->minutes();
        $detection->touch();
        $this->getJson(route('detections.progress', $detection))->assertOk()
            ->assertJsonPath('processing_status', 'failed');
        $this->assertSame('failed', $detection->fresh()->processing_status);
        Http::assertNothingSent();
    }

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

    public function test_completed_report_poll_explicitly_returns_completed_status(): void
    {
        $user = User::factory()->create();
        $detection = Detection::create([
            'user_id' => $user->id,
            'source_kind' => 'upload',
            'media_type' => 'image',
            'processing_status' => 'completed',
            'verdict' => 'misleading',
            'fake_score' => 64,
            'fact_check_result' => [
                'verdict' => 'UNVERIFIED',
                'summary' => 'No matching evidence was available to establish the claim.',
                'sources' => [],
            ],
        ]);

        $this->actingAs($user)
            ->getJson(route('detections.result', $detection))
            ->assertOk()
            ->assertJsonPath('processing_status', 'completed');
    }

    public function test_polling_expires_an_abandoned_analysis_instead_of_loading_forever(): void
    {
        config()->set('fact_check.pending_stale_minutes', 10);
        $user = User::factory()->create();
        $detection = Detection::create([
            'user_id' => $user->id,
            'source_kind' => 'upload',
            'media_type' => 'image',
            'processing_status' => 'processing',
            'verdict' => 'review',
            'fake_score' => 0,
        ]);
        $this->travel(11)->minutes();

        $this->actingAs($user)
            ->getJson(route('detections.result', $detection))
            ->assertOk()
            ->assertJsonPath('processing_status', 'failed');

        $this->assertSame('failed', $detection->fresh()->processing_status);
        $this->assertSame('ANALYSIS_UNAVAILABLE', data_get($detection->fresh()->fact_check_result, 'verdict'));
    }

    public function test_opening_an_older_november_13_report_restores_the_reviewed_catalog_answer(): void
    {
        $user = User::factory()->create();
        $detection = Detection::create([
            'user_id' => $user->id,
            'source_kind' => 'upload',
            'media_type' => 'image',
            'caption_text' => 'Is the claim in this post true?',
            'processing_status' => 'completed',
            'verdict' => 'review',
            'fake_score' => 0,
            'signals' => ['submission_extraction' => [[
                'analysis_text' => 'The end of the world will be on Friday, November 13, 2026, according to a scientist mathematical prediction.',
            ]]],
            'fact_check_result' => [
                'schema_version' => 4,
                'verdict' => 'UNVERIFIED',
                'summary' => 'No result was found.',
                'sources' => [],
            ],
        ]);

        $this->actingAs($user)
            ->getJson(route('detections.result', $detection))
            ->assertOk()
            ->assertJsonPath('processing_status', 'completed')
            ->assertJsonPath('verdict', 'MISLEADING')
            ->assertJsonCount(5, 'sources');

        $detection->refresh();
        $this->assertSame('MISLEADING', data_get($detection->fact_check_result, 'verdict'));
        $this->assertTrue((bool) data_get($detection->fact_check_result, 'diagnostics.known_fact_check'));
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

        foreach (['queued', 'processing'] as $status) {
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

    public function test_failed_upload_opens_a_readable_report_instead_of_redirecting_to_progress(): void
    {
        $user = User::factory()->create();
        $detection = Detection::create(['user_id' => $user->id, 'source_kind' => 'upload',
            'media_type' => 'image', 'processing_status' => 'failed', 'verdict' => 'review', 'fake_score' => 0]);
        $this->actingAs($user)->get(route('detections.result', $detection))->assertOk();
        $this->assertSame('ANALYSIS_UNAVAILABLE', data_get($detection->fresh()->fact_check_result, 'verdict'));
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

    public function test_stale_job_cleanup_failure_does_not_crash_the_fact_check_page(): void
    {
        $user = User::factory()->create();
        $this->mock(GoogleFactCheckFeedService::class)
            ->shouldReceive('latest')
            ->once()
            ->andReturn(['items' => []]);
        $this->mock(ActiveDetectionManager::class)
            ->shouldReceive('expireStaleForUser')
            ->once()
            ->with($user->id)
            ->andThrow(new \RuntimeException('Production cleanup failure'));

        $this->actingAs($user)
            ->get(route('detections.create'))
            ->assertOk()
            ->assertViewHas('selectedDetection', null);
    }

    public function test_opening_fact_check_does_not_resume_background_work_without_an_explicit_pending_link(): void
    {
        $this->mock(GoogleFactCheckFeedService::class)->shouldReceive('latest')->once()->andReturn(['items' => []]);
        $user = User::factory()->create();
        $older = Detection::create(['user_id' => $user->id, 'source_kind' => 'link', 'media_type' => 'text',
            'processing_status' => 'processing', 'verdict' => 'review', 'fake_score' => 0]);
        $latest = Detection::create(['user_id' => $user->id, 'source_kind' => 'link', 'media_type' => 'text',
            'processing_status' => 'queued', 'verdict' => 'review', 'fake_score' => 0]);
        Detection::create(['user_id' => User::factory()->create()->id, 'source_kind' => 'link', 'media_type' => 'text',
            'processing_status' => 'queued', 'verdict' => 'review', 'fake_score' => 0]);

        $this->actingAs($user)->get(route('detections.create'))
            ->assertOk()
            ->assertViewMissing('pendingDetection')
            ->assertDontSee('aria-label="Mobile navigation"', false);

        $this->assertNotSame($older->id, $latest->id);
        $this->assertSame('processing', $older->fresh()->processing_status);
        $this->assertSame('queued', $latest->fresh()->processing_status);
    }

    public function test_unfinished_analysis_remains_accessible_after_leaving_fact_check(): void
    {
        $user = User::factory()->create();
        $pending = Detection::create([
            'user_id' => $user->id,
            'source_kind' => 'link',
            'media_type' => 'text',
            'caption_text' => 'A background claim review',
            'processing_status' => 'processing',
            'verdict' => 'review',
            'fake_score' => 0,
        ]);
        Detection::create([
            'user_id' => User::factory()->create()->id,
            'source_kind' => 'link',
            'media_type' => 'text',
            'processing_status' => 'processing',
            'verdict' => 'review',
            'fake_score' => 0,
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Fact check analyzing')
            ->assertSee('View progress')
            ->assertSee('Your fact check is ready')
            ->assertSee('Open result')
            ->assertSee('Dismiss fact-check update')
            ->assertSee('pending='.$pending->id, false);
    }

    public function test_stale_background_assessment_does_not_reopen_the_analysis_modal(): void
    {
        config()->set('fact_check.pending_stale_minutes', 10);
        $this->mock(GoogleFactCheckFeedService::class)->shouldReceive('latest')->once()->andReturn(['items' => []]);
        $user = User::factory()->create();
        $detection = Detection::create([
            'user_id' => $user->id,
            'source_kind' => 'upload',
            'media_type' => 'image',
            'media_path' => 'detections/abandoned-image.jpg',
            'processing_status' => 'processing',
            'verdict' => 'review',
            'fake_score' => 0,
        ]);
        $this->travel(11)->minutes();

        $this->actingAs($user)
            ->get(route('detections.create'))
            ->assertOk()
            ->assertViewMissing('pendingDetection');

        $detection->refresh();
        $this->assertSame('failed', $detection->processing_status);
        $this->assertSame('ANALYSIS_UNAVAILABLE', data_get($detection->fact_check_result, 'verdict'));
        $this->assertNull(data_get($detection->signals, 'analysis_progress'));
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
