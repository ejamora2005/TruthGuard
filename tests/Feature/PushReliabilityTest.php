<?php

namespace Tests\Feature;

use App\Jobs\SendPushNotification;
use App\Models\NotificationPreference;
use App\Models\PushDelivery;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Notifications\NotificationEventService;
use App\Services\Notifications\PushConfiguration;
use App\Services\Notifications\TruthGuardPushNotificationService;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PushReliabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['firebase.enabled' => true, 'firebase.web.projectId' => 'test-project']);
        Queue::fake();
    }

    private function device(User $user, string $token = 'device'): PushSubscription
    {
        NotificationPreference::updateOrCreate(['user_id' => $user->id], ['push_enabled' => true]);

        return PushSubscription::create(['user_id' => $user->id, 'token' => $token, 'token_hash' => hash('sha256', $token)]);
    }

    public function test_background_cookie_authorizes_only_its_own_user_without_login_session(): void
    {
        $user = User::factory()->create();
        $device = $this->device($user);
        $own = app(NotificationEventService::class)->system($user, 'own', 'Safe notification.');
        $otherUser = User::factory()->create();
        $other = app(NotificationEventService::class)->system($otherUser, 'other', 'Other notification.');
        $this->getJson('/push/deliveries/'.$own->id)->assertNotFound();
        $this->withCredentials()->withCookie('truthguard_push', $device->token_hash)
            ->getJson('/push/deliveries/'.$own->id)->assertOk()->assertJsonPath('body', 'Safe notification.');
        $this->getJson('/push/deliveries/'.$other->id)->assertNotFound();
        $device->delete();
        $this->getJson('/push/deliveries/'.$own->id)->assertNotFound();
    }

    public function test_disabled_preferences_and_different_logged_in_account_reject_pending_push(): void
    {
        $user = User::factory()->create();
        $device = $this->device($user);
        $notification = app(NotificationEventService::class)->system($user, 'private', 'Safe notification.');
        NotificationPreference::where('user_id', $user->id)->update(['push_enabled' => false]);
        $this->withCredentials()->withCookie('truthguard_push', $device->token_hash)
            ->getJson('/push/deliveries/'.$notification->id)->assertNotFound();
        NotificationPreference::where('user_id', $user->id)->update(['push_enabled' => true]);
        $this->actingAs(User::factory()->create())->getJson('/push/deliveries/'.$notification->id)->assertNotFound();
    }

    public function test_switching_accounts_revokes_previous_device_binding(): void
    {
        $previous = User::factory()->create();
        $device = $this->device($previous);
        $otherDevice = $this->device($previous, 'other-device');
        request()->cookies->set('truthguard_push', $device->token_hash);
        Event::dispatch(new Login('web', User::factory()->create(), false));
        $this->assertModelMissing($device);
        $this->assertModelExists($otherDevice);
    }

    public function test_self_test_targets_current_device_and_status_is_owner_scoped(): void
    {
        $user = User::factory()->create();
        $device = $this->device($user);
        $otherDevice = $this->device($user, 'other-device');
        $this->actingAs($user)->postJson('/push/test')->assertUnprocessable();
        $response = $this->withCredentials()->withCookie('truthguard_push', $device->token_hash)->postJson('/push/test')->assertAccepted();
        Queue::assertPushed(SendPushNotification::class, fn ($job) => $job->subscriptionId === $device->id);
        Queue::assertNotPushed(SendPushNotification::class, fn ($job) => $job->subscriptionId === $otherDevice->id);
        $url = $response->json('statusUrl');
        $this->withCredentials()->getJson($url)->assertOk()->assertJsonPath('status', 'queued');
        $this->actingAs(User::factory()->create())->getJson($url)->assertNotFound();
    }

    public function test_retry_reports_sanitized_error_then_records_acceptance_without_resending(): void
    {
        $user = User::factory()->create();
        $device = $this->device($user);
        $notification = app(NotificationEventService::class)->system($user, 'retry', 'Safe notification.');
        $service = new class extends TruthGuardPushNotificationService
        {
            protected function accessToken(): string
            {
                return 'fake-access-token';
            }
        };
        Http::fake(['fcm.googleapis.com/*' => Http::sequence()
            ->push(['error' => ['message' => 'never-log-this-secret']], 503)
            ->push(['name' => 'accepted'])]);
        $job = new SendPushNotification($device->id, $notification->id);
        try {
            $job->handle($service);
            $this->fail('Expected provider failure.');
        } catch (\RuntimeException $error) {
            $this->assertStringNotContainsString('never-log', $error->getMessage());
        }
        $this->assertSame('retrying', PushDelivery::first()->status);
        $job->handle($service);
        $job->handle($service);
        Http::assertSentCount(2);
        $this->assertSame('sent', PushDelivery::first()->status);
        $this->actingAs($user)->withCredentials()->withCookie('truthguard_push', $device->token_hash)
            ->getJson('/push/test/'.$notification->id)->assertJsonPath('status', 'sent')
            ->assertSee('does not confirm display');
    }

    public function test_settings_never_claim_readiness_with_missing_credentials(): void
    {
        config(['firebase.credentials_base64' => null, 'firebase.credentials_path' => null, 'firebase.vapid_key' => null]);
        $this->assertNotEmpty(app(PushConfiguration::class)->problems());
        $this->actingAs(User::factory()->create())->getJson('/push/settings')
            ->assertJsonPath('enabled', false)->assertJsonPath('canTest', true)
            ->assertDontSee('private_key');
    }

    public function test_delayed_test_is_bounded_and_targets_current_device(): void
    {
        $user = User::factory()->create();
        $device = $this->device($user);
        $this->actingAs($user)->withCredentials()->withCookie('truthguard_push', $device->token_hash)
            ->postJson('/push/test', ['delay_seconds' => 15])->assertAccepted();
        Queue::assertPushed(SendPushNotification::class, fn ($job) => $job->subscriptionId === $device->id
            && $job->delay->greaterThan(now()->addSeconds(10)));
        $this->postJson('/push/test', ['delay_seconds' => 999999])->assertUnprocessable();
    }
}
