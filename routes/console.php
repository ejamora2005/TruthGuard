<?php

use App\Models\User;
use App\Services\Automation\ScrapeOrchestrator;
use App\Services\Detections\DetectionRetentionService;
use App\Services\Detections\ModelHealthService;
use App\Services\Notifications\PublicClaimReviewNotificationService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('truthguard:scrape-source {targetUrl?} {--source=generic-feed} {--limit=10} {--screenshot} {--analyze} {--user_id=} {--ready-selector=*}', function (?string $targetUrl = null) {
    $user = null;
    $userId = $this->option('user_id');

    if ($userId !== null && $userId !== '') {
        $user = User::query()->find($userId);

        if (! $user) {
            $this->error("User {$userId} was not found.");

            return 1;
        }
    }

    if ($this->option('analyze') && ! $user) {
        $this->error('Pass --user_id when using --analyze so detections can be attached to a user.');

        return 1;
    }

    try {
        $scrapeRun = app(ScrapeOrchestrator::class)->run([
            'target_url' => $targetUrl,
            'source_key' => (string) $this->option('source'),
            'post_limit' => (int) $this->option('limit'),
            'take_screenshot' => (bool) $this->option('screenshot'),
            'analyze_posts' => (bool) $this->option('analyze'),
            'ready_selectors' => array_values($this->option('ready-selector')),
            'trigger_source' => 'command',
        ], $user);
    } catch (Throwable $throwable) {
        $this->error($throwable->getMessage());

        return 1;
    }

    $this->info("Scrape run {$scrapeRun->id} completed with status {$scrapeRun->status}.");
    $this->line('Target URL: '.$scrapeRun->target_url);
    $this->line('Posts saved: '.($scrapeRun->summary['posts_saved'] ?? 0));
    $this->line('Detections created: '.($scrapeRun->summary['detections_created'] ?? 0));

    if ($scrapeRun->screenshot_path) {
        $this->line('Screenshot: '.$scrapeRun->screenshot_path);
    }

    return 0;
})->purpose('Run a Playwright scraping job and optionally create detections from collected posts.');

Artisan::command('truthguard:archive-detections {--user_id=}', function () {
    $userId = $this->option('user_id');
    $retentionService = app(DetectionRetentionService::class);
    $archived = $retentionService->archiveExpired($userId !== null && $userId !== '' ? (int) $userId : null);

    $this->info("Expired detection records archived: {$archived}");

    return 0;
})->purpose('Archive fact-check detection records older than 7 days without deleting their data.');

Artisan::command('truthguard:prune-detections {--user_id=}', function () {
    $userId = $this->option('user_id');
    $parameters = $userId !== null && $userId !== ''
        ? ['--user_id' => $userId]
        : [];

    $this->call('truthguard:archive-detections', $parameters);

    return 0;
})->purpose('Alias for truthguard:archive-detections.');

Artisan::command('truthguard:notify-public-claim-reviews {--limit=} {--dry-run} {--notify-existing}', function () {
    $configuredLimit = (int) config('services.google_fact_check.feed_news_notification_limit', 5);
    $limit = (int) ($this->option('limit') ?: $configuredLimit);
    $notifyExisting = (bool) $this->option('notify-existing')
        || (bool) config('services.google_fact_check.feed_news_notifications_notify_existing', false);

    $result = app(PublicClaimReviewNotificationService::class)->announceLatest(
        limit: $limit,
        dryRun: (bool) $this->option('dry-run'),
        notifyExisting: $notifyExisting,
    );

    if (! $result['enabled']) {
        $this->warn('Public claim review notifications are disabled or the announcement table is missing.');

        return 0;
    }

    $this->info("Public claim reviews checked: {$result['checked']}");
    $this->info("Public claim review baseline entries saved: {$result['seeded']}");
    $this->info("New public claim reviews announced: {$result['announced']}");
    $this->info("User emails sent: {$result['notified']}");
    $this->info("Existing review user emails sent: {$result['existing_notified']}");

    return 0;
})->purpose('Email active users when new public claim reviews are added to the latest feed.');

Artisan::command('truthguard:models:health {--load : Load each configured model and run a safe probe where supported} {--json : Emit JSON instead of a table}', function () {
    $result = app(ModelHealthService::class)->check((bool) $this->option('load'));

    if ($this->option('json')) {
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $result['ok'] ? 0 : 1;
    }

    foreach ($result['models'] as $name => $check) {
        $this->line($name.': '.(($check['ok'] ?? false) ? 'PASS' : 'FAIL'));
    }

    return $result['ok'] ? 0 : 1;
})->purpose('Check trained model deployment readiness without exposing model internals publicly.');

Schedule::command('truthguard:archive-detections')->daily()->withoutOverlapping();
Artisan::command('truthguard:refresh-review-images {--limit=50} {--queue}', function () {
    if (! config('services.google_fact_check.feed_images_enabled', true)) {
        $this->error('Preview collection is disabled. Enable GOOGLE_FACT_CHECK_FEED_IMAGES_ENABLED and refresh config cache.');
        return 1;
    }
    $reviews = \App\Models\PublicClaimReview::query()
        ->where(fn ($query) => $query->whereNull('image_url')->orWhere('image_url', ''))
        ->whereNotNull('url')->where('url', '!=', '')
        ->orderBy('updated_at')->orderBy('id')
        ->limit(max(1, min(500, (int) $this->option('limit'))))->get();
    $collected = 0;
    foreach ($reviews as $review) {
        if ($this->option('queue')) {
            \App\Jobs\FetchFactCheckPreview::dispatch($review->feed_item_id);
        } else {
            try {
                $collected += (int) app(\App\Services\Detections\GoogleFactCheckFeedService::class)
                    ->refreshSavedPreview($review->feed_item_id);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }
        // Rotate unavailable articles so they cannot starve the rest of the backfill.
        $review->touch();
    }
    if ($this->option('queue')) {
        $this->info('Submitted '.$reviews->count().' missing previews to the queue; delivery requires a running worker.');
        return 0;
    }
    $remaining = $reviews->count() - $collected;
    $this->info("Images collected: {$collected}; still unavailable: {$remaining}.");
    if ($remaining) $this->warn('Check Laravel logs for publisher HTTP errors, timeouts, or missing image metadata.');
    return $remaining ? 1 : 0;
})->purpose('Repair missing saved fact-check previews without recollecting reviews or sending notifications.');

Schedule::command('truthguard:refresh-review-images --queue --limit=100')->everyThirtyMinutes()->withoutOverlapping();
Schedule::command('truthguard:notify-public-claim-reviews')->everyThirtyMinutes()->withoutOverlapping();
