<?php

namespace App\Http\Controllers;

use App\Jobs\SendPushNotification;
use App\Models\NotificationPreference;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Notifications\NotificationEventService;
use App\Services\Notifications\NotificationUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminAnnouncementController extends Controller
{
    public function index(): JsonResponse
    {
        $totalUsers = User::query()->count();
        $systemOptedOut = NotificationPreference::query()
            ->where('system_notifications', false)
            ->count();
        $pushReadyUsers = DB::table('push_subscriptions')
            ->join('notification_preferences', 'push_subscriptions.user_id', '=', 'notification_preferences.user_id')
            ->where('notification_preferences.push_enabled', true)
            ->where('notification_preferences.system_notifications', true)
            ->distinct()
            ->count('push_subscriptions.user_id');

        return response()->json([
            'summaryCards' => [
                [
                    'label' => 'Accounts',
                    'value' => number_format($totalUsers),
                    'note' => number_format(max(0, $totalUsers - $systemOptedOut)).' allow system notices',
                ],
                [
                    'label' => 'Push-ready users',
                    'value' => number_format($pushReadyUsers),
                    'note' => number_format(PushSubscription::query()->count()).' subscribed devices',
                ],
                [
                    'label' => 'Firebase',
                    'value' => config('firebase.enabled') ? 'Enabled' : 'Disabled',
                    'note' => config('firebase.enabled') ? 'Push jobs can be queued' : 'Only in-app notices will be created',
                ],
                [
                    'label' => 'Queue',
                    'value' => (string) config('queue.default', 'sync'),
                    'note' => 'Push delivery runs through queued jobs',
                ],
            ],
            'recentAnnouncements' => $this->recentAnnouncements(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->validate([
            'audience' => ['required', Rule::in(['me', 'all', 'users', 'admins'])],
            'title' => ['required', 'string', 'min:3', 'max:90'],
            'message' => ['required', 'string', 'min:8', 'max:600'],
            'update_at' => ['nullable', 'date'],
            'action_url' => ['nullable', 'string', 'max:2048'],
            'action_label' => ['nullable', 'string', 'max:24'],
        ]);

        $announcementId = (string) Str::uuid();
        $updateAt = filled($input['update_at'] ?? null)
            ? Carbon::parse((string) $input['update_at'], config('app.timezone', 'UTC'))
            : null;
        $message = trim((string) $input['message']);

        if ($updateAt) {
            $message .= "\n\nUpdate window: ".$updateAt->format('M j, Y g:i A');
        }

        $title = trim((string) $input['title']);
        $actionUrl = NotificationUrl::safe($input['action_url'] ?? '/notifications');
        $actionLabel = filled($input['action_label'] ?? null) ? trim((string) $input['action_label']) : 'Open';
        $audience = (string) $input['audience'];
        $recipients = 0;
        $notificationsCreated = 0;
        $pushJobsQueued = 0;

        $this->audienceQuery($request->user(), $audience)
            ->chunkById(100, function ($users) use (
                $request,
                $announcementId,
                $audience,
                $title,
                $message,
                $actionUrl,
                $actionLabel,
                $updateAt,
                &$recipients,
                &$notificationsCreated,
                &$pushJobsQueued
            ): void {
                foreach ($users as $user) {
                    $recipients++;
                    $preferences = NotificationPreference::forUser($user);

                    if (! ($preferences['system_notifications'] ?? true)) {
                        continue;
                    }

                    $notification = $user->notifications()->create([
                        'id' => (string) Str::uuid(),
                        'type' => NotificationEventService::class,
                        'event_key' => hash('sha256', $user->id.':admin-announcement:'.$announcementId),
                        'data' => [
                            'preference' => 'system_notifications',
                            'category' => 'system',
                            'title' => $title,
                            'message' => $message,
                            'action_url' => $actionUrl,
                            'action_label' => $actionLabel,
                            'icon' => 'bell',
                            'admin_announcement' => true,
                            'announcement_id' => $announcementId,
                            'audience' => $audience,
                            'update_at' => $updateAt?->toIso8601String(),
                            'sent_by' => $request->user()->name,
                        ],
                    ]);
                    $notificationsCreated++;

                    if (! config('firebase.enabled') || ! ($preferences['push_enabled'] ?? false)) {
                        continue;
                    }

                    PushSubscription::query()
                        ->where('user_id', $user->id)
                        ->each(function (PushSubscription $subscription) use ($notification, &$pushJobsQueued): void {
                            SendPushNotification::dispatch($subscription->id, $notification->id)->afterCommit();
                            $pushJobsQueued++;
                        });
                }
            });

        return response()->json([
            'message' => 'Announcement sent.',
            'announcement' => [
                'id' => $announcementId,
                'title' => $title,
                'audience' => $this->audienceLabel($audience),
                'recipients' => $recipients,
                'notificationsCreated' => $notificationsCreated,
                'pushJobsQueued' => $pushJobsQueued,
                'firebaseEnabled' => (bool) config('firebase.enabled'),
                'updateAtLabel' => $updateAt?->format('M j, Y g:i A'),
            ],
            'recentAnnouncements' => $this->recentAnnouncements(),
        ], 201);
    }

    private function audienceQuery(User $admin, string $audience)
    {
        return match ($audience) {
            'me' => User::query()->whereKey($admin->id),
            'users' => User::query()->where('is_admin', false),
            'admins' => User::query()->where('is_admin', true),
            default => User::query(),
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recentAnnouncements(): array
    {
        return DatabaseNotification::query()
            ->where('type', NotificationEventService::class)
            ->latest()
            ->limit(80)
            ->get()
            ->filter(fn (DatabaseNotification $notification): bool => (bool) data_get($notification->data, 'admin_announcement', false))
            ->unique(fn (DatabaseNotification $notification): string => (string) data_get($notification->data, 'announcement_id', $notification->id))
            ->take(8)
            ->map(function (DatabaseNotification $notification): array {
                $data = $notification->data ?: [];
                $updateAt = data_get($data, 'update_at');

                return [
                    'id' => (string) data_get($data, 'announcement_id', $notification->id),
                    'title' => (string) data_get($data, 'title', 'TruthGuard announcement'),
                    'message' => Str::limit((string) data_get($data, 'message', ''), 160),
                    'audience' => $this->audienceLabel((string) data_get($data, 'audience', 'all')),
                    'sentBy' => (string) data_get($data, 'sent_by', 'Admin'),
                    'sentAt' => $notification->created_at?->format('M j, Y g:i A') ?? '',
                    'updateAt' => is_string($updateAt) && $updateAt !== ''
                        ? Carbon::parse($updateAt)->format('M j, Y g:i A')
                        : null,
                ];
            })
            ->values()
            ->all();
    }

    private function audienceLabel(string $audience): string
    {
        return match ($audience) {
            'me' => 'Me only',
            'users' => 'Regular users',
            'admins' => 'Admins',
            default => 'All accounts',
        };
    }
}
