<?php

namespace Tests\Feature;

use App\Jobs\AnnounceFactCheck;
use App\Jobs\SendPushNotification;
use App\Models\Detection;
use App\Models\NotificationPreference;
use App\Models\PublicClaimReviewAnnouncement;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Notifications\NotificationEventService;
use App\Services\Notifications\NotificationUrl;
use App\Services\Notifications\TruthGuardPushNotificationService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PushNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['firebase.enabled' => true, 'firebase.web.projectId' => 'test-project']);
        Queue::fake();
    }

    public function test_registration_supports_multiple_devices_duplicates_and_scoped_removal(): void
    {
        $user = User::factory()->create();
        $first = str_repeat('a', 100);
        $second = str_repeat('b', 100);
        $this->actingAs($user)->postJson('/push/subscriptions', ['token' => $first])->assertOk();
        $this->postJson('/push/subscriptions', ['token' => $first])->assertOk();
        $this->postJson('/push/subscriptions', ['token' => $second])->assertOk();
        $this->assertDatabaseCount('push_subscriptions', 2);
        $this->assertNotSame($first, PushSubscription::first()->getRawOriginal('token'));
        $other = User::factory()->create();
        $this->actingAs($other)->postJson('/push/subscriptions', ['token' => $first])->assertStatus(409);
        $this->deleteJson('/push/subscriptions', ['token' => $first])->assertOk();
        $this->assertDatabaseCount('push_subscriptions', 2);
        $this->actingAs($user)->deleteJson('/push/subscriptions', ['token' => $first])->assertOk();
        $this->assertDatabaseCount('push_subscriptions', 1);
    }

    public function test_authentication_validation_and_private_configuration(): void
    {
        $this->postJson('/push/subscriptions', ['token' => str_repeat('a', 100)])->assertUnauthorized();
        $this->patchJson('/push/preferences', ['push_enabled' => true])->assertUnauthorized();
        config(['firebase.credentials_base64' => 'private-credential']);
        $this->actingAs(User::factory()->create())->getJson('/push/settings')->assertOk()->assertDontSee('private-credential');
        $this->postJson('/push/subscriptions', ['token' => 'bad token'])->assertUnprocessable();
        $this->patchJson('/push/preferences', ['push_enabled' => 'bad'])->assertUnprocessable();
    }

    public function test_completion_observer_deduplicates_and_skips_failed_processing(): void
    {
        $user = User::factory()->create();
        $detection = $this->detection($user, 'failed');
        $events = app(NotificationEventService::class);
        $events->analysisComplete($detection);
        $this->assertSame(0, $user->notifications()->count());
        $detection->update(['processing_status' => 'completed']);
        $events->analysisComplete($detection);
        $events->analysisComplete($detection->fresh());
        $this->assertSame(1, $user->notifications()->count());
        $data = $user->notifications()->first()->data;
        $this->assertSame('TruthGuard Analysis Complete', $data['title']);
        $this->assertStringNotContainsString('private caption', json_encode($data));
        $this->assertSame('/detections/'.$detection->id.'/result', $data['action_url']);
    }

    public function test_category_and_push_preferences_are_independent_and_rechecked_by_job(): void
    {
        $user = User::factory()->create();
        NotificationPreference::create(['user_id' => $user->id, 'analysis_results' => false, 'push_enabled' => true]);
        $detection = $this->detection($user);
        app(NotificationEventService::class)->analysisComplete($detection);
        $this->assertSame(0, $user->notifications()->count());
        $subscription = PushSubscription::create(['user_id' => $user->id, 'token' => str_repeat('a', 100), 'token_hash' => hash('sha256', 'a')]);
        $notification = app(NotificationEventService::class)->system($user, 'account-update', 'An account update is available.');
        Queue::assertPushed(SendPushNotification::class, 1);
        NotificationPreference::where('user_id', $user->id)->update(['push_enabled' => false]);
        Http::preventStrayRequests();
        (new SendPushNotification($subscription->id, $notification->id))->handle(app(TruthGuardPushNotificationService::class));
        Http::assertNothingSent();
        $this->assertSame(1, $user->notifications()->count());
    }

    public function test_evidence_update_and_new_fact_check_events_deduplicate(): void
    {
        $user = User::factory()->create(['subscription_status' => 'active', 'email_updates_enabled' => false]);
        $detection = $this->detection($user);
        $detection->update(['verification_sources' => [['url' => 'https://example.com/evidence']]]);
        $events = app(NotificationEventService::class);
        $events->factCheckUpdate($detection);
        $events->factCheckUpdate($detection);
        $announcement = PublicClaimReviewAnnouncement::create(['feed_item_id' => 'test-review', 'headline' => 'Review']);
        (new AnnounceFactCheck($announcement->id))->handle($events);
        (new AnnounceFactCheck($announcement->id))->handle($events);
        $this->assertSame(1, $user->notifications()->where('data->preference', 'fact_check_updates')->count());
        $this->assertSame(1, $user->notifications()->where('data->preference', 'new_fact_checks')->count());
    }

    public function test_fcm_payload_and_invalid_token_cleanup(): void
    {
        $user = User::factory()->create();
        NotificationPreference::create(['user_id' => $user->id, 'push_enabled' => true]);
        $subscription = PushSubscription::create(['user_id' => $user->id, 'token' => str_repeat('a', 100), 'token_hash' => hash('sha256', 'a')]);
        $notification = app(NotificationEventService::class)->system($user, 'test', 'Safe account update.', 'https://evil.example');
        $push = new class extends TruthGuardPushNotificationService
        {
            protected function accessToken(): string
            {
                return 'fake-access-token';
            }
        };
        Http::fake(['fcm.googleapis.com/*' => Http::sequence()->push(['name' => 'message-id'])->push(['error' => ['details' => [[
            '@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNREGISTERED',
        ]]]], 404)]);
        (new SendPushNotification($subscription->id, $notification->id))->handle($push);
        Http::assertSent(fn ($request) => $request['message']['data']['url'] === '/notifications'
            && $request['message']['data']['notification_id'] === $notification->id
            && ! isset($request['message']['notification']));

        (new SendPushNotification($subscription->id, $notification->id))->handle($push);
        $this->assertDatabaseCount('push_subscriptions', 0);
    }

    public function test_temporary_fcm_errors_keep_token_and_raise_sanitized_retry_error(): void
    {
        $user = User::factory()->create();
        $subscription = PushSubscription::create(['user_id' => $user->id, 'token' => 'secret-token', 'token_hash' => hash('sha256', 'a')]);
        $push = new class extends TruthGuardPushNotificationService
        {
            protected function accessToken(): string
            {
                return 'fake-access-token';
            }
        };
        Http::fake(['*' => Http::response(['error' => 'secret-token'], 503)]);
        try {
            $push->send($subscription, ['title' => 'TruthGuard']);
            $this->fail('Temporary error must trigger retry.');
        } catch (\RuntimeException $error) {
            $this->assertStringNotContainsString('secret-token', $error->getMessage());
        }
        $this->assertDatabaseCount('push_subscriptions', 1);
    }

    public function test_urls_and_read_state_are_protected(): void
    {
        foreach (['//evil.example', 'javascript:alert(1)', '/logout', '/notifications?next=https://evil.example', '/detections/1/result/../logout'] as $url) {
            $this->assertSame('/notifications', NotificationUrl::safe($url));
        }
        $user = User::factory()->create();
        $notification = app(NotificationEventService::class)->system($user, 'read-test', 'Account update.');
        $this->actingAs($user)->postJson(route('notifications.read', $notification))->assertOk();
        $this->assertNotNull($notification->fresh()->read_at);
        $this->actingAs(User::factory()->create())->deleteJson(route('notifications.destroy', $notification))->assertNotFound();
    }

    public function test_logout_removes_only_this_browser_subscription(): void
    {
        $user = User::factory()->create();
        foreach (['a', 'b'] as $token) {
            PushSubscription::create(['user_id' => $user->id, 'token' => $token, 'token_hash' => hash('sha256', $token)]);
        }
        $this->actingAs($user)->withCookie('truthguard_push', hash('sha256', 'a'))->post('/logout')->assertRedirect();
        $this->assertDatabaseCount('push_subscriptions', 1);
        $this->assertSame('b', PushSubscription::first()->token);
    }

    public function test_self_test_requires_preferences_and_only_targets_self(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/push/test')->assertUnprocessable();
        NotificationPreference::create(['user_id' => $user->id, 'push_enabled' => true]);
        $this->postJson('/push/subscriptions', ['token' => str_repeat('x', 100)])->assertOk();
        $this->postJson('/push/test', ['user_id' => User::factory()->create()->id])->assertStatus(202);
        Queue::assertPushed(SendPushNotification::class, 1);
    }

    public function test_normal_production_user_cannot_send_test_notifications(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->actingAs(User::factory()->create())->postJson('/push/test')->assertForbidden();
    }

    public function test_push_mutations_require_csrf_outside_testing_environment(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->actingAs(User::factory()->create())->postJson('/push/subscriptions', ['token' => str_repeat('x', 100)])->assertStatus(419);
    }

    public function test_rotated_token_replaces_only_the_current_device(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/push/subscriptions', ['token' => str_repeat('a', 100)])->assertOk();
        $this->postJson('/push/subscriptions', ['token' => str_repeat('b', 100)])->assertOk();
        $this->withCredentials()->withCookie('truthguard_push', hash('sha256', str_repeat('a', 100)))
            ->postJson('/push/subscriptions', ['token' => str_repeat('c', 100)])->assertOk();
        $this->assertDatabaseCount('push_subscriptions', 2);
        $this->assertDatabaseMissing('push_subscriptions', ['token_hash' => hash('sha256', str_repeat('a', 100))]);
    }

    public function test_oauth_signing_and_access_token_cache(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $privateKey);
        config(['firebase.credentials_base64' => base64_encode(json_encode([
            'project_id' => 'test-project', 'client_email' => 'test@test-project.iam.gserviceaccount.com', 'private_key' => $privateKey,
        ]))]);
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'test-oauth-token', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response(['name' => 'message']),
        ]);
        $subscription = PushSubscription::create(['user_id' => User::factory()->create()->id, 'token' => 'token', 'token_hash' => hash('sha256', 'token')]);
        $service = app(TruthGuardPushNotificationService::class);
        $service->send($subscription, ['title' => 'TruthGuard', 'body' => 'Test']);
        $service->send($subscription, ['title' => 'TruthGuard', 'body' => 'Test']);
        Http::assertSentCount(3);
        Http::assertSent(function ($request) use ($key) {
            if ($request->url() !== 'https://oauth2.googleapis.com/token') {
                return false;
            }
            $parts = explode('.', $request['assertion']);
            $signature = base64_decode(strtr($parts[2], '-_', '+/'));

            return openssl_verify($parts[0].'.'.$parts[1], $signature, openssl_pkey_get_details($key)['key'], OPENSSL_ALGO_SHA256) === 1;
        });
    }

    public function test_profile_contains_notification_controls(): void
    {
        $this->actingAs(User::factory()->create())->get('/profile')->assertOk()
            ->assertSee('Enable TruthGuard Notifications')->assertSee('data-push-settings', false);
    }

    private function detection(User $user, string $status = 'completed'): Detection
    {
        return Detection::create(['user_id' => $user->id, 'source_kind' => 'upload', 'media_type' => 'video',
            'fake_score' => 10, 'verdict' => 'real', 'processing_status' => $status,
            'caption_text' => 'private caption', 'analyzed_at' => now()]);
    }
}
