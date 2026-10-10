<?php

namespace App\Jobs;

use App\Models\NotificationPreference;
use App\Models\PushDelivery;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Notifications\NotificationUrl;
use App\Services\Notifications\TruthGuardPushNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

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
        if (! config('firebase.enabled')) {
            return;
        }
        $lock = Cache::lock('push-delivery:'.$this->subscriptionId.':'.$this->notificationId, 60);
        if (! $lock->get()) {
            throw new RuntimeException('Push delivery is already being processed.');
        }
        try {
            $this->deliver($push);
        } finally {
            $lock->release();
        }
    }

    private function deliver(TruthGuardPushNotificationService $push): void
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
        $delivery = PushDelivery::firstOrCreate([
            'push_subscription_id' => $subscription->id,
            'notification_id' => $notification->id,
        ]);
        if ($delivery->status === 'sent') {
            return;
        }
        $delivery->increment('attempts');
        try {
            $sent = $push->send($subscription, [
                'subscription_id' => (string) $subscription->id,
                'title' => $data['title'],
                'body' => $data['message'],
                'url' => NotificationUrl::safe($data['action_url'] ?? null),
                'notification_id' => $notification->id,
                'timestamp' => (string) ($notification->created_at->timestamp * 1000),
            ]);
            if ($sent) {
                $delivery->update(['status' => 'sent', 'sent_at' => now(), 'last_error' => null]);
            }
        } catch (Throwable $error) {
            // Only the service's explicitly sanitized errors may reach logs or settings.
            $message = $error instanceof RuntimeException && str_starts_with($error->getMessage(), 'FCM ')
                ? $error->getMessage() : 'Push delivery failed. Check server configuration and worker logs.';
            $delivery->update(['status' => 'retrying', 'last_error' => $message]);
            Log::warning($message, ['notification_id' => $this->notificationId, 'subscription_id' => $this->subscriptionId]);
            throw new RuntimeException($message);
        }
    }

    public function failed(?Throwable $error): void
    {
        PushDelivery::where('push_subscription_id', $this->subscriptionId)
            ->where('notification_id', $this->notificationId)
            ->where('status', '!=', 'sent')->update(['status' => 'failed']);
    }
}
