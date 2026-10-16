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

    public int $uniqueFor = 360;

    public function __construct(public int $detectionId, public array $context)
    {
        $this->onConnection('evidence')->onQueue('evidence');
    }

    public function uniqueId(): string
    {
        return (string) $this->detectionId;
    }

    public function handle(DetectionPipeline $pipeline): void
    {
        $detection = Detection::find($this->detectionId);
        if (! $detection || $detection->fact_check_result !== null || ! $detection->user) {
            return;
        }
        $detection->forceFill(['processing_status' => 'processing'])->saveQuietly();
        $file = null;
        if ($detection->media_path) {
            $path = Storage::disk('public')->path($detection->media_path);
            if (! is_file($path)) {
                throw new \RuntimeException('Submitted media is no longer available.');
            }
            $file = new UploadedFile($path, basename($path), null, null, true);
        }
        $completed = $pipeline->run($detection->user, $this->context, $file, $detection);
        if (config('playwright.related_posts_inline', false)) {
            app(RelatedPostScraper::class)->scrapeForDetection($completed, $this->context);
        }
    }

    public function failed(?\Throwable $error): void
    {
        // Store a safe status only; the exception may contain request URLs or provider secrets.
        Detection::whereKey($this->detectionId)->whereNull('fact_check_result')->update(['processing_status' => 'failed']);
    }
}
