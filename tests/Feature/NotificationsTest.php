<?php

namespace Tests\Feature;

use App\Models\Detection;
use App\Models\PublicClaimReviewAnnouncement;
use App\Models\PublicClaimReviewEmailDelivery;
use App\Models\User;
use App\Notifications\FactCheckResultReady;
use App\Notifications\PublicClaimReviewPublished;
use App\Notifications\WelcomeToTruthGuard;
use App\Services\Detections\GoogleFactCheckFeedService;
use App\Services\Notifications\PublicClaimReviewNotificationService;
use App\Services\Notifications\TruthGuardNotificationManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Mockery;
use Tests\TestCase;

class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_notifications_page_shows_user_notifications(): void
    {
        $user = User::factory()->create();
        $user->notify(new WelcomeToTruthGuard);

        $response = $this
            ->actingAs($user)
            ->get(route('notifications.index'));

        $response
            ->assertOk()
            ->assertSee('Notification Center')
            ->assertSee('Welcome to TruthGuard');
    }

    public function test_user_can_mark_all_notifications_as_read(): void
    {
        $user = User::factory()->create();
        $user->notify(new WelcomeToTruthGuard);

        $response = $this
            ->actingAs($user)
            ->postJson(route('notifications.mark-all-read'));

        $response->assertOk();
        $this->assertSame(0, $user->fresh()->unreadNotifications()->count());
    }

    public function test_user_cannot_mark_another_users_notification_as_read(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $owner->notify(new WelcomeToTruthGuard);

        $notification = $owner->notifications()->firstOrFail();

        $response = $this
            ->actingAs($otherUser)
            ->postJson(route('notifications.read', $notification));

        $response->assertNotFound();
        $this->assertTrue($notification->fresh()->unread());
    }

    public function test_user_can_archive_notification_from_delete_action(): void
    {
        $user = User::factory()->create();
        $user->notify(new WelcomeToTruthGuard);

        $notification = $user->notifications()->firstOrFail();

        $response = $this
            ->actingAs($user)
            ->deleteJson(route('notifications.destroy', $notification));

        $response
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'unreadCount' => 0,
            ]);

        $notification->refresh();

        $this->assertNotNull($notification->archived_at);
        $this->assertNotNull($notification->read_at);

        $this
            ->actingAs($user)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertDontSee('Welcome to TruthGuard');
    }

    public function test_user_cannot_archive_another_users_notification(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $owner->notify(new WelcomeToTruthGuard);

        $notification = $owner->notifications()->firstOrFail();

        $response = $this
            ->actingAs($otherUser)
            ->deleteJson(route('notifications.destroy', $notification));

        $response->assertNotFound();
        $this->assertNotNull($notification->fresh());
    }

    public function test_peek_backfills_welcome_notification_for_existing_logged_in_user(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->getJson(route('notifications.peek'));

        $response
            ->assertOk()
            ->assertJsonPath('unreadCount', 1);

        $this->assertSame(
            1,
            $user->fresh()->notifications()->where('type', WelcomeToTruthGuard::class)->count()
        );
    }

    public function test_welcome_notifications_are_sent_only_once_per_account(): void
    {
        $user = User::factory()->create();
        $manager = app(TruthGuardNotificationManager::class);

        $manager->sendWelcomeOnce($user);
        $manager->sendWelcomeOnce($user->fresh());

        $this->assertSame(
            1,
            $user->fresh()->notifications()->where('type', WelcomeToTruthGuard::class)->count()
        );
    }

    public function test_fact_check_result_notifications_are_not_sent(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $detection = Detection::query()->create([
            'user_id' => $user->id,
            'source_kind' => 'upload',
            'platform' => 'web',
            'source_url' => null,
            'media_path' => 'detections/example.jpg',
            'media_checksum' => null,
            'media_type' => 'image',
            'request_fingerprint' => null,
            'reused_from_detection_id' => null,
            'caption_text' => null,
            'fake_score' => 82,
            'processing_status' => 'completed',
            'preprocessing_summary' => 'Image prepared for analysis.',
            'analysis_summary' => 'TruthGuard found strong visual risk signals.',
            'verification_summary' => 'Related public sources were checked.',
            'explanation_summary' => 'The submission needs careful verification before sharing.',
            'recommendation' => 'Open the result and compare against trusted sources.',
            'signals' => [],
            'verification_sources' => [],
            'verdict' => 'fake',
            'notes' => null,
            'analyzed_at' => now(),
        ]);
        $manager = app(TruthGuardNotificationManager::class);

        $manager->sendFactCheckResultOnce($detection);
        $manager->sendFactCheckResultOnce($detection->fresh());

        Notification::assertNothingSent();

        $this->assertSame(
            0,
            $user->fresh()->notifications()->where('type', FactCheckResultReady::class)->count()
        );
    }

    public function test_new_public_claim_reviews_are_emailed_to_active_users(): void
    {
        Notification::fake();
        config()->set('services.google_fact_check.feed_news_notifications_seed_baseline', false);

        $activeUser = User::factory()->create(['subscription_status' => 'active']);
        $inactiveUser = User::factory()->create(['subscription_status' => 'inactive']);
        $this->mockPublicClaimReviewFeed();

        $result = app(PublicClaimReviewNotificationService::class)->announceLatest(limit: 5);

        $this->assertSame(1, $result['announced']);
        $this->assertSame(1, $result['notified']);
        $this->assertSame(1, PublicClaimReviewAnnouncement::query()->count());
        $announcement = PublicClaimReviewAnnouncement::query()->firstOrFail();

        Notification::assertSentTo($activeUser, PublicClaimReviewPublished::class);
        Notification::assertNotSentTo($inactiveUser, PublicClaimReviewPublished::class);
        $this->assertDatabaseHas('public_claim_review_email_deliveries', [
            'public_claim_review_announcement_id' => $announcement->id,
            'user_id' => $activeUser->id,
        ]);
        $this->assertDatabaseMissing('public_claim_review_email_deliveries', [
            'public_claim_review_announcement_id' => $announcement->id,
            'user_id' => $inactiveUser->id,
        ]);
        $this->assertSame(0, $activeUser->fresh()->notifications()->where('type', PublicClaimReviewPublished::class)->count());
    }

    public function test_public_claim_review_emails_are_sent_only_once(): void
    {
        Notification::fake();
        config()->set('services.google_fact_check.feed_news_notifications_seed_baseline', false);

        $user = User::factory()->create(['subscription_status' => 'active']);
        $this->mockPublicClaimReviewFeed(expectedCalls: 2);
        $service = app(PublicClaimReviewNotificationService::class);

        $firstResult = $service->announceLatest(limit: 5);
        $secondResult = $service->announceLatest(limit: 5);

        $this->assertSame(1, $firstResult['announced']);
        $this->assertSame(0, $secondResult['announced']);
        $this->assertSame(1, PublicClaimReviewAnnouncement::query()->count());
        Notification::assertSentToTimes($user, PublicClaimReviewPublished::class, 1);
        $this->assertSame(1, PublicClaimReviewEmailDelivery::query()->where('user_id', $user->id)->count());
        $this->assertSame(0, $user->fresh()->notifications()->where('type', PublicClaimReviewPublished::class)->count());
    }

    public function test_first_public_claim_review_check_seeds_baseline_without_notifying(): void
    {
        Notification::fake();

        $user = User::factory()->create(['subscription_status' => 'active']);
        $this->mockPublicClaimReviewFeed();

        $result = app(PublicClaimReviewNotificationService::class)->announceLatest(limit: 5);

        $this->assertSame(1, $result['seeded']);
        $this->assertSame(0, $result['announced']);
        $this->assertSame(0, $result['notified']);
        $this->assertSame(1, PublicClaimReviewAnnouncement::query()->count());
        $this->assertSame(0, PublicClaimReviewEmailDelivery::query()->count());
        Notification::assertNotSentTo($user, PublicClaimReviewPublished::class);
    }

    private function mockPublicClaimReviewFeed(int $expectedCalls = 1): void
    {
        $feedService = Mockery::mock(GoogleFactCheckFeedService::class);
        $feedService
            ->shouldReceive('latest')
            ->times($expectedCalls)
            ->andReturn([
                'configured' => true,
                'items' => [
                    [
                        'id' => 'public-review-001',
                        'publisher' => 'Rappler',
                        'headline' => 'Fact Check: Viral public claim reviewed',
                        'claim' => 'A viral public claim was checked by a trusted partner.',
                        'rating' => 'False',
                        'source_domain' => 'rappler.com',
                        'url' => 'https://www.rappler.com/fact-check/example',
                        'image_url' => null,
                        'timestamp' => now()->timestamp,
                    ],
                ],
            ]);

        $this->instance(GoogleFactCheckFeedService::class, $feedService);
    }
}
