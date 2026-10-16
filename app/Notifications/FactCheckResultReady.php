<?php

namespace App\Notifications;

use App\Models\Detection;
use App\Services\Detections\DetectionVerdictPresenter;
use App\Services\Detections\ReportAnswer;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class FactCheckResultReady extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Detection $detection,
    ) {}

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
        $kind = $this->reportKind();
        $summary = trim((string) $this->detection->analysis_summary) !== ''
            ? Str::limit((string) $this->detection->analysis_summary, 180)
            : 'Open the result to review the evidence, sources, and recommendation.';

        return (new MailMessage)
            ->subject("TruthGuard {$kind} ready: {$verdict}")
            ->greeting('Your TruthGuard result is ready')
            ->line("We finished analyzing your latest submission. TruthGuard categorized it as {$verdict}.".$this->confidenceSentence())
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
        $kind = ucfirst($this->reportKind());

        return [
            'category' => 'fact-check',
            'title' => "{$kind} result ready: {$verdict}",
            'message' => "TruthGuard categorized your latest submission as {$verdict}.".$this->confidenceSentence(),
            'action_url' => route('detections.result', $this->detection, absolute: false),
            'action_label' => 'Open result',
            'icon' => 'scan',
            'detection_id' => $this->detection->id,
            'verdict' => (string) $this->detection->verdict,
            'confidence' => $confidence,
        ];
    }

    private function confidencePercent(): ?int
    {
        return DetectionVerdictPresenter::confidencePercent($this->detection);
    }

    private function verdictLabel(): string
    {
        $presentation = DetectionVerdictPresenter::forDetection($this->detection);

        return is_array($this->detection->fact_check_result) ? $presentation['history_label'] : $presentation['notification_label'];
    }

    private function reportKind(): string
    {
        if (! is_array($this->detection->fact_check_result)) {
            return 'fact check';
        }
        $result = ReportAnswer::forPresentation($this->detection->fact_check_result);
        $tasks = $result['request']['tasks'];
        if (array_intersect($tasks, ['claim', 'context']) !== [] && array_intersect($tasks, ['media', 'manipulation']) !== []) {
            return 'content verification';
        }

        return match ($result['primary_task']) {
            'context' => 'context check', 'media', 'manipulation' => 'media analysis', default => 'fact check',
        };
    }

    private function confidenceSentence(): string
    {
        if ($this->confidencePercent() === null) {
            return '';
        }
        $result = ReportAnswer::forPresentation($this->detection->fact_check_result);

        return ' '.$result['score_display'].'. '.$result['score']['explanation'];
    }
}
