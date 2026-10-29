<?php

namespace App\Jobs;

use App\Models\Detection;
use App\Services\Automation\RelatedPostScraper;
use App\Services\Detections\DetectionPipeline;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class AnalyzeDetectionEvidence implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 360;

    // Defaults also cover jobs serialized before retry support shipped.
    public ?string $retryId = null;

    public string $previousStatus = 'completed';

    public function __construct(
        public int $detectionId,
        public array $context,
        ?string $retryId = null,
        string $previousStatus = 'completed',
    ) {
        $this->retryId = $retryId;
        $this->previousStatus = $previousStatus;
        $this->onConnection('evidence')->onQueue('evidence');
    }

    public function uniqueId(): string
    {
        return $this->detectionId.($this->retryId ? ':'.$this->retryId : '');
    }

    public function handle(DetectionPipeline $pipeline): void
    {
        $detection = Detection::find($this->detectionId);
        if (! $detection || ! $detection->user
            || ($this->retryId === null && $detection->fact_check_result !== null)
            || ($this->retryId !== null && data_get($detection->signals, 'analysis_retry.id') !== $this->retryId)) {
            return;
        }
        // Claim once even if the broker redelivers a job or its unique lock expires.
        if (! Detection::whereKey($this->detectionId)->where('processing_status', 'queued')
            ->update(['processing_status' => 'processing'])) {
            return;
        }
        $detection->refresh();
        $file = null;
        if ($detection->media_path) {
            $path = Storage::disk('public')->path($detection->media_path);
            if (! is_file($path)) {
                throw new \RuntimeException('Submitted media is no longer available.');
            }
            $file = new UploadedFile($path, basename($path), null, null, true);
        }
        $completed = $pipeline->run($detection->user, $this->context, $file, $detection);
        if ($completed->processing_status === 'completed' && config('playwright.related_posts_inline', false)) {
            app(RelatedPostScraper::class)->scrapeForDetection($completed, $this->context);
        }
    }

    public function failed(?\Throwable $error): void
    {
        // Store a safe status only; the exception may contain request URLs or provider secrets.
        if ($this->retryId !== null) {
            $detection = Detection::find($this->detectionId);
            if ($detection && data_get($detection->signals, 'analysis_retry.id') === $this->retryId
                && in_array($detection->processing_status, ['queued', 'processing'], true)) {
                $signals = $detection->signals;
                unset($signals['analysis_progress']);
                $signals['analysis_retry']['status'] = 'failed';
                $detection->forceFill(['signals' => $signals,
                    'processing_status' => $detection->fact_check_result !== null ? $this->previousStatus : 'failed',
                ])->saveQuietly();
            }

            return;
        }
        Detection::whereKey($this->detectionId)->whereNull('fact_check_result')->update(['processing_status' => 'failed']);
    }
}
