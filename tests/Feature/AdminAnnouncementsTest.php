<?php

namespace Tests\Feature;

use App\Jobs\SendPushNotification;
use App\Models\NotificationPreference;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdminAnnouncementsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_send_announcement_to_self_and_queue_push(): void
    {
        Queue::fake();
        config(['firebase.enabled' => true]);

        $admin = User::factory()->create(['is_admin' => true]);
        NotificationPreference::create([
            'user_id' => $admin->id,
            'push_enabled' => true,
            'system_notifications' => true,
        ]);
        PushSubscription::create([
            'user_id' => $admin->id,
            'token' => str_repeat('a', 100),
            'token_hash' => hash('sha256', 'admin-device'),
        ]);

        $response = $this->actingAs($admin)->postJson('/admin/api/announcements', [
            'audience' => 'me',
            'title' => 'TruthGuard maintenance advisory',
            'message' => 'TruthGuard will have a short system update window.',
            'update_at' => '2026-10-01 10:00:00',
            'action_url' => '/notifications',
            'action_label' => 'View notice',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('announcement.audience', 'Me only')
            ->assertJsonPath('announcement.recipients', 1)
            ->assertJsonPath('announcement.notificationsCreated', 1)
            ->assertJsonPath('announcement.pushJobsQueued', 1);

        $notification = $admin->notifications()->firstOrFail();

        $this->assertSame('TruthGuard maintenance advisory', $notification->data['title']);
        $this->assertTrue($notification->data['admin_announcement']);
        $this->assertStringContainsString('Update window: Oct 1, 2026 10:00 AM', $notification->data['message']);

        Queue::assertPushed(SendPushNotification::class, 1);
    }

    public function test_announcements_respect_system_notification_preferences(): void
    {
        Queue::fake();
        config(['firebase.enabled' => false]);

        $admin = User::factory()->create(['is_admin' => true]);
        $enabledUser = User::factory()->create(['is_admin' => false]);
        $disabledUser = User::factory()->create(['is_admin' => false]);
        NotificationPreference::create([
            'user_id' => $disabledUser->id,
            'system_notifications' => false,
        ]);

        $response = $this->actingAs($admin)->postJson('/admin/api/announcements', [
            'audience' => 'users',
            'title' => 'TruthGuard update',
            'message' => 'A platform advisory for regular users.',
            'action_url' => '/notifications',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('announcement.recipients', 2)
            ->assertJsonPath('announcement.notificationsCreated', 1)
            ->assertJsonPath('announcement.pushJobsQueued', 0);

        $this->assertSame(1, $enabledUser->notifications()->count());
        $this->assertSame(0, $disabledUser->notifications()->count());
        Queue::assertNothingPushed();
    }

    public function test_non_admin_is_redirected_from_announcements_api(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/admin/api/announcements')
            ->assertRedirect(route('dashboard', absolute: false));
    }
}
