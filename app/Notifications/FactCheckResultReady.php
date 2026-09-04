<?php

namespace App\Notifications;

use App\Models\Detection;
use App\Services\Detections\DetectionVerdictPresenter;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class FactCheckResultReady extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Detection $detection,
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $verdict = $this->verdictLabel();
        $confidence = $this->confidencePercent();
        $summary = trim((string) $this->detection->analysis_summary) !== ''
            ? Str::limit((string) $this->detection->analysis_summary, 180)
            : 'Open the result to review the evidence, sources, and recommendation.';

        return (new MailMessage)
            ->subject("TruthGuard fact check ready: {$verdict}")
            ->greeting('Your TruthGuard result is ready')
            ->line("We finished analyzing your latest submission. TruthGuard categorized it as {$verdict} with {$confidence}% confidence.")
            ->line($summary)
            ->action('Open result', route('detections.result', $this->detection))
            ->line('AI can make mistakes. Verify important details before sharing.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $verdict = $this->verdictLabel();
        $confidence = $this->confidencePercent();

        return [
            'category' => 'fact-check',
            'title' => "Fact check result ready: {$verdict}",
            'message' => "TruthGuard categorized your latest fact check as {$verdict} with {$confidence}% confidence.",
            'action_url' => route('detections.result', $this->detection, absolute: false),
            'action_label' => 'Open result',
            'icon' => 'scan',
            'detection_id' => $this->detection->id,
            'verdict' => (string) $this->detection->verdict,
            'confidence' => $confidence,
        ];
    }

    private function confidencePercent(): int
    {
        return DetectionVerdictPresenter::confidencePercent($this->detection);
    }

    private function verdictLabel(): string
    {
        return DetectionVerdictPresenter::forDetection($this->detection)['notification_label'];
    }
}
