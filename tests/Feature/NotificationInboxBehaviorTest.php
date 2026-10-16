<?php

namespace Tests\Feature;

use App\Models\Detection;
use App\Models\User;
use App\Notifications\WelcomeToTruthGuard;
use App\Services\Notifications\NotificationEventService;
use App\Services\Notifications\NotificationInbox;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NotificationInboxBehaviorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config()->set('firebase.enabled', false);
    }

    public static function archiveSchemas(): array
    {
        return ['archive column present' => [true], 'archive column missing' => [false]];
    }

    #[DataProvider('archiveSchemas')]
    public function test_dismissal_survives_polls_and_page_loads_without_resending_one_time_events(bool $archiveColumn): void
    {
        if (! $archiveColumn) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->dropIndex(['notifiable_type', 'notifiable_id', 'archived_at']);
                $table->dropColumn('archived_at');
            });
        }
        $user = User::factory()->create();
        $user->notify(new WelcomeToTruthGuard);
        $welcome = $user->notifications()->sole();
        $notice = app(NotificationEventService::class)->system($user, 'dismiss-once', 'Dismiss this once.');
        $this->actingAs($user);
        foreach ([$welcome, $notice] as $record) {
            $this->deleteJson(route('notifications.destroy', $record))->assertOk()->assertJsonPath('ok', true);
            $this->assertNotNull($record->fresh()->data['dismissed_at']);
            if ($archiveColumn) {
                $this->assertNotNull($record->fresh()->archived_at);
            }
        }
        foreach (range(1, 2) as $refresh) {
            $this->getJson(route('notifications.peek'))->assertOk()->assertJsonCount(0, 'notifications')->assertJsonPath('unreadCount', 0);
            $this->get(route('notifications.index'))->assertOk()->assertViewHas('notifications', fn ($rows) => $rows->isEmpty())
                ->assertDontSee('Dismiss this once.')->assertDontSee('Welcome to TruthGuard');
        }
        $again = app(NotificationEventService::class)->system($user, 'dismiss-once', 'Dismiss this once.');
        $this->assertSame($notice->id, $again->id);
        $this->assertSame(2, $user->notifications()->count());
        $this->assertSame(0, NotificationInbox::visible($user)->count());
        $this->get(route('notifications.show', $notice->id))->assertNotFound();
        Http::assertNothingSent();
    }

    public static function layouts(): array
    {
        return ['user' => [false], 'admin' => [true]];
    }

    #[DataProvider('layouts')]
    public function test_results_link_directly_from_both_initial_layout_and_polled_inbox(bool $admin): void
    {
        $user = User::factory()->create(['is_admin' => $admin]);
        $detection = Detection::create(['user_id' => $user->id, 'source_kind' => 'upload', 'media_type' => 'image',
            'processing_status' => 'completed', 'verdict' => 'review', 'fake_score' => 0, 'analysis_summary' => 'Saved report.']);
        app(NotificationEventService::class)->analysisComplete($detection);
        $notification = $user->notifications()->where('data->category', 'fact-check')->sole();
        $target = route('detections.result', $detection, absolute: false);
        $this->actingAs($user);
        $response = $this->get(route('notifications.index'))->assertOk()
            ->assertViewHas('notifications', fn ($rows) => $rows->firstWhere('id', $notification->id)['actionUrl'] === $target
                && $rows->firstWhere('id', $notification->id)['actionLabel'] === 'View result');
        $this->assertStringContainsString('View result', $response->getContent());
        $this->getJson(route('notifications.peek'))->assertOk()->assertJsonFragment(['actionUrl' => $target, 'actionLabel' => 'View result']);
        $this->get(route('notifications.show', $notification->id))->assertRedirect($target);
        $this->assertNotNull($notification->fresh()->read_at);
        $this->actingAs(User::factory()->create())->get(route('notifications.show', $notification->id))->assertNotFound();
        if (getenv('TRUTHGUARD_NOTIFICATION_BROWSER_FIXTURE')) {
            foreach (range(2, 7) as $index) {
                app(NotificationEventService::class)->send($user, 'browser-fixture-'.$index, 'analysis_results',
                    'Analysis report '.$index, 'A saved analysis is ready to view.', $target);
            }
            $fixture = $this->actingAs($user)->get(route('notifications.index'))->assertOk();
            file_put_contents(storage_path('tmp-notification-'.($admin ? 'admin' : 'user').'.html'), $fixture->getContent());
        }
        Http::assertNothingSent();
    }

    public function test_non_result_notifications_keep_details_and_unsafe_destinations_are_not_direct_links(): void
    {
        $user = User::factory()->create();
        $notification = app(NotificationEventService::class)->system($user, 'normal-detail', 'A system update.', '/profile');
        $details = route('notifications.show', $notification->id, absolute: false);
        $this->assertSame($details, NotificationInbox::format($notification)['actionUrl']);
        foreach (['//evil.example/detections/1/result', 'https://evil.example/detections/1/result', '/detections/1/result/../logout'] as $unsafe) {
            $data = $notification->data;
            $data['action_url'] = $unsafe;
            $notification->data = $data;
            $this->assertSame($details, NotificationInbox::format($notification)['actionUrl']);
        }
        $this->actingAs($user)->get($details)->assertOk()->assertSee('A system update.');
        Http::assertNothingSent();
    }
}
