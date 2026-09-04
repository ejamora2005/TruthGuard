<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Schema;

class WelcomeToTruthGuard extends Notification
{
    use Queueable;

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return Schema::hasTable('notifications')
            ? ['database', 'mail']
            : ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = trim((string) ($notifiable->name ?? ''));

        return (new MailMessage)
            ->subject('Welcome to TruthGuard')
            ->greeting($name !== '' ? "Welcome to TruthGuard, {$name}!" : 'Welcome to TruthGuard!')
            ->line('Your account is ready. You can now upload evidence, paste source links, and review your fact-check history from your workspace.')
            ->action('Start fact checking', route('detections.create'))
            ->line('Thanks for helping keep shared information grounded and clear.');
    }

    /**
     * @return array<string, string>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'category' => 'welcome',
            'title' => 'Welcome to TruthGuard',
            'message' => 'Your workspace is ready. Start your first fact check or review your notification center anytime.',
            'action_url' => route('detections.create', absolute: false),
            'action_label' => 'Start fact checking',
            'icon' => 'shield-check',
        ];
    }
}
