<?php

namespace App\Services\Notifications;

use App\Models\Detection;
use App\Models\User;
use App\Notifications\WelcomeToTruthGuard;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Schema;
use Throwable;

class TruthGuardNotificationManager
{
    public function sendWelcomeOnce(User $user): void
    {
        $this->sendOnce($user, WelcomeToTruthGuard::class, new WelcomeToTruthGuard);
    }

    public function sendFactCheckResultOnce(Detection $detection): void
    {
        // Verification results are available on the result page; users should not get a separate alert.
    }

    /**
     * @param  class-string<Notification>  $type
     */
    private function sendOnce(User $user, string $type, Notification $notification, ?callable $matches = null): void
    {
        if ($this->alreadySent($user, $type, $matches)) {
            return;
        }

        try {
            $user->notify($notification);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @param  class-string<Notification>  $type
     */
    private function alreadySent(User $user, string $type, ?callable $matches = null): bool
    {
        if (! Schema::hasTable('notifications')) {
            return false;
        }

        $query = $user->notifications()->where('type', $type);

        if ($matches === null) {
            return $query->exists();
        }

        return $query
            ->latest()
            ->take(100)
            ->get()
            ->contains(fn (DatabaseNotification $notification): bool => $matches($notification));
    }
}
