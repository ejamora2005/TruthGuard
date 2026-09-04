<?php

use App\Models\User;
use App\Services\Automation\ScrapeOrchestrator;
use App\Services\Detections\DetectionRetentionService;
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
    } catch (\Throwable $throwable) {
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

Schedule::command('truthguard:archive-detections')->daily();
Schedule::command('truthguard:notify-public-claim-reviews')->everyThirtyMinutes();
