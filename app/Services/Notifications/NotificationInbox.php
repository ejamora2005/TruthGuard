<?php

namespace App\Services\Notifications;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Schema;

/** Shared visibility and destinations for the bell and the activity inbox. */
final class NotificationInbox
{
    public static function visible(User $user, bool $unread = false): MorphMany
    {
        $query = $unread ? $user->unreadNotifications() : $user->notifications();
        // Retain the row/event key so dismissed one-time events are not resent.
        $query->whereNull('data->dismissed_at');
        if (Schema::hasColumn('notifications', 'archived_at')) {
            $query->whereNull('archived_at');
        }

        return $query;
    }

    public static function resultUrl(array $data): ?string
    {
        $url = $data['action_url'] ?? null;

        return is_string($url) && preg_match('~^/detections/[0-9]+/result$~D', $url) ? $url : null;
    }

    public static function format(DatabaseNotification $notification): array
    {
        $data = $notification->data ?: [];
        $message = (string) data_get($data, 'message', 'Open TruthGuard to review the latest update.');
        if (preg_match('/TG-\d+/i', $message)) {
            $confidence = (int) data_get($data, 'confidence', 0);
            $message = ((string) data_get($data, 'category') === 'fact-check')
                ? ($confidence > 0 ? "TruthGuard completed your latest fact check with {$confidence}% confidence." : 'TruthGuard completed your latest fact check.')
                : trim((string) preg_replace('/\s*TG-\d+\s*/i', ' ', $message));
        }
        $resultUrl = self::resultUrl($data);

        return [
            'id' => (string) $notification->id,
            'title' => (string) data_get($data, 'title', 'TruthGuard notification'),
            'message' => $message,
            'category' => (string) data_get($data, 'category', 'system'),
            'actionUrl' => $resultUrl ?? route('notifications.show', $notification->id, absolute: false),
            'actionLabel' => $resultUrl ? 'View result' : 'Read notification',
            'icon' => (string) data_get($data, 'icon', 'bell'),
            'imageUrl' => (string) data_get($data, 'image_url', ''),
            'publisher' => (string) data_get($data, 'publisher', ''),
            'rating' => (string) data_get($data, 'rating', ''),
            'createdAt' => $notification->created_at?->diffForHumans() ?? '',
            'unread' => $notification->read_at === null,
        ];
    }
}
