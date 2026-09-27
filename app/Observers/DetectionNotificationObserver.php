<?php

namespace App\Observers;

use App\Models\Detection;
use App\Services\Notifications\NotificationEventService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class DetectionNotificationObserver implements ShouldHandleEventsAfterCommit
{
    public function created(Detection $detection): void
    {
        app(NotificationEventService::class)->analysisComplete($detection);
    }

    public function updated(Detection $detection): void
    {
        if ($detection->wasChanged('processing_status') && $detection->processing_status === 'completed') {
            app(NotificationEventService::class)->analysisComplete($detection);
        } elseif ($detection->wasChanged('verification_sources') && ! empty($detection->verification_sources)) {
            app(NotificationEventService::class)->factCheckUpdate($detection);
        }
    }
}
