<?php

namespace App\Notifications;

use App\Models\PublicClaimReviewAnnouncement;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PublicClaimReviewPublished extends Notification
{
    use Queueable;

    public function __construct(
        private readonly PublicClaimReviewAnnouncement $announcement,
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        if (Schema::hasColumn('users', 'email_updates_enabled') && ! (bool) ($notifiable->email_updates_enabled ?? false)) {
            return [];
        }

        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $publisher = $this->publisherLabel();

        return (new MailMessage)
            ->subject("New public claim review from {$publisher}")
            ->markdown('emails.public-claim-review-published', [
                'announcement' => $this->announcement,
                'publisher' => $publisher,
                'actionUrl' => $this->actionUrl(),
                'rating' => trim((string) $this->announcement->rating),
                'imageUrl' => trim((string) $this->announcement->image_url),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'category' => 'news',
            'title' => 'Latest public claim review',
            'message' => Str::limit(collect([
                $this->publisherLabel(),
                filled($this->announcement->rating) ? 'rated '.$this->announcement->rating : null,
                $this->announcement->headline,
            ])->filter()->implode(' - '), 180),
            'action_url' => $this->actionUrl(),
            'action_label' => 'Read review',
            'icon' => 'newspaper',
            'claim_review_id' => $this->announcement->feed_item_id,
            'public_claim_review_announcement_id' => $this->announcement->id,
            'publisher' => $this->announcement->publisher,
            'rating' => $this->announcement->rating,
            'image_url' => $this->announcement->image_url,
        ];
    }

    private function actionUrl(): string
    {
        $url = trim((string) $this->announcement->url);

        return $url !== '' ? $url : route('notifications.index', absolute: false);
    }

    private function publisherLabel(): string
    {
        $publisher = trim((string) $this->announcement->publisher);

        return $publisher !== '' ? $publisher : 'Fact-check partner';
    }
}
