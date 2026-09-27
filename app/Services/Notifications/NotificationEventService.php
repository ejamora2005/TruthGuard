<?php

namespace App\Services\Notifications;

use App\Jobs\SendPushNotification;
use App\Models\Detection;
use App\Models\NotificationPreference;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NotificationEventService
{
    public function analysisComplete(Detection $detection): void
    {
        if ($detection->processing_status !== 'completed' || ! $detection->user) {
            return;
        }
        $media = in_array($detection->media_type, ['image', 'video'], true) ? $detection->media_type : 'content';
        $this->send($detection->user, 'analysis:'.$detection->id, 'analysis_results',
            'TruthGuard Analysis Complete', "Your submitted {$media} has finished processing. Tap to view the analysis.",
            route('detections.result', $detection, false));
    }

    public function factCheckUpdate(Detection $detection): void
    {
        if ($detection->processing_status !== 'completed' || ! $detection->user) {
            return;
        }
        $revision = hash('sha256', json_encode($detection->verification_sources));
        $this->send($detection->user, "evidence:{$detection->id}:{$revision}", 'fact_check_updates',
            'TruthGuard Fact-Check Update', 'New supporting information is available for content you previously checked.',
            route('detections.result', $detection, false));
    }

    public function system(User $user, string $eventKey, string $message, string $url = '/notifications'): ?DatabaseNotification
    {
        return $this->send($user, 'system:'.$eventKey, 'system_notifications', 'TruthGuard', $message, $url);
    }

    public function send(User $user, string $eventKey, string $preference, string $title, string $message, string $url): ?DatabaseNotification
    {
        $preferences = NotificationPreference::forUser($user);
        if (! ($preferences[$preference] ?? false) || $preference === 'push_enabled') {
            return null;
        }

        return DB::transaction(function () use ($user, $eventKey, $preference, $title, $message, $url, $preferences) {
            $notification = $user->notifications()->createOrFirst([
                'event_key' => hash('sha256', $user->id.':'.$eventKey),
            ], [
                'id' => (string) Str::uuid(),
                'type' => self::class,
                'data' => [
                    'preference' => $preference,
                    'category' => match ($preference) {
                        'analysis_results', 'fact_check_updates' => 'fact-check',
                        'new_fact_checks' => 'news',
                        default => 'system',
                    },
                    'title' => $title,
                    'message' => $message,
                    'action_url' => NotificationUrl::safe($url),
                    'action_label' => 'Open',
                    'icon' => 'bell',
                ],
            ]);
            if ($notification->wasRecentlyCreated && $preferences['push_enabled'] && config('firebase.enabled')) {
                PushSubscription::where('user_id', $user->id)->each(function ($subscription) use ($notification) {
                    SendPushNotification::dispatch($subscription->id, $notification->id)->afterCommit();
                });
            }

            return $notification;
        });
    }
}
