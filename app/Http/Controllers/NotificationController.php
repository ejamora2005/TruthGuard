<?php

namespace App\Http\Controllers;

use App\Services\Notifications\NotificationInbox;
use App\Services\Notifications\NotificationUrl;
use App\Services\Notifications\TruthGuardNotificationManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function __construct(
        private readonly TruthGuardNotificationManager $notifications,
    ) {}

    public function index(Request $request): View
    {
        $notifications = $this->visibleNotifications($request)
            ->latest()
            ->take(100)
            ->get()
            ->map(fn (DatabaseNotification $notification) => $this->formatNotification($notification))
            ->values();

        return view('notifications.index', [
            'notifications' => $notifications,
            'counts' => [
                'all' => $notifications->count(),
                'unread' => $notifications->where('unread', true)->count(),
                'fact_check' => $notifications->where('category', 'fact-check')->count(),
                'news' => $notifications->where('category', 'news')->count(),
                'security' => $notifications->where('category', 'security')->count(),
                'system' => $notifications->whereIn('category', ['system', 'welcome'])->count(),
            ],
        ]);
    }

    public function show(Request $request, string $notification): View|RedirectResponse
    {
        $record = $this->visibleNotifications($request)->whereKey($notification)->firstOrFail();
        $record->markAsRead();

        if ($resultUrl = NotificationInbox::resultUrl($record->data ?: [])) {
            return redirect($resultUrl);
        }

        return view('notifications.show', [
            'notification' => $this->formatNotification($record),
            'actionUrl' => NotificationUrl::safe($record->data['action_url'] ?? null),
            'actionLabel' => $record->data['action_label'] ?? 'Open related page',
        ]);
    }

    public function peek(Request $request): JsonResponse
    {
        $this->notifications->sendWelcomeOnce($request->user());

        $notifications = $this->visibleNotifications($request)
            ->latest()
            ->take(5)
            ->get()
            ->map(fn (DatabaseNotification $notification) => $this->formatNotification($notification))
            ->values();

        $latestUnread = $this->visibleUnreadNotifications($request)
            ->latest()
            ->first();

        return response()->json([
            'unreadCount' => $this->visibleUnreadNotifications($request)->count(),
            'latestId' => $latestUnread?->id,
            'notifications' => $notifications,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function markAsRead(Request $request, DatabaseNotification $notification): JsonResponse|RedirectResponse
    {
        $this->authorizeNotification($request, $notification);
        $notification->markAsRead();

        if ($request->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        return back();
    }

    public function markAllAsRead(Request $request): JsonResponse|RedirectResponse
    {
        $this->visibleUnreadNotifications($request)->update(['read_at' => now()]);

        if ($request->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        return back();
    }

    public function destroy(Request $request, DatabaseNotification $notification): JsonResponse|RedirectResponse
    {
        $this->authorizeNotification($request, $notification);

        $updates = [
            'read_at' => $notification->read_at ?? now(),
            'data' => array_replace($notification->data ?: [], ['dismissed_at' => now()->toIso8601String()]),
        ];
        if ($this->supportsArchivedNotifications()) {
            $updates['archived_at'] = now();
        }
        $notification->forceFill($updates)->save();

        if ($request->expectsJson()) {
            $latestUnread = $this->visibleUnreadNotifications($request)
                ->latest()
                ->first();

            return response()->json([
                'ok' => true,
                'unreadCount' => $this->visibleUnreadNotifications($request)->count(),
                'latestId' => $latestUnread?->id,
            ]);
        }

        return back()->with('status', 'notification-archived');
    }

    private function visibleNotifications(Request $request)
    {
        return NotificationInbox::visible($request->user());
    }

    private function visibleUnreadNotifications(Request $request)
    {
        return NotificationInbox::visible($request->user(), unread: true);
    }

    private function supportsArchivedNotifications(): bool
    {
        return Schema::hasTable('notifications')
            && Schema::hasColumn('notifications', 'archived_at');
    }

    private function authorizeNotification(Request $request, DatabaseNotification $notification): void
    {
        $user = $request->user();

        abort_unless(
            (string) $notification->notifiable_id === (string) $user->getKey()
            && $notification->notifiable_type === $user->getMorphClass(),
            404
        );
    }

    private function formatNotification(DatabaseNotification $notification): array
    {
        return NotificationInbox::format($notification);
    }
}
