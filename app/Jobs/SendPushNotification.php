<?php

namespace App\Jobs;

use App\Models\NotificationPreference;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Notifications\NotificationUrl;
use App\Services\Notifications\TruthGuardPushNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Notifications\DatabaseNotification;

class SendPushNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 45;

    public function __construct(public int $subscriptionId, public string $notificationId) {}

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(TruthGuardPushNotificationService $push): void
    {
        $subscription = PushSubscription::find($this->subscriptionId);
        $notification = DatabaseNotification::find($this->notificationId);
        if (! $subscription || ! $notification || $notification->archived_at) {
            return;
        }
        $user = User::find($subscription->user_id);
        if (! $user || (string) $notification->notifiable_id !== (string) $user->id || $notification->notifiable_type !== $user->getMorphClass()) {
            return;
        }
        $preferences = NotificationPreference::forUser($user);
        $data = $notification->data;
        if (! $preferences['push_enabled'] || ! ($preferences[$data['preference'] ?? ''] ?? false)) {
            return;
        }
        $push->send($subscription, [
            'title' => $data['title'],
            'body' => $data['message'],
            'url' => NotificationUrl::safe($data['action_url'] ?? null),
            'notification_id' => $notification->id,
            'timestamp' => (string) ($notification->created_at->timestamp * 1000),
        ]);
    }
}
