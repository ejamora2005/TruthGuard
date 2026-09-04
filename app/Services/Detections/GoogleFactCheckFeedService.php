<?php

namespace App\Services\Detections;

use App\Models\FactCheckSource;
use App\Models\PublicClaimReview;
use Illuminate\Support\Carbon;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class GoogleFactCheckFeedService
{
    private const ENDPOINT = 'https://factchecktools.googleapis.com/v1alpha1/claims:search';
    private const MAX_FEED_LIMIT = 500;

    /**
     * @return array{configured: bool, source_label: string, query_label: string, updated_at: ?Carbon, cache_minutes: int, cache_seconds: int, cache_label: string, refresh_seconds: int, max_age_days: int, items: array<int, array<string, mixed>>}
     */
    public function latest(int $limit = 10, ?int $maxAgeDays = null): array
    {
        $limit = max(1, min($limit, self::MAX_FEED_LIMIT));
        $maxAgeDays = $this->normalizeFeedMaxAgeDays($maxAgeDays);
        $queries = $this->queries();
        $publisherSites = $this->publisherSites();
        $apiKey = trim((string) config('services.google_fact_check.key'));

        if ($apiKey === '') {
            return $this->withPersistedItems($this->emptyFeed(false, $queries, $publisherSites, $maxAgeDays), $limit, $maxAgeDays);
        }

        $cacheSeconds = $this->feedCacheSeconds();
        $cacheKey = 'truthguard.google_fact_check_feed.v4.'.md5(json_encode([
            $queries,
            $publisherSites,
            $limit,
            $maxAgeDays,
            config('services.google_fact_check.language_code', 'en-US'),
        ]));

        $googlePayload = Cache::remember($cacheKey, now()->addSeconds($cacheSeconds), function () use ($apiKey, $queries, $publisherSites, $limit, $maxAgeDays): array {
            return [
                'items' => $this->fetchItems($apiKey, $queries, $publisherSites, $limit, $maxAgeDays),
                'fetched_at' => now()->toIso8601String(),
            ];
        });
        $directPayload = $this->directPublisherPayload($limit, $cacheSeconds, $maxAgeDays);
        $googleItems = is_array($googlePayload['items'] ?? null) ? $googlePayload['items'] : [];
        $directItems = is_array($directPayload['items'] ?? null) ? $directPayload['items'] : [];
        $items = $this->filterItemsByMaxAge($this->mergeFeedItems($directItems, $googleItems, $limit), $maxAgeDays);
        $this->persistItems($items);
        $items = $this->persistedItems($limit, $maxAgeDays) ?: $items;
        $updatedAt = collect([
            $this->latestPersistedAt($maxAgeDays),
            $directPayload['fetched_at'] ?? null,
            $googlePayload['fetched_at'] ?? null,
        ])
            ->map(fn ($date) => $this->parseDate($date))
            ->filter()
            ->sortByDesc(fn (Carbon $date) => $date->timestamp)
            ->first();

        return [
            'configured' => true,
            'source_label' => 'Publisher feeds + Google Fact Check API',
            'query_label' => implode(', ', array_merge($this->directPublisherLabels(), $publisherSites, $queries)),
            'updated_at' => $updatedAt,
            'cache_minutes' => (int) ceil($cacheSeconds / 60),
            'cache_seconds' => $cacheSeconds,
            'cache_label' => $this->secondsLabel($cacheSeconds),
            'refresh_seconds' => $this->feedRefreshSeconds($cacheSeconds),
            'max_age_days' => $maxAgeDays,
            'items' => $items,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        $id = trim($id);

        if ($id === '') {
            return null;
        }

        $persisted = $this->findPersisted($id);

        if ($persisted !== null) {
            return $persisted;
        }

        $feed = $this->latest(self::MAX_FEED_LIMIT);

        return collect($feed['items'] ?? [])
            ->first(fn (array $item): bool => hash_equals((string) ($item['id'] ?? ''), $id));
    }

    /**
     * @param  array<int, string>  $queries
     * @param  array<int, string>  $publisherSites
     * @return array<int, array<string, mixed>>
     */
    private function fetchItems(string $apiKey, array $queries, array $publisherSites, int $limit, int $maxAgeDays): array
    {
        $items = [];
        $seen = [];
        $timeout = max(1, min((int) config('services.google_fact_check.timeout', 3), 2));
        $pageSize = max(3, min((int) config('services.google_fact_check.feed_page_size', 8), 10));

        $searches = $this->searches($queries, $publisherSites);
        $responses = Http::pool(function (Pool $pool) use ($apiKey, $searches, $pageSize, $maxAgeDays, $timeout): array {
            return collect($searches)
                ->mapWithKeys(function (array $search, int $index) use ($pool, $apiKey, $pageSize, $maxAgeDays, $timeout): array {
                    return [
                        'search_'.$index => $pool
                            ->as('search_'.$index)
                            ->acceptJson()
                            ->connectTimeout(1)
                            ->timeout($timeout)
                            ->get(self::ENDPOINT, $this->searchParams($apiKey, $search, $pageSize, $maxAgeDays)),
                    ];
                })
                ->all();
        });

        foreach ($searches as $index => $search) {
            try {
                $response = $responses['search_'.$index] ?? null;

                if (! $response instanceof \Illuminate\Http\Client\Response || ! $response->successful()) {
                    Log::warning('Google Fact Check feed request failed.', [
                        'search' => $search['label'] ?? null,
                        'status' => $response instanceof \Illuminate\Http\Client\Response ? $response->status() : null,
                    ]);

                    continue;
                }

                $claims = $response->json('claims', []);

                if (! is_array($claims)) {
                    continue;
                }

                foreach ($claims as $claim) {
                    if (! is_array($claim)) {
                        continue;
                    }

                    $reviews = $claim['claimReview'] ?? [];

                    if (! is_array($reviews) || $reviews === []) {
                        $reviews = [[]];
                    }

                    foreach ($reviews as $review) {
                        if (! is_array($review)) {
                            continue;
                        }

                        $item = $this->normalizeClaim($claim, $review, $search);
                        $dedupeKey = $item['url'] ?: md5($item['claim'].'|'.$item['headline']);

                        if (isset($seen[$dedupeKey])) {
                            continue;
                        }

                        $seen[$dedupeKey] = true;
                        $items[] = $item;

                    }
                }
            } catch (Throwable $exception) {
                Log::warning('Google Fact Check feed request errored.', [
                    'search' => $search['label'] ?? null,
                    'message' => Str::limit($exception->getMessage(), 180),
                ]);
            }
        }

        usort($items, fn (array $first, array $second): int => ($second['timestamp'] ?? 0) <=> ($first['timestamp'] ?? 0));

        return $this->attachPreviewImages(array_slice($this->filterItemsByMaxAge($items, $maxAgeDays), 0, $limit));
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, fetched_at: string}
     */
    private function directPublisherPayload(int $limit, int $cacheSeconds, int $maxAgeDays): array
    {
        if (! (bool) config('services.google_fact_check.feed_direct_sources_enabled', true)) {
            return [
                'items' => [],
                'fetched_at' => now()->toIso8601String(),
            ];
        }

        $sources = $this->directPublisherSources();

        if ($sources === []) {
            return [
                'items' => [],
                'fetched_at' => now()->toIso8601String(),
            ];
        }

        $cacheKey = 'truthguard.direct_fact_check_feed.v1.'.md5(json_encode([$sources, $limit, $maxAgeDays]));

        return Cache::remember($cacheKey, now()->addSeconds($cacheSeconds), function () use ($sources, $limit, $maxAgeDays): array {
            return [
                'items' => $this->filterItemsByMaxAge($this->fetchDirectPublisherItems($sources, $limit), $maxAgeDays),
                'fetched_at' => now()->toIso8601String(),
            ];
        });
    }

    /**
     * @param  array<int, array{publisher: string, domain: string, url: string}>  $sources
     * @return array<int, array<string, mixed>>
     */
    private function fetchDirectPublisherItems(array $sources, int $limit): array
    {
        $timeout = max(4, min((int) config('services.google_fact_check.timeout', 3) + 2, 8));
        $responses = [];

        try {
            $responses = Http::pool(function (Pool $pool) use ($sources, $timeout): array {
                return collect($sources)
                    ->mapWithKeys(fn (array $source, int $index): array => [
                        'direct_'.$index => $pool
                            ->as('direct_'.$index)
                            ->acceptJson()
                            ->withHeaders($this->previewImageHeaders())
                            ->connectTimeout(1)
                            ->timeout($timeout)
                            ->get($source['url']),
                    ])
                    ->all();
            });
        } catch (Throwable $exception) {
            Log::debug('Direct publisher fact-check feed pool failed.', [
                'message' => Str::limit($exception->getMessage(), 180),
            ]);
        }

        $items = [];

        foreach ($sources as $index => $source) {
            $response = $responses['direct_'.$index] ?? null;

            if (! $response instanceof \Illuminate\Http\Client\Response || ! $response->successful()) {
                Log::debug('Direct publisher fact-check feed request failed.', [
                    'publisher' => $source['publisher'] ?? null,
                    'status' => $response instanceof \Illuminate\Http\Client\Response ? $response->status() : null,
                ]);

                continue;
            }

            $posts = $response->json();

            if (! is_array($posts)) {
                continue;
            }

            foreach ($posts as $post) {
                if (! is_array($post)) {
                    continue;
                }

                $item = $this->normalizeWordPressFactCheck($post, $source);

                if ($item !== null) {
                    $items[] = $item;
                }
            }
        }

        usort($items, fn (array $first, array $second): int => ($second['timestamp'] ?? 0) <=> ($first['timestamp'] ?? 0));

        return array_slice($items, 0, $limit);
    }

    /**
     * @param  array{publisher: string, domain: string, url: string}  $source
     * @return array<string, mixed>|null
     */
    private function normalizeWordPressFactCheck(array $post, array $source): ?array
    {
        $url = $this->string(data_get($post, 'link'));

        if ($url === null) {
            return null;
        }

        $publisher = $this->string($source['publisher'] ?? null) ?? 'Fact-check partner';
        $domain = $this->normalizeDomain($source['domain'] ?? null) ?? $this->normalizeDomain(parse_url($url, PHP_URL_HOST));
        $headline = $this->htmlText(data_get($post, 'title.rendered')) ?? 'Fact-check result';
        $claim = $this->string(data_get($post, 'meta.claim_reviewed'))
            ?? $this->htmlText(data_get($post, 'excerpt.rendered'))
            ?? $this->firstContentParagraph(data_get($post, 'content.rendered'))
            ?? 'Latest fact-check article from '.$publisher.'.';
        $claimant = $this->string(data_get($post, 'meta.claim_author_name')) ?? 'Online claim';
        $rating = $this->string(data_get($post, 'meta.review_rating')) ?? $this->ratingFromText($headline.' '.$claim);
        $date = $this->parseWordPressDate($post);
        $imageUrl = $this->extractWordPressPreviewImage($post, $url);

        if (! Str::startsWith(Str::lower($headline), ['fact check', 'fact-check'])) {
            $headline = 'Fact Check: '.$headline;
        }

        return [
            'id' => substr(hash('sha256', 'publisher-latest|'.$url), 0, 24),
            'publisher' => Str::limit($publisher, 54, ''),
            'headline' => Str::limit($headline, 150),
            'claim' => Str::limit($claim, 250),
            'claimant' => Str::limit($claimant, 70),
            'rating' => Str::limit($rating, 50, ''),
            'tone' => $this->ratingTone($rating),
            'url' => $url,
            'host' => $domain,
            'source_domain' => $domain,
            'logo_url' => $domain ? $this->logoUrl($domain) : null,
            'image_url' => $imageUrl,
            'query' => 'Latest: '.$publisher,
            'feed_source_type' => 'publisher_latest',
            'publisher_filter' => $domain,
            'date_label' => $date?->format('M d, Y') ?? 'Date unavailable',
            'timestamp' => $date?->timestamp ?? 0,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $directItems
     * @param  array<int, array<string, mixed>>  $googleItems
     * @return array<int, array<string, mixed>>
     */
    private function mergeFeedItems(array $directItems, array $googleItems, int $limit): array
    {
        $seen = [];
        $items = [];

        foreach (array_merge($directItems, $googleItems) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $key = $this->string($item['url'] ?? null) ?: $this->string($item['id'] ?? null);

            if ($key === null || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $items[] = $item;
        }

        usort($items, fn (array $first, array $second): int => ($second['timestamp'] ?? 0) <=> ($first['timestamp'] ?? 0));

        return array_slice($items, 0, $limit);
    }

    /**
     * @return array<int, array{publisher: string, domain: string, url: string}>
     */
    private function directPublisherSources(): array
    {
        $sources = config('services.google_fact_check.feed_direct_sources', []);

        if (! is_array($sources)) {
            return [];
        }

        return collect($sources)
            ->filter(fn ($source): bool => is_array($source)
                && filled($source['publisher'] ?? null)
                && filled($source['domain'] ?? null)
                && filled($source['url'] ?? null))
            ->map(fn (array $source): array => [
                'publisher' => (string) $source['publisher'],
                'domain' => (string) $source['domain'],
                'url' => (string) $source['url'],
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function directPublisherLabels(): array
    {
        if (! (bool) config('services.google_fact_check.feed_direct_sources_enabled', true)) {
            return [];
        }

        return collect($this->directPublisherSources())
            ->pluck('publisher')
            ->map(fn (string $publisher): string => $publisher.' latest')
            ->all();
    }

    private function parseWordPressDate(array $post): ?Carbon
    {
        $localDate = $this->parseDate(data_get($post, 'date'));

        if ($localDate) {
            return $localDate;
        }

        $gmtDate = $this->string(data_get($post, 'date_gmt'));

        return $gmtDate ? $this->parseDate($gmtDate.' UTC') : null;
    }

    private function htmlText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return $this->string(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5));
    }

    private function firstContentParagraph(mixed $html): ?string
    {
        if (! is_string($html) || trim($html) === '') {
            return null;
        }

        if (preg_match('/<p\b[^>]*>(.*?)<\/p>/is', $html, $matches) !== 1) {
            return $this->htmlText($html);
        }

        return $this->htmlText($matches[1]);
    }

    private function ratingFromText(string $text): string
    {
        return FactCheckRatingNormalizer::normalize($text);
    }

    /**
     * @param  array{query: ?string, publisher: ?string, label: string, type: string}  $search
     * @return array<string, mixed>
     */
    private function searchParams(string $apiKey, array $search, int $pageSize, int $maxAgeDays): array
    {
        $params = [
            'languageCode' => config('services.google_fact_check.language_code', 'en-US'),
            'pageSize' => $pageSize,
            'key' => $apiKey,
        ];

        if ($maxAgeDays > 0) {
            $params['maxAgeDays'] = $maxAgeDays;
        }

        if (($search['publisher'] ?? null) !== null) {
            $params['reviewPublisherSiteFilter'] = $search['publisher'];
        }

        if (($search['query'] ?? null) !== null) {
            $params['query'] = $search['query'];
        }

        return $params;
    }

    private function feedCacheSeconds(): int
    {
        $configuredSeconds = (int) config('services.google_fact_check.feed_cache_seconds', 0);

        if ($configuredSeconds > 0) {
            return max(5, min($configuredSeconds, 3600));
        }

        $configuredMinutes = (int) config('services.google_fact_check.feed_cache_minutes', 30);

        return max(5, min($configuredMinutes * 60, 3600));
    }

    private function feedRefreshSeconds(int $cacheSeconds): int
    {
        $configuredSeconds = (int) config('services.google_fact_check.feed_refresh_seconds', $cacheSeconds);

        return max(5, min($configuredSeconds > 0 ? $configuredSeconds : $cacheSeconds, 3600));
    }

    private function secondsLabel(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds.' sec';
        }

        $minutes = (int) ceil($seconds / 60);

        return $minutes.' min';
    }

    private function normalizeFeedMaxAgeDays(?int $maxAgeDays): int
    {
        $days = $maxAgeDays ?? (int) config('services.google_fact_check.feed_max_age_days', 120);

        return max(0, min($days, 365));
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function filterItemsByMaxAge(array $items, int $maxAgeDays): array
    {
        if ($maxAgeDays <= 0) {
            return array_values($items);
        }

        $cutoffTimestamp = now()->subDays($maxAgeDays)->startOfDay()->timestamp;

        return collect($items)
            ->filter(fn (array $item): bool => (int) ($item['timestamp'] ?? 0) >= $cutoffTimestamp)
            ->values()
            ->all();
    }

    /**
     * @param  array{query: ?string, publisher: ?string, label: string, type: string}  $search
     * @return array<string, mixed>
     */
    private function normalizeClaim(array $claim, array $review, array $search): array
    {
        $claimText = $this->string($claim['text'] ?? null)
            ?? $this->string($review['claimReviewed'] ?? null)
            ?? 'Public claim reviewed by a fact-checking partner.';
        $headline = $this->string($review['title'] ?? null) ?: Str::limit($claimText, 120, '');
        $publisher = $this->string(data_get($review, 'publisher.name')) ?? 'Google Fact Check';
        $rating = $this->string($review['textualRating'] ?? null) ?? 'Reviewed';
        $url = $this->string($review['url'] ?? null);
        $date = $this->parseDate($review['reviewDate'] ?? $claim['claimDate'] ?? null);
        $claimant = $this->string($claim['claimant'] ?? null) ?? 'Online claim';
        $host = $url ? parse_url($url, PHP_URL_HOST) : null;
        $sourceDomain = $this->sourceDomain(
            $this->string($search['publisher'] ?? null),
            is_string($host) ? $host : null,
            $publisher,
        );

        if (! Str::startsWith(Str::lower($headline), ['fact check', 'fact-check'])) {
            $headline = 'Fact Check: '.$headline;
        }

        $identity = $url ?: implode('|', [$publisher, $headline, $claimText]);

        return [
            'id' => substr(hash('sha256', $identity), 0, 24),
            'publisher' => Str::limit($publisher, 54, ''),
            'headline' => Str::limit($headline, 150),
            'claim' => Str::limit($claimText, 250),
            'claimant' => Str::limit($claimant, 70),
            'rating' => Str::limit($rating, 50, ''),
            'tone' => $this->ratingTone($rating),
            'url' => $url,
            'host' => $sourceDomain,
            'source_domain' => $sourceDomain,
            'logo_url' => $sourceDomain ? $this->logoUrl($sourceDomain) : null,
            'image_url' => null,
            'query' => $search['label'] ?? null,
            'feed_source_type' => $search['type'] ?? 'topic',
            'publisher_filter' => $search['publisher'] ?? null,
            'date_label' => $date?->format('M d, Y') ?? 'Date unavailable',
            'timestamp' => $date?->timestamp ?? 0,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function attachPreviewImages(array $items): array
    {
        if (! (bool) config('services.google_fact_check.feed_images_enabled', true)) {
            return $items;
        }

        $limit = max(0, min((int) config('services.google_fact_check.feed_image_limit', 8), count($items)));
        $pending = [];

        for ($index = 0; $index < $limit; $index++) {
            $url = $this->string($items[$index]['url'] ?? null);

            if ($url === null) {
                continue;
            }

            $cacheKey = $this->previewImageCacheKey($url);

            if (Cache::has($cacheKey)) {
                $cachedImage = Cache::get($cacheKey);
                $items[$index]['image_url'] = is_string($cachedImage) && $cachedImage !== '' ? $cachedImage : null;

                continue;
            }

            $pending[$index] = $url;
        }

        if ($pending === []) {
            return $items;
        }

        $timeout = max(1, min((int) config('services.google_fact_check.feed_image_timeout', 1), 3));

        try {
            $responses = Http::pool(function (Pool $pool) use ($pending, $timeout): array {
                return collect($pending)
                    ->mapWithKeys(fn (string $url, int $index): array => [
                        'image_'.$index => $pool
                            ->as('image_'.$index)
                            ->withHeaders($this->previewImageHeaders())
                            ->connectTimeout(1)
                            ->timeout($timeout)
                            ->get($url),
                    ])
                    ->all();
            });
        } catch (Throwable $exception) {
            Log::debug('Fact-check preview image pool failed.', [
                'message' => Str::limit($exception->getMessage(), 180),
            ]);

            $responses = [];
        }

        $fallbackPending = [];

        foreach ($pending as $index => $url) {
            $image = null;
            $response = $responses['image_'.$index] ?? null;

            if ($response instanceof \Illuminate\Http\Client\Response && $response->successful()) {
                $image = $this->extractPreviewImage($response->body(), $url);
            }

            if (! $image) {
                $fallbackUrl = $this->publisherPreviewApiUrl($url);

                if ($fallbackUrl) {
                    $fallbackPending[$index] = [
                        'page_url' => $url,
                        'api_url' => $fallbackUrl,
                    ];

                    continue;
                }
            }

            $this->cachePreviewImage($url, $image);
            $items[$index]['image_url'] = $image;
        }

        if ($fallbackPending !== []) {
            foreach ($this->fetchPublisherFallbackImages($fallbackPending) as $index => $image) {
                $url = $fallbackPending[$index]['page_url'];
                $this->cachePreviewImage($url, $image);
                $items[$index]['image_url'] = $image;
            }
        }

        return $items;
    }

    private function previewImageCacheKey(string $url): string
    {
        return 'truthguard.fact_check_preview_image.v4.'.md5($url);
    }

    private function cachePreviewImage(string $url, ?string $image): void
    {
        Cache::put(
            $this->previewImageCacheKey($url),
            $image ?: '',
            $image ? now()->addDay() : now()->addMinutes($this->previewImageMissCacheMinutes()),
        );
    }

    private function previewImageMissCacheMinutes(): int
    {
        return max(1, min((int) config('services.google_fact_check.feed_image_miss_cache_minutes', 10), 1440));
    }

    private function previewImageForUrl(string $url): ?string
    {
        $value = Cache::remember(
            $this->previewImageCacheKey($url),
            now()->addMinutes($this->previewImageMissCacheMinutes()),
            fn (): string => $this->fetchPreviewImage($url) ?: '',
        );

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<int, array{page_url: string, api_url: string}>  $pending
     * @return array<int, ?string>
     */
    private function fetchPublisherFallbackImages(array $pending): array
    {
        $timeout = max(2, min((int) config('services.google_fact_check.feed_image_timeout', 1) + 2, 5));

        try {
            $responses = Http::pool(function (Pool $pool) use ($pending, $timeout): array {
                return collect($pending)
                    ->mapWithKeys(fn (array $request, int $index): array => [
                        'fallback_'.$index => $pool
                            ->as('fallback_'.$index)
                            ->withHeaders($this->previewImageHeaders())
                            ->connectTimeout(1)
                            ->timeout($timeout)
                            ->get($request['api_url']),
                    ])
                    ->all();
            });
        } catch (Throwable $exception) {
            Log::debug('Fact-check publisher image fallback pool failed.', [
                'message' => Str::limit($exception->getMessage(), 180),
            ]);

            $responses = [];
        }

        $images = [];

        foreach ($pending as $index => $request) {
            $image = null;
            $response = $responses['fallback_'.$index] ?? null;

            if ($response instanceof \Illuminate\Http\Client\Response && $response->successful()) {
                $image = $this->extractWordPressPreviewImage($response->json(), $request['page_url']);
            }

            $images[$index] = $image;
        }

        return $images;
    }

    private function publisherPreviewApiUrl(string $pageUrl): ?string
    {
        $host = parse_url($pageUrl, PHP_URL_HOST);
        $path = trim((string) parse_url($pageUrl, PHP_URL_PATH), '/');

        if (! is_string($host) || $host === '' || $path === '') {
            return null;
        }

        $host = preg_replace('/^www\./', '', Str::lower($host));
        $slug = basename($path);

        if (! is_string($slug) || $slug === '') {
            return null;
        }

        return match ($host) {
            'verafiles.org', 'pressone.ph', 'tsek.ph' => 'https://'.$host.'/wp-json/wp/v2/posts?slug='.rawurlencode($slug).'&_embed=1',
            'rappler.com' => 'https://www.rappler.com/wp-json/wp/v2/posts?slug='.rawurlencode($slug).'&_embed=1',
            default => null,
        };
    }

    private function extractWordPressPreviewImage(mixed $payload, string $pageUrl): ?string
    {
        if (! is_array($payload) || $payload === []) {
            return null;
        }

        $post = array_is_list($payload) ? ($payload[0] ?? null) : $payload;

        if (! is_array($post)) {
            return null;
        }

        $candidates = [
            data_get($post, 'yoast_head_json.og_image.0.url'),
            data_get($post, 'yoast_head_json.twitter_image'),
            data_get($post, 'jetpack_featured_media_url'),
            data_get($post, '_embedded.wp:featuredmedia.0.source_url'),
            data_get($post, '_embedded.wp:featuredmedia.0.media_details.sizes.full.source_url'),
            data_get($post, '_embedded.wp:featuredmedia.0.media_details.sizes.large.source_url'),
        ];

        foreach ($candidates as $candidate) {
            $image = is_string($candidate) ? $this->normalizeCandidateImage($candidate, $pageUrl) : null;

            if ($image) {
                return $image;
            }
        }

        foreach (['yoast_head', 'content.rendered', 'excerpt.rendered'] as $key) {
            $html = data_get($post, $key);

            if (! is_string($html) || trim($html) === '') {
                continue;
            }

            $image = $this->extractPreviewImage($html, $pageUrl);

            if ($image) {
                return $image;
            }
        }

        return null;
    }

    private function fetchPreviewImage(string $url): ?string
    {
        $timeout = max(1, min((int) config('services.google_fact_check.feed_image_timeout', 1), 3));

        try {
            $response = Http::withHeaders($this->previewImageHeaders())
                ->connectTimeout(1)
                ->timeout($timeout)
                ->get($url);

            if (! $response->successful()) {
                return null;
            }

            return $this->extractPreviewImage($response->body(), $url);
        } catch (Throwable $exception) {
            Log::debug('Fact-check preview image lookup failed.', [
                'url' => Str::limit($url, 180),
                'message' => Str::limit($exception->getMessage(), 180),
            ]);

            return null;
        }
    }

    /**
     * @return array<string, string>
     */
    private function previewImageHeaders(): array
    {
        return [
            'User-Agent' => 'Mozilla/5.0 (compatible; TruthGuard/1.0; +https://truthguard.local)',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language' => 'en-US,en;q=0.9',
        ];
    }

    private function extractPreviewImage(string $html, string $pageUrl): ?string
    {
        if (trim($html) === '') {
            return null;
        }

        libxml_use_internal_errors(true);

        $document = new \DOMDocument();

        if (! $document->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR)) {
            libxml_clear_errors();

            return null;
        }

        libxml_clear_errors();

        $xpath = new \DOMXPath($document);
        $queries = [
            '//meta[translate(@property, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="og:image"]/@content',
            '//meta[translate(@property, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="og:image:url"]/@content',
            '//meta[translate(@property, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="og:image:secure_url"]/@content',
            '//meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="twitter:image"]/@content',
            '//meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="twitter:image:src"]/@content',
            '//meta[translate(@itemprop, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="image"]/@content',
            '//link[contains(concat(" ", normalize-space(translate(@rel, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")), " "), " image_src ")]/@href',
        ];

        foreach ($queries as $query) {
            $nodes = $xpath->query($query);

            if (! $nodes || $nodes->length === 0) {
                continue;
            }

            $image = $this->string($nodes->item(0)?->nodeValue);
            $image = $image ? $this->normalizeCandidateImage($image, $pageUrl) : null;

            if ($image) {
                return $image;
            }
        }

        $jsonLdImage = $this->extractJsonLdImage($xpath, $pageUrl);

        if ($jsonLdImage) {
            return $jsonLdImage;
        }

        $htmlImage = $this->extractHtmlImage($xpath, $pageUrl);

        if ($htmlImage) {
            return $htmlImage;
        }

        return null;
    }

    private function extractJsonLdImage(\DOMXPath $xpath, string $pageUrl): ?string
    {
        $nodes = $xpath->query('//script[contains(translate(@type, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "ld+json")]');

        if (! $nodes) {
            return null;
        }

        foreach ($nodes as $node) {
            $json = trim((string) $node->nodeValue);

            if ($json === '') {
                continue;
            }

            try {
                $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                continue;
            }

            $image = $this->firstJsonLdImage($payload, $pageUrl);

            if ($image) {
                return $image;
            }
        }

        return null;
    }

    private function firstJsonLdImage(mixed $value, string $pageUrl): ?string
    {
        if (is_string($value)) {
            return $this->normalizeCandidateImage($value, $pageUrl);
        }

        if (! is_array($value)) {
            return null;
        }

        foreach (['image', 'thumbnailUrl', 'thumbnail', 'primaryImageOfPage'] as $key) {
            if (! array_key_exists($key, $value)) {
                continue;
            }

            $image = $this->firstImageValue($value[$key], $pageUrl);

            if ($image) {
                return $image;
            }
        }

        foreach ($value as $child) {
            if (! is_array($child)) {
                continue;
            }

            $image = $this->firstJsonLdImage($child, $pageUrl);

            if ($image) {
                return $image;
            }
        }

        return null;
    }

    private function firstImageValue(mixed $value, string $pageUrl): ?string
    {
        if (is_string($value)) {
            return $this->normalizeCandidateImage($value, $pageUrl);
        }

        if (! is_array($value)) {
            return null;
        }

        foreach (['url', 'contentUrl', '@id'] as $key) {
            if (! isset($value[$key]) || ! is_string($value[$key])) {
                continue;
            }

            $image = $this->normalizeCandidateImage($value[$key], $pageUrl);

            if ($image) {
                return $image;
            }
        }

        foreach ($value as $child) {
            $image = $this->firstImageValue($child, $pageUrl);

            if ($image) {
                return $image;
            }
        }

        return null;
    }

    private function extractHtmlImage(\DOMXPath $xpath, string $pageUrl): ?string
    {
        $queries = [
            '//article//img',
            '//main//img',
            '//*[contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "article")]//img',
            '//*[contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "post")]//img',
            '//img',
        ];
        $attributes = ['src', 'data-src', 'data-lazy-src', 'data-original', 'data-url', 'data-image', 'data-orig-file'];
        $srcsetAttributes = ['srcset', 'data-srcset', 'data-lazy-srcset'];

        foreach ($queries as $query) {
            $nodes = $xpath->query($query);

            if (! $nodes) {
                continue;
            }

            foreach ($nodes as $node) {
                foreach ($attributes as $attribute) {
                    $image = $this->normalizeCandidateImage($node->attributes?->getNamedItem($attribute)?->nodeValue, $pageUrl);

                    if ($image) {
                        return $image;
                    }
                }

                foreach ($srcsetAttributes as $attribute) {
                    $image = $this->imageFromSrcset($node->attributes?->getNamedItem($attribute)?->nodeValue, $pageUrl);

                    if ($image) {
                        return $image;
                    }
                }
            }
        }

        return null;
    }

    private function imageFromSrcset(?string $srcset, string $pageUrl): ?string
    {
        $srcset = $this->string($srcset);

        if ($srcset === null) {
            return null;
        }

        $candidates = collect(explode(',', $srcset))
            ->map(fn (string $candidate): string => trim(preg_split('/\s+/', trim($candidate))[0] ?? ''))
            ->filter()
            ->values();

        for ($index = $candidates->count() - 1; $index >= 0; $index--) {
            $image = $this->normalizeCandidateImage($candidates[$index], $pageUrl);

            if ($image) {
                return $image;
            }
        }

        return null;
    }

    private function normalizeCandidateImage(?string $candidate, string $pageUrl): ?string
    {
        $candidate = $this->string($candidate);

        if ($candidate === null || Str::startsWith($candidate, ['data:', 'blob:', '#'])) {
            return null;
        }

        $image = $this->absoluteUrl(html_entity_decode($candidate, ENT_QUOTES | ENT_HTML5), $pageUrl);

        if (! Str::startsWith($image, ['http://', 'https://'])) {
            return null;
        }

        $lower = Str::lower($image);

        if (Str::contains($lower, [
            'favicon',
            'apple-touch-icon',
            'sprite',
            'spacer',
            'blank.',
            'placeholder',
            'avatar',
            'profile',
            'tracking',
            'pixel',
            '1x1',
            'logo',
        ])) {
            return null;
        }

        return $image;
    }

    private function absoluteUrl(string $url, string $pageUrl): string
    {
        if (Str::startsWith($url, ['http://', 'https://'])) {
            return $url;
        }

        $parts = parse_url($pageUrl);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? null;

        if (! $host) {
            return $url;
        }

        if (Str::startsWith($url, '//')) {
            return $scheme.':'.$url;
        }

        if (Str::startsWith($url, '/')) {
            return $scheme.'://'.$host.$url;
        }

        $path = $parts['path'] ?? '/';
        $basePath = rtrim(str_replace('\\', '/', dirname($path)), '/');

        return $scheme.'://'.$host.($basePath ? '/'.$basePath : '').'/'.$url;
    }

    private function ratingTone(string $rating): string
    {
        return FactCheckRatingNormalizer::toneFor($rating);
    }

    /**
     * @param  array<int, string>  $queries
     * @param  array<int, string>  $publisherSites
     * @return array<int, array{query: ?string, publisher: ?string, label: string, type: string}>
     */
    private function searches(array $queries, array $publisherSites): array
    {
        $publisherSearches = collect($publisherSites)
            ->map(fn (string $site): array => [
                'query' => null,
                'publisher' => $site,
                'label' => $this->publisherLabel($site),
                'type' => 'publisher',
            ]);

        $topicSearches = collect($queries)
            ->map(fn (string $query): array => [
                'query' => $query,
                'publisher' => null,
                'label' => $query,
                'type' => 'topic',
            ]);

        return $publisherSearches
            ->merge($topicSearches)
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function queries(): array
    {
        $configuredQueries = config('services.google_fact_check.feed_queries', []);

        if (is_string($configuredQueries)) {
            $configuredQueries = explode(',', $configuredQueries);
        }

        $maxQueries = max(1, min((int) config('services.google_fact_check.feed_max_queries', 3), 5));
        $queries = collect(is_array($configuredQueries) ? $configuredQueries : [])
            ->map(fn ($query): string => trim((string) $query))
            ->filter()
            ->unique()
            ->take($maxQueries)
            ->values()
            ->all();

        return $queries ?: ['Reuters fact check', 'Philippines', 'viral misinformation', 'fake news'];
    }

    /**
     * @return array<int, string>
     */
    private function publisherSites(): array
    {
        $configuredSites = config('services.google_fact_check.feed_publisher_sites', []);

        if (is_string($configuredSites)) {
            $configuredSites = explode(',', $configuredSites);
        }

        $maxPublishers = max(0, min((int) config('services.google_fact_check.feed_max_publishers', 7), 10));

        if ($maxPublishers === 0) {
            return [];
        }

        $fallbackSites = ['verafiles.org', 'rappler.com', 'factcheck.afp.com', 'abs-cbn.com', 'pressone.ph', 'tsek.ph', 'reuters.com'];
        $configuredSites = is_array($configuredSites) && $configuredSites !== []
            ? $configuredSites
            : $fallbackSites;

        return collect($this->managedPublisherSites())
            ->merge($configuredSites)
            ->map(fn ($site): string => trim((string) $site))
            ->filter()
            ->unique()
            ->take($maxPublishers)
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function managedPublisherSites(): array
    {
        try {
            if (! Schema::hasTable('fact_check_sources')) {
                return [];
            }

            return FactCheckSource::query()
                ->enabled()
                ->where('category', 'fact_check')
                ->get()
                ->map(fn (FactCheckSource $source): ?string => $source->displayDomain())
                ->filter()
                ->values()
                ->all();
        } catch (Throwable $exception) {
            Log::debug('Managed fact-check publisher sites could not be loaded.', [
                'message' => Str::limit($exception->getMessage(), 180),
            ]);

            return [];
        }
    }

    private function publisherLabel(string $site): string
    {
        return match ($site) {
            'verafiles.org' => 'VERA Files',
            'rappler.com' => 'Rappler',
            'factcheck.afp.com' => 'AFP Fact Check',
            'abs-cbn.com' => 'ABS-CBN Fact Check',
            'pressone.ph' => 'PressOne.PH',
            'tsek.ph' => 'Tsek.ph',
            'reuters.com' => 'Reuters',
            default => $site,
        };
    }

    private function sourceDomain(?string $publisherFilter, ?string $host, string $publisher): ?string
    {
        $domain = $this->normalizeDomain($publisherFilter)
            ?? $this->normalizeDomain($host)
            ?? $this->domainFromPublisherName($publisher);

        return $domain ? preg_replace('/^www\./', '', $domain) : null;
    }

    private function normalizeDomain(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = Str::lower(trim($value));

        if ($value === '') {
            return null;
        }

        if (Str::startsWith($value, ['http://', 'https://'])) {
            $host = parse_url($value, PHP_URL_HOST);

            return is_string($host) ? preg_replace('/^www\./', '', Str::lower($host)) : null;
        }

        $value = preg_replace('/^www\./', '', $value);
        $value = explode('/', (string) $value)[0] ?? $value;

        return filter_var($value, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) ? $value : null;
    }

    private function domainFromPublisherName(string $publisher): ?string
    {
        $publisher = Str::lower($publisher);

        return match (true) {
            Str::contains($publisher, 'rappler') => 'rappler.com',
            Str::contains($publisher, ['vera files', 'verafiles']) => 'verafiles.org',
            Str::contains($publisher, 'reuters') => 'reuters.com',
            Str::contains($publisher, ['afp', 'agence france']) => 'factcheck.afp.com',
            Str::contains($publisher, ['abs-cbn', 'abs cbn']) => 'abs-cbn.com',
            Str::contains($publisher, 'pressone') => 'pressone.ph',
            Str::contains($publisher, 'tsek') => 'tsek.ph',
            default => null,
        };
    }

    private function logoUrl(string $domain): string
    {
        return 'https://www.google.com/s2/favicons?sz=128&domain='.rawurlencode($domain);
    }

    private function string(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');

        return $value === '' ? null : $value;
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function persistItems(array $items): void
    {
        if (! $this->canPersistFeed() || $items === []) {
            return;
        }

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $feedItemId = $this->string($item['id'] ?? null);
            $headline = $this->string($item['headline'] ?? null);

            if ($feedItemId === null || $headline === null) {
                continue;
            }

            $publishedAt = $this->timestampToCarbon((int) ($item['timestamp'] ?? 0))
                ?? $this->parseDate($item['date_label'] ?? null);

            try {
                PublicClaimReview::query()->updateOrCreate(
                    ['feed_item_id' => Str::limit($feedItemId, 80, '')],
                    [
                        'publisher' => Str::limit((string) ($item['publisher'] ?? ''), 120, '') ?: null,
                        'headline' => Str::limit($headline, 180, ''),
                        'claim' => Str::limit((string) ($item['claim'] ?? ''), 1000, '') ?: null,
                        'claimant' => Str::limit((string) ($item['claimant'] ?? ''), 120, '') ?: null,
                        'rating' => Str::limit((string) ($item['rating'] ?? ''), 80, '') ?: null,
                        'tone' => Str::limit((string) ($item['tone'] ?? ''), 24, '') ?: null,
                        'source_domain' => Str::limit((string) ($item['source_domain'] ?? ($item['host'] ?? '')), 120, '') ?: null,
                        'url' => Str::limit((string) ($item['url'] ?? ''), 2048, '') ?: null,
                        'image_url' => Str::limit((string) ($item['image_url'] ?? ''), 2048, '') ?: null,
                        'logo_url' => Str::limit((string) ($item['logo_url'] ?? ''), 2048, '') ?: null,
                        'query' => Str::limit((string) ($item['query'] ?? ''), 255, '') ?: null,
                        'feed_source_type' => Str::limit((string) ($item['feed_source_type'] ?? ''), 80, '') ?: null,
                        'publisher_filter' => Str::limit((string) ($item['publisher_filter'] ?? ''), 120, '') ?: null,
                        'source_payload' => $item,
                        'published_at' => $publishedAt,
                        'first_seen_at' => PublicClaimReview::query()
                            ->where('feed_item_id', Str::limit($feedItemId, 80, ''))
                            ->value('first_seen_at') ?: now(),
                        'last_seen_at' => now(),
                    ],
                );
            } catch (Throwable $exception) {
                Log::warning('Public claim review feed persistence failed.', [
                    'feed_item_id' => $feedItemId,
                    'message' => $exception->getMessage(),
                ]);
            }
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function persistedItems(int $limit, int $maxAgeDays): array
    {
        if (! $this->canPersistFeed()) {
            return [];
        }

        $query = PublicClaimReview::query();

        if ($maxAgeDays > 0) {
            $query->where('published_at', '>=', now()->subDays($maxAgeDays)->startOfDay());
        }

        return $query
            ->latest('published_at')
            ->latest('last_seen_at')
            ->latest('id')
            ->limit(max(1, min($limit, self::MAX_FEED_LIMIT)))
            ->get()
            ->map(fn (PublicClaimReview $review): array => $review->toFeedItem())
            ->all();
    }

    private function findPersisted(string $id): ?array
    {
        if (! $this->canPersistFeed()) {
            return null;
        }

        $review = PublicClaimReview::query()
            ->where('feed_item_id', Str::limit($id, 80, ''))
            ->first();

        return $review?->toFeedItem();
    }

    private function latestPersistedAt(int $maxAgeDays): ?Carbon
    {
        if (! $this->canPersistFeed()) {
            return null;
        }

        $query = PublicClaimReview::query();

        if ($maxAgeDays > 0) {
            $query->where('published_at', '>=', now()->subDays($maxAgeDays)->startOfDay());
        }

        $value = $query->max('last_seen_at');

        return is_string($value) ? $this->parseDate($value) : null;
    }

    /**
     * @param  array{items: array<int, array<string, mixed>>}  $feed
     * @return array<string, mixed>
     */
    private function withPersistedItems(array $feed, int $limit, int $maxAgeDays): array
    {
        $persistedItems = $this->persistedItems($limit, $maxAgeDays);

        if ($persistedItems === []) {
            return $feed;
        }

        $feed['items'] = $persistedItems;
        $feed['updated_at'] = $this->latestPersistedAt($maxAgeDays);

        return $feed;
    }

    private function timestampToCarbon(int $timestamp): ?Carbon
    {
        return $timestamp > 0 ? Carbon::createFromTimestamp($timestamp) : null;
    }

    private function canPersistFeed(): bool
    {
        try {
            return Schema::hasTable('public_claim_reviews');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array<int, string>  $queries
     * @param  array<int, string>  $publisherSites
     * @return array{configured: bool, source_label: string, query_label: string, updated_at: null, cache_minutes: int, cache_seconds: int, cache_label: string, refresh_seconds: int, max_age_days: int, items: array<int, array<string, mixed>>}
     */
    private function emptyFeed(bool $configured, array $queries, array $publisherSites, int $maxAgeDays): array
    {
        $cacheSeconds = $this->feedCacheSeconds();

        return [
            'configured' => $configured,
            'source_label' => 'Google Fact Check API',
            'query_label' => implode(', ', array_merge($publisherSites, $queries)),
            'updated_at' => null,
            'cache_minutes' => (int) ceil($cacheSeconds / 60),
            'cache_seconds' => $cacheSeconds,
            'cache_label' => $this->secondsLabel($cacheSeconds),
            'refresh_seconds' => $this->feedRefreshSeconds($cacheSeconds),
            'max_age_days' => $maxAgeDays,
            'items' => [],
        ];
    }
}
