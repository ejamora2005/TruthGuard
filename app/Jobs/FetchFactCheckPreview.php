<?php

namespace App\Jobs;

use App\Services\Detections\GoogleFactCheckFeedService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class FetchFactCheckPreview implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $uniqueFor = 600;
    public int $timeout = 20;
    public int $tries = 2;
    public int $backoff = 60;

    public function __construct(public string $feedItemId) {}

    public function uniqueId(): string
    {
        return $this->feedItemId;
    }

    public function handle(GoogleFactCheckFeedService $feed): void
    {
        $feed->refreshSavedPreview($this->feedItemId);
    }
}
