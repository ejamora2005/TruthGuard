<?php

namespace App\Services\Automation;

use App\Models\ScrapedPost;
use App\Models\ScrapeRun;
use App\Models\User;
use App\Services\Detections\DetectionPipeline;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ScrapeOrchestrator
{
    public function __construct(
        private readonly PlaywrightRunner $playwrightRunner,
        private readonly DetectionPipeline $detectionPipeline,
    ) {
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function run(array $input, ?User $triggeredBy = null): ScrapeRun
    {
        $targetUrl = $this->resolveTargetUrl($input['target_url'] ?? null);

        if ($targetUrl === null) {
            throw new RuntimeException('A target URL is required before a Playwright scrape can run.');
        }

        $sourceKey = (string) ($input['source_key'] ?? config('playwright.default_source'));
        $analyzePosts = array_key_exists('analyze_posts', $input)
            ? (bool) $input['analyze_posts']
            : (bool) config('playwright.analyze_posts');
        $associateDetectionId = isset($input['associate_detection_id'])
            ? (int) $input['associate_detection_id']
            : null;

        $scrapeRun = ScrapeRun::create([
            'triggered_by_user_id' => $triggeredBy?->id,
            'trigger_source' => (string) ($input['trigger_source'] ?? 'manual'),
            'source_key' => $sourceKey,
            'target_url' => $targetUrl,
            'status' => 'running',
            'browser_name' => (string) ($input['browser_name'] ?? config('playwright.browser')),
            'headless' => (bool) ($input['headless'] ?? config('playwright.headless')),
            'options' => [
                'post_limit' => (int) ($input['post_limit'] ?? config('playwright.post_limit')),
                'take_screenshot' => (bool) ($input['take_screenshot'] ?? config('playwright.take_screenshot')),
                'scroll_limit' => (int) ($input['scroll_limit'] ?? config('playwright.scroll_limit')),
                'scroll_pause_ms' => (int) ($input['scroll_pause_ms'] ?? config('playwright.scroll_pause_ms')),
                'ready_selectors' => array_values($input['ready_selectors'] ?? []),
                'analyze_posts' => $analyzePosts,
            ],
            'started_at' => now(),
        ]);

        try {
            $result = $this->playwrightRunner->run([
                'target_url' => $targetUrl,
                'source_key' => $sourceKey,
                'post_limit' => $input['post_limit'] ?? config('playwright.post_limit'),
                'take_screenshot' => $input['take_screenshot'] ?? config('playwright.take_screenshot'),
                'ready_selectors' => $input['ready_selectors'] ?? [],
                'extra_selectors' => $input['extra_selectors'] ?? [],
                'browser_name' => $input['browser_name'] ?? config('playwright.browser'),
                'headless' => $input['headless'] ?? config('playwright.headless'),
                'scroll_limit' => $input['scroll_limit'] ?? config('playwright.scroll_limit'),
                'scroll_pause_ms' => $input['scroll_pause_ms'] ?? config('playwright.scroll_pause_ms'),
                'retries' => $input['retries'] ?? config('playwright.retries'),
                'screenshot_dir' => storage_path('app/private/playwright/screenshots'),
            ]);

            $storedPosts = collect($result['posts'] ?? [])
                ->map(fn (array $post) => $this->storeScrapedPost(
                    $scrapeRun,
                    $sourceKey,
                    $post,
                    $analyzePosts,
                    $triggeredBy,
                    $associateDetectionId,
                ));

            $summary = [
                'posts_saved' => $storedPosts->count(),
                'detections_created' => $storedPosts->filter(fn (ScrapedPost $post) => $post->detection_id !== null)->count(),
                'media_items_collected' => $storedPosts->sum(fn (ScrapedPost $post) => count($post->media_urls ?? [])),
                'source_links_collected' => $storedPosts->sum(fn (ScrapedPost $post) => count($post->source_links ?? [])),
                'browser_summary' => $result['run']['summary'] ?? [],
            ];

            $scrapeRun->forceFill([
                'status' => 'completed',
                'summary' => $summary,
                'screenshot_path' => $result['run']['screenshotPath'] ?? null,
                'finished_at' => now(),
                'error_message' => null,
            ])->save();

            return $scrapeRun->load(['triggeredByUser', 'posts.detection']);
        } catch (Throwable $throwable) {
            Log::channel((string) config('playwright.log_channel', 'playwright'))->error('Playwright scrape run failed', [
                'scrape_run_id' => $scrapeRun->id,
                'target_url' => $targetUrl,
                'error' => $throwable->getMessage(),
            ]);

            $scrapeRun->forceFill([
                'status' => 'failed',
                'error_message' => $throwable->getMessage(),
                'finished_at' => now(),
            ])->save();

            throw $throwable;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function storeScrapedPost(
        ScrapeRun $scrapeRun,
        string $sourceKey,
        array $payload,
        bool $analyzePosts,
        ?User $triggeredBy,
        ?int $associateDetectionId = null,
    ): ScrapedPost {
        $normalized = $this->normalizeScrapedPost($sourceKey, $payload);
        $existing = null;

        if ($normalized['post_fingerprint'] !== null) {
            $existing = $scrapeRun->posts()
                ->where('post_fingerprint', $normalized['post_fingerprint'])
                ->first();
        }

        /** @var ScrapedPost $scrapedPost */
        $scrapedPost = $existing ?? $scrapeRun->posts()->create($normalized);

        if ($associateDetectionId && $scrapedPost->detection_id === null) {
            $scrapedPost->detection_id = $associateDetectionId;
            $scrapedPost->save();
        }

        if ($analyzePosts && $triggeredBy && $scrapedPost->detection_id === null) {
            $detection = $this->createDetectionFromScrapedPost($triggeredBy, $scrapedPost);
            $scrapedPost->detection()->associate($detection);
            $scrapedPost->save();
        }

        return $scrapedPost;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeScrapedPost(string $sourceKey, array $payload): array
    {
        $imageUrls = $this->normalizeUrlArray($payload['imageUrls'] ?? []);
        $videoUrls = $this->normalizeUrlArray($payload['videoUrls'] ?? []);
        $mediaUrls = $this->normalizeUrlArray($payload['mediaUrls'] ?? array_merge($imageUrls, $videoUrls));
        $sourceLinks = $this->normalizeUrlArray($payload['sourceLinks'] ?? []);
        $captionText = $this->nullableString($payload['captionText'] ?? null);
        $postUrl = $this->nullableString($payload['postUrl'] ?? null);
        $displayName = $this->nullableString($payload['displayName'] ?? null);
        $username = $this->nullableString($payload['username'] ?? null);
        $postedAt = $this->normalizeDate($payload['postedAt'] ?? null);

        return [
            'source_key' => $sourceKey,
            'external_id' => $this->nullableString($payload['externalId'] ?? null),
            'post_fingerprint' => $this->buildPostFingerprint($sourceKey, $postUrl, $captionText, $mediaUrls),
            'post_url' => $postUrl,
            'display_name' => $displayName,
            'username' => $username,
            'caption_text' => $captionText,
            'posted_at' => $postedAt,
            'source_links' => $sourceLinks,
            'image_urls' => $imageUrls,
            'video_urls' => $videoUrls,
            'media_urls' => $mediaUrls,
            'raw_payload' => is_array($payload['rawPayload'] ?? null) ? $payload['rawPayload'] : [],
            'scraped_at' => now(),
        ];
    }

    private function createDetectionFromScrapedPost(User $user, ScrapedPost $scrapedPost)
    {
        $notes = collect([
            'Collected by the TruthGuard Playwright automation module.',
            $scrapedPost->display_name ? 'Display name: '.$scrapedPost->display_name : null,
            $scrapedPost->username ? 'Username: '.$scrapedPost->username : null,
            $scrapedPost->posted_at ? 'Posted at: '.$scrapedPost->posted_at->toIso8601String() : null,
            count($scrapedPost->media_urls ?? []) > 0 ? 'Media URLs: '.implode(', ', array_slice($scrapedPost->media_urls, 0, 5)) : null,
            count($scrapedPost->source_links ?? []) > 0 ? 'Source links: '.implode(', ', array_slice($scrapedPost->source_links, 0, 5)) : null,
        ])->filter()->implode(PHP_EOL);

        $validated = [
            'source_url' => $scrapedPost->post_url ?: ($scrapedPost->media_urls[0] ?? null),
            'caption_text' => $scrapedPost->caption_text,
            'notes' => $notes !== '' ? $notes : null,
            'source_platform' => $this->mapSourceToPlatform($scrapedPost->source_key),
        ];

        return $this->detectionPipeline->run($user, $validated, null);
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, string>
     */
    private function normalizeUrlArray(array $values): array
    {
        return collect($values)
            ->map(fn (mixed $value) => $this->nullableString($value))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function buildPostFingerprint(string $sourceKey, ?string $postUrl, ?string $captionText, array $mediaUrls): ?string
    {
        if ($postUrl === null && $captionText === null && $mediaUrls === []) {
            return null;
        }

        return hash('sha256', json_encode([
            'source_key' => Str::lower($sourceKey),
            'post_url' => Str::lower((string) $postUrl),
            'caption_text' => Str::lower((string) $captionText),
            'media_urls' => array_map(static fn (string $url) => Str::lower($url), $mediaUrls),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function normalizeDate(mixed $value): ?Carbon
    {
        $normalized = $this->nullableString($value);

        if ($normalized === null) {
            return null;
        }

        try {
            return Carbon::parse($normalized);
        } catch (Throwable) {
            return null;
        }
    }

    private function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function resolveTargetUrl(mixed $value): ?string
    {
        $targetUrl = $this->nullableString($value);

        return $targetUrl ?? (config('playwright.target_urls')[0] ?? null);
    }

    private function mapSourceToPlatform(string $sourceKey): string
    {
        return match (true) {
            Str::contains($sourceKey, 'facebook') => 'facebook',
            Str::contains($sourceKey, 'instagram') => 'instagram',
            Str::contains($sourceKey, 'tiktok') => 'tiktok',
            Str::contains($sourceKey, 'youtube') => 'youtube',
            Str::contains($sourceKey, 'twitter'), Str::contains($sourceKey, 'x') => 'x',
            default => 'web',
        };
    }
}
