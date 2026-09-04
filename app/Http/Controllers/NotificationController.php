<?php

namespace App\Http\Controllers;

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
    ) {
    }

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
        ]);
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

        if ($this->supportsArchivedNotifications()) {
            $notification->forceFill([
                'read_at' => $notification->read_at ?? now(),
                'archived_at' => now(),
            ])->save();
        }

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
        $query = $request->user()->notifications();

        if ($this->supportsArchivedNotifications()) {
            $query->whereNull('archived_at');
        }

        return $query;
    }

    private function visibleUnreadNotifications(Request $request)
    {
        $query = $request->user()->unreadNotifications();

        if ($this->supportsArchivedNotifications()) {
            $query->whereNull('archived_at');
        }

        return $query;
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
        $data = $notification->data ?: [];

        return [
            'id' => (string) $notification->id,
            'title' => (string) data_get($data, 'title', 'TruthGuard notification'),
            'message' => $this->sanitizeNotificationMessage(
                (string) data_get($data, 'message', 'Open TruthGuard to review the latest update.'),
                $data
            ),
            'category' => (string) data_get($data, 'category', 'system'),
            'actionUrl' => (string) data_get($data, 'action_url', route('notifications.index', absolute: false)),
            'actionLabel' => (string) data_get($data, 'action_label', 'Open'),
            'icon' => (string) data_get($data, 'icon', 'bell'),
            'imageUrl' => (string) data_get($data, 'image_url', ''),
            'publisher' => (string) data_get($data, 'publisher', ''),
            'rating' => (string) data_get($data, 'rating', ''),
            'createdAt' => $notification->created_at?->diffForHumans() ?? '',
            'unread' => $notification->read_at === null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function sanitizeNotificationMessage(string $message, array $data): string
    {
        if (! preg_match('/TG-\d+/i', $message)) {
            return $message;
        }

        $confidence = (int) data_get($data, 'confidence', 0);

        if ((string) data_get($data, 'category') === 'fact-check') {
            return $confidence > 0
                ? "TruthGuard completed your latest fact check with {$confidence}% confidence."
                : 'TruthGuard completed your latest fact check.';
        }

        return trim((string) preg_replace('/\s*TG-\d+\s*/i', ' ', $message));
    }
}
