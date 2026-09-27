<?php

namespace App\Jobs;

use App\Models\PublicClaimReviewAnnouncement;
use App\Models\User;
use App\Services\Notifications\NotificationEventService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class AnnounceFactCheck implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $announcementId) {}

    public function handle(NotificationEventService $events): void
    {
        $announcement = PublicClaimReviewAnnouncement::find($this->announcementId);
        if (! $announcement) {
            return;
        }
        User::where('subscription_status', 'active')->chunkById(100, function ($users) use ($events, $announcement) {
            foreach ($users as $user) {
                $events->send($user, 'announcement:'.$announcement->id, 'new_fact_checks',
                    'New Fact-Check Available', 'A new fact-check has been published. Tap to view the sources and findings.',
                    route('dashboard.fact-check', $announcement->feed_item_id, false));
            }
        });
    }
}
