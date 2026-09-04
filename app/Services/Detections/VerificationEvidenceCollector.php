<?php

namespace App\Services\Detections;

use App\Models\FactCheckSource;
use App\Services\Automation\PlaywrightRunner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class VerificationEvidenceCollector
{
    public function __construct(
        private readonly PlaywrightRunner $playwrightRunner,
    ) {
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{
     *     query: string|null,
     *     scraped_evidence: array<int, array<string, mixed>>,
     *     verification_sources: array<int, array<string, string>>,
     *     summary: string|null
     * }
     */
    public function collect(array $context): array
    {
        if (! $this->isEnabled()) {
            return $this->emptyBundle();
        }

        $query = $this->buildQuery($context);

        if ($query === null) {
            return $this->emptyBundle();
        }

        $scrapedEvidence = array_merge(
            $this->collectWebEvidence($query),
            $this->collectApiEvidence($context, $query),
        );

        $scrapedEvidence = $this->deduplicateEvidence($scrapedEvidence);
        $maxItems = (int) config('playwright.verification_max_items', 8);
        $scrapedEvidence = array_slice($scrapedEvidence, 0, max(1, $maxItems));

        if ($scrapedEvidence === []) {
            return $this->emptyBundle($query);
        }

        $verificationSources = $this->buildVerificationSources($scrapedEvidence);

        return [
            'query' => $query,
            'scraped_evidence' => $scrapedEvidence,
            'verification_sources' => $verificationSources,
            'summary' => $this->buildSummary($scrapedEvidence),
        ];
    }

    private function isEnabled(): bool
    {
        if (app()->runningUnitTests()) {
            return false;
        }

        return (bool) config('playwright.verification_collection_enabled', true);
    }

    /**
     * @return array{
     *     query: string|null,
     *     scraped_evidence: array<int, array<string, mixed>>,
     *     verification_sources: array<int, array<string, string>>,
     *     summary: string|null
     * }
     */
    private function emptyBundle(?string $query = null): array
    {
        return [
            'query' => $query,
            'scraped_evidence' => [],
            'verification_sources' => [],
            'summary' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function buildQuery(array $context): ?string
    {
        $text = trim(collect([
            $this->nullableString($context['caption_text'] ?? null),
            $this->nullableString($context['notes'] ?? null),
            $this->sourceUrlTerms($this->nullableString($context['source_url'] ?? null)),
        ])->filter()->implode(' '));

        if ($text === '') {
            return null;
        }

        $terms = array_merge(
            $this->extractQuotedPhrases($text),
            $this->extractKeywords($text),
            $this->locationTerms($context),
        );

        $query = collect($terms)
            ->filter()
            ->unique()
            ->take(8)
            ->implode(' ');

        if ($query === '') {
            $query = Str::limit($text, (int) config('playwright.verification_query_length', 140), '');
        }

        return trim($query) !== '' ? trim($query) : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function collectWebEvidence(string $query): array
    {
        $collected = [];

        foreach ($this->verificationSourceConfigs() as $source) {
            if (! is_array($source) || blank($source['url_template'] ?? null)) {
                continue;
            }

            $targetUrl = str_replace('{query}', rawurlencode($query), (string) $source['url_template']);

            try {
                $result = $this->playwrightRunner->run([
                    'target_url' => $targetUrl,
                    'source_key' => $source['source_key'] ?? 'article-search',
                    'post_limit' => (int) config('playwright.verification_post_limit', 3),
                    'take_screenshot' => false,
                    'ready_selectors' => $source['ready_selectors'] ?? [],
                    'extra_selectors' => $source['extra_selectors'] ?? [],
                    'scroll_limit' => (int) config('playwright.verification_scroll_limit', 2),
                    'scroll_pause_ms' => min((int) config('playwright.scroll_pause_ms', 1200), 900),
                    'retries' => 1,
                ]);
            } catch (\Throwable $exception) {
                Log::channel((string) config('playwright.log_channel', 'playwright'))->warning(
                    'Verification source scrape failed',
                    [
                        'source_key' => $source['key'] ?? 'unknown',
                        'target_url' => $targetUrl,
                        'message' => $exception->getMessage(),
                    ]
                );

                continue;
            }

            foreach ((array) ($result['posts'] ?? []) as $post) {
                if (! is_array($post)) {
                    continue;
                }

                $evidence = $this->mapScrapedPostToEvidence($source, $post, $targetUrl, $query);

                if ($evidence !== null) {
                    $collected[] = $evidence;
                }
            }
        }

        return $collected;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function verificationSourceConfigs(): array
    {
        $configuredSources = collect((array) config('playwright.verification_sources', []))
            ->filter(fn ($source): bool => is_array($source) && filled($source['url_template'] ?? null))
            ->values();

        $managedSources = collect();

        try {
            if (Schema::hasTable('fact_check_sources')) {
                $managedSources = FactCheckSource::query()
                    ->enabled()
                    ->orderBy('name')
                    ->get()
                    ->map(fn (FactCheckSource $source): array => $source->toVerificationSourceConfig());
            }
        } catch (\Throwable $exception) {
            Log::debug('Managed fact-check sources could not be loaded for scraping.', [
                'message' => Str::limit($exception->getMessage(), 180),
            ]);
        }

        return $configuredSources
            ->merge($managedSources)
            ->unique(fn (array $source): string => Str::lower((string) ($source['key'] ?? $source['url_template'] ?? '')))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $post
     * @return array<string, mixed>|null
     */
    private function mapScrapedPostToEvidence(array $source, array $post, string $targetUrl, string $query): ?array
    {
        $headline = $this->nullableString(data_get($post, 'rawPayload.headline'))
            ?? $this->nullableString($post['captionText'] ?? null);
        $summary = $this->nullableString(data_get($post, 'rawPayload.summary'))
            ?? $this->nullableString($post['captionText'] ?? null);
        $url = $this->nullableString($post['postUrl'] ?? null)
            ?? collect($post['sourceLinks'] ?? [])->filter(fn ($value) => is_string($value) && $value !== '')->first();

        if ($headline === null || $url === null) {
            return null;
        }

        if (Str::contains(Str::lower($headline), [
            'manage your privacy choices',
            'cookie settings',
            'newsletter',
            'advertisement',
        ])) {
            return null;
        }

        if (! $this->matchesQuery($query, trim(($headline ?? '').' '.($summary ?? '')))) {
            return null;
        }

        $provider = (string) ($source['name'] ?? 'Verification source');
        $category = (string) ($source['category'] ?? 'reference');
        $status = $category === 'fact_check' ? 'scraped-fact-check-match' : 'scraped-news-match';
        $purposePrefix = $category === 'fact_check'
            ? "Playwright scraped a fact-check result from {$provider}."
            : "Playwright scraped a newsroom result from {$provider}.";
        $purpose = trim($purposePrefix.' '.Str::limit($summary ?? $headline, 180, ''));

        return [
            'provider' => $provider,
            'provider_key' => (string) ($source['key'] ?? Str::slug($provider)),
            'category' => $category,
            'collection_method' => 'playwright',
            'headline' => $headline,
            'summary' => $summary ?? $headline,
            'url' => $url,
            'published_at' => $this->normalizeDateString($post['postedAt'] ?? null),
            'search_url' => $targetUrl,
            'status' => $status,
            'purpose' => $purpose,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<int, array<string, mixed>>
     */
    private function collectApiEvidence(array $context, string $query): array
    {
        return array_merge(
            $this->fetchGoogleFactCheckEvidence($query),
            $this->fetchGNewsEvidence($query),
            $this->fetchNewsApiEvidence($query),
            $this->fetchOpenWeatherEvidence($context, $query),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchGoogleFactCheckEvidence(string $query): array
    {
        $apiKey = trim((string) config('services.google_fact_check.key'));

        if ($apiKey === '') {
            return [];
        }

        $payload = $this->requestJson(
            'https://factchecktools.googleapis.com/v1alpha1/claims:search',
            [
                'query' => $query,
                'languageCode' => config('services.google_fact_check.language_code', 'en-US'),
                'pageSize' => (int) config('services.google_fact_check.page_size', 3),
                'key' => $apiKey,
            ],
            (int) config('services.google_fact_check.timeout', 12),
            'Google Fact Check API',
        );

        $claims = $payload['claims'] ?? null;

        if (! is_array($claims)) {
            return [];
        }

        return collect($claims)
            ->filter(fn ($claim) => is_array($claim))
            ->flatMap(function (array $claim): array {
                $text = $this->nullableString($claim['text'] ?? null);
                $claimReviews = $claim['claimReview'] ?? [];

                if (! is_array($claimReviews)) {
                    return [];
                }

                return collect($claimReviews)
                    ->filter(fn ($review) => is_array($review))
                    ->map(function (array $review) use ($text): ?array {
                        $publisher = $this->nullableString(data_get($review, 'publisher.name'))
                            ?? 'Google Fact Check';
                        $url = $this->nullableString($review['url'] ?? null);

                        if ($url === null) {
                            return null;
                        }

                        $reviewTitle = $this->nullableString($review['title'] ?? null) ?? $text ?? $publisher;
                        $rating = $this->nullableString($review['textualRating'] ?? null);

                        return [
                            'provider' => $publisher,
                            'provider_key' => 'google-fact-check',
                            'category' => 'fact_check',
                            'collection_method' => 'api',
                            'headline' => $reviewTitle,
                            'summary' => trim(collect([
                                $text ? "Claim: {$text}." : null,
                                $rating ? "Rating: {$rating}." : null,
                            ])->filter()->implode(' ')),
                            'url' => $url,
                            'published_at' => $this->normalizeDateString($review['reviewDate'] ?? null),
                            'search_url' => null,
                            'status' => 'api-fact-check-match',
                            'purpose' => trim("API match from {$publisher}. ".($rating ? "Rating: {$rating}." : 'Review this related claim check.')),
                        ];
                    })
                    ->filter()
                    ->values()
                    ->all();
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchGNewsEvidence(string $query): array
    {
        $apiKey = trim((string) config('services.gnews.key'));

        if ($apiKey === '') {
            return [];
        }

        $payload = $this->requestJson(
            'https://gnews.io/api/v4/search',
            array_filter([
                'q' => $query,
                'lang' => config('services.gnews.lang', 'en'),
                'country' => config('services.gnews.country'),
                'max' => (int) config('services.gnews.max', 3),
                'from' => now()->subHours((int) config('services.gnews.lookback_hours', 168))->toIso8601String(),
                'apikey' => $apiKey,
            ], fn ($value) => filled($value)),
            (int) config('services.gnews.timeout', 12),
            'GNews API',
        );

        $articles = $payload['articles'] ?? null;

        return $this->mapNewsArticlesToEvidence(is_array($articles) ? $articles : [], 'GNews', 'api-news-match');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchNewsApiEvidence(string $query): array
    {
        $apiKey = trim((string) config('services.newsapi.key'));

        if ($apiKey === '') {
            return [];
        }

        $payload = $this->requestJson(
            'https://newsapi.org/v2/everything',
            [
                'q' => $query,
                'language' => config('services.newsapi.language', 'en'),
                'pageSize' => (int) config('services.newsapi.page_size', 3),
                'from' => now()->subDays((int) config('services.newsapi.lookback_days', 7))->format('Y-m-d'),
            ],
            (int) config('services.newsapi.timeout', 12),
            'NewsAPI',
            [
                'X-Api-Key' => $apiKey,
            ],
        );

        $articles = $payload['articles'] ?? null;

        return $this->mapNewsArticlesToEvidence(is_array($articles) ? $articles : [], 'NewsAPI', 'api-news-match');
    }

    /**
     * @param  array<int, array<string, mixed>>  $articles
     * @return array<int, array<string, mixed>>
     */
    private function mapNewsArticlesToEvidence(array $articles, string $fallbackProvider, string $status): array
    {
        return collect($articles)
            ->filter(fn ($article) => is_array($article))
            ->map(function (array $article) use ($fallbackProvider, $status): ?array {
                $url = $this->nullableString($article['url'] ?? null);
                $headline = $this->nullableString($article['title'] ?? null);

                if ($url === null || $headline === null) {
                    return null;
                }

                $provider = $this->nullableString(data_get($article, 'source.name'))
                    ?? $fallbackProvider;
                $summary = $this->nullableString($article['description'] ?? null)
                    ?? $headline;

                return [
                    'provider' => $provider,
                    'provider_key' => Str::slug($provider),
                    'category' => 'news',
                    'collection_method' => 'api',
                    'headline' => $headline,
                    'summary' => $summary,
                    'url' => $url,
                    'published_at' => $this->normalizeDateString($article['publishedAt'] ?? null),
                    'search_url' => null,
                    'status' => $status,
                    'purpose' => trim("API match from {$provider}. ".Str::limit($summary, 180, '')),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<int, array<string, mixed>>
     */
    private function fetchOpenWeatherEvidence(array $context, string $query): array
    {
        $apiKey = trim((string) config('services.openweather.key'));

        if ($apiKey === '' || ! $this->looksWeatherRelated($query)) {
            return [];
        }

        $lat = $this->normalizeCoordinate($context['weather_lat'] ?? null, -90, 90);
        $lon = $this->normalizeCoordinate($context['weather_lon'] ?? null, -180, 180);
        $label = $this->nullableString($context['weather_label'] ?? null);

        if ($lat === null || $lon === null) {
            return [];
        }

        $payload = $this->requestJson(
            'https://api.openweathermap.org/data/2.5/weather',
            [
                'lat' => $lat,
                'lon' => $lon,
                'appid' => $apiKey,
                'units' => config('services.openweather.units', 'metric'),
                'lang' => config('services.openweather.lang', 'en'),
            ],
            (int) config('services.openweather.timeout', 12),
            'OpenWeatherMap',
        );

        if (! is_array($payload) || $payload === []) {
            return [];
        }

        $weatherDescription = $this->nullableString(data_get($payload, 'weather.0.description'));
        $temperature = data_get($payload, 'main.temp');
        $cityName = $this->nullableString($payload['name'] ?? null) ?? $label ?? 'Current location';
        $country = $this->nullableString(data_get($payload, 'sys.country'));
        $location = $country ? "{$cityName}, {$country}" : $cityName;
        $cityId = data_get($payload, 'id');
        $observedAt = $this->normalizeDateString(
            is_numeric($payload['dt'] ?? null)
                ? Carbon::createFromTimestamp((int) $payload['dt'])->toIso8601String()
                : null
        );

        $summary = trim(collect([
            "Observed weather for {$location}.",
            $weatherDescription ? "Conditions: {$weatherDescription}." : null,
            is_numeric($temperature) ? 'Temperature: '.round((float) $temperature, 1).' '.$this->weatherUnitsLabel().'.' : null,
        ])->filter()->implode(' '));

        return [[
            'provider' => 'OpenWeatherMap',
            'provider_key' => 'openweather',
            'category' => 'weather',
            'collection_method' => 'api',
            'headline' => "Current weather for {$location}",
            'summary' => $summary,
            'url' => is_numeric($cityId)
                ? 'https://openweathermap.org/city/'.rawurlencode((string) $cityId)
                : 'https://openweathermap.org/current',
            'published_at' => $observedAt,
            'search_url' => null,
            'status' => 'api-weather-match',
            'purpose' => "API observation from OpenWeatherMap for {$location}.",
        ]];
    }

    /**
     * @param  array<int, array<string, mixed>>  $scrapedEvidence
     * @return array<int, array<string, string>>
     */
    private function buildVerificationSources(array $scrapedEvidence): array
    {
        return collect($scrapedEvidence)
            ->map(function (array $item): ?array {
                $provider = $this->nullableString($item['provider'] ?? null);
                $url = $this->nullableString($item['url'] ?? null);
                $headline = $this->nullableString($item['headline'] ?? null);
                $summary = $this->nullableString($item['summary'] ?? null);
                $status = $this->nullableString($item['status'] ?? null) ?? 'scraped-match';

                if ($provider === null || $url === null || $headline === null) {
                    return null;
                }

                $category = (string) ($item['category'] ?? 'reference');

                return array_filter([
                    'name' => $provider,
                    'status' => $status,
                    'purpose' => $this->nullableString($item['purpose'] ?? null)
                        ?? Str::limit($headline, 180, ''),
                    'url' => $url,
                    'source_type' => $category,
                    'label' => match ($category) {
                        'fact_check' => 'Fact-check',
                        'news' => 'News',
                        'weather' => 'Weather',
                        default => 'Reference',
                    },
                    'summary' => $summary ?? $headline,
                    'published_at' => $this->formatDate($item['published_at'] ?? null),
                ], fn ($value) => $value !== null && $value !== '');
            })
            ->filter()
            ->unique(fn (array $source) => Str::lower(($source['url'] ?? '').'|'.($source['name'] ?? '')))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $scrapedEvidence
     * @return array<int, array<string, mixed>>
     */
    private function deduplicateEvidence(array $scrapedEvidence): array
    {
        return collect($scrapedEvidence)
            ->filter(fn ($item) => is_array($item))
            ->unique(function (array $item): string {
                return Str::lower(trim(($item['url'] ?? '').'|'.($item['headline'] ?? '')));
            })
            ->sortByDesc(function (array $item): int {
                return match ($item['category'] ?? 'reference') {
                    'fact_check' => 3,
                    'news' => 2,
                    'weather' => 1,
                    default => 0,
                };
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $scrapedEvidence
     */
    private function buildSummary(array $scrapedEvidence): string
    {
        $factCheckCount = collect($scrapedEvidence)->where('category', 'fact_check')->count();
        $newsCount = collect($scrapedEvidence)->where('category', 'news')->count();
        $weatherCount = collect($scrapedEvidence)->where('category', 'weather')->count();

        $parts = [];

        if ($factCheckCount > 0) {
            $parts[] = "{$factCheckCount} fact-check match".($factCheckCount === 1 ? '' : 'es');
        }

        if ($newsCount > 0) {
            $parts[] = "{$newsCount} newsroom match".($newsCount === 1 ? '' : 'es');
        }

        if ($weatherCount > 0) {
            $parts[] = "{$weatherCount} weather data point".($weatherCount === 1 ? '' : 's');
        }

        return $parts === []
            ? 'Collected verification evidence from configured sources.'
            : 'Collected '.implode(', ', $parts).' before AI analysis.';
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>|null
     */
    private function requestJson(
        string $url,
        array $query,
        int $timeout,
        string $serviceName,
        array $headers = [],
    ): ?array {
        try {
            $response = Http::acceptJson()
                ->connectTimeout(min(2, max(1, $timeout)))
                ->timeout($timeout)
                ->withHeaders($headers)
                ->get($url, $query);

            if (! $response->successful()) {
                Log::warning("{$serviceName} request failed during evidence collection.", [
                    'status' => $response->status(),
                    'url' => $url,
                ]);

                return null;
            }

            $data = $response->json();

            return is_array($data) ? $data : null;
        } catch (\Throwable $exception) {
            Log::warning("{$serviceName} request threw during evidence collection.", [
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return list<string>
     */
    private function extractKeywords(string $text, int $limit = 6): array
    {
        $stopWords = [
            'about', 'after', 'again', 'against', 'already', 'also', 'always', 'before', 'being', 'claim', 'claims',
            'compare', 'could', 'describe', 'detection', 'depicts', 'display', 'displays', 'displaying', 'evidence',
            'false', 'fake', 'from', 'have', 'image', 'images', 'input', 'into', 'just', 'link', 'look', 'looks',
            'more', 'must', 'named', 'need', 'news', 'page', 'paste', 'post', 'presents', 'presenting', 'reporting',
            'same', 'screenshots', 'share', 'should', 'show', 'showing', 'shows', 'source', 'sources', 'submitted',
            'text', 'that', 'their', 'there', 'these', 'they', 'this', 'those', 'using', 'video', 'viral', 'what',
            'when', 'where', 'which', 'with', 'would',
        ];

        $normalized = preg_replace('/[^a-z0-9\s-]+/i', ' ', Str::lower($text)) ?? Str::lower($text);

        return collect(explode(' ', $normalized))
            ->map(fn (string $word) => trim($word))
            ->filter(function (string $word) use ($stopWords): bool {
                if ($word === '' || in_array($word, $stopWords, true)) {
                    return false;
                }

                if (ctype_digit($word)) {
                    return strlen($word) === 4;
                }

                return strlen($word) >= 4;
            })
            ->unique()
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function extractQuotedPhrases(string $text): array
    {
        preg_match_all('/"([^"]{3,80})"/u', $text, $matches);

        return collect($matches[1] ?? [])
            ->map(fn ($phrase) => trim((string) $phrase))
            ->filter()
            ->take(2)
            ->values()
            ->all();
    }

    private function sourceUrlTerms(?string $sourceUrl): string
    {
        if ($sourceUrl === null || ! Str::startsWith($sourceUrl, ['http://', 'https://'])) {
            return '';
        }

        $parts = parse_url($sourceUrl);

        if (! is_array($parts)) {
            return '';
        }

        $fragments = collect([
            $parts['host'] ?? null,
            isset($parts['path']) ? urldecode((string) $parts['path']) : null,
            isset($parts['query']) ? urldecode((string) $parts['query']) : null,
        ])->filter()->implode(' ');

        $normalized = preg_replace('/[^a-z0-9]+/i', ' ', Str::lower($fragments)) ?? Str::lower($fragments);

        return collect(explode(' ', $normalized))
            ->map(fn (string $term) => trim($term))
            ->reject(fn (string $term) => $term === '' || is_numeric($term))
            ->reject(fn (string $term) => in_array($term, [
                'www', 'http', 'https', 'com', 'org', 'net', 'facebook', 'instagram', 'tiktok', 'twitter', 'youtube',
                'youtu', 'post', 'posts', 'story', 'stories',
            ], true))
            ->unique()
            ->take(4)
            ->implode(' ');
    }

    /**
     * @param  array<string, mixed>  $context
     * @return list<string>
     */
    private function locationTerms(array $context): array
    {
        $label = $this->nullableString($context['weather_label'] ?? null);

        if ($label === null || $this->isGenericLocationLabel($label)) {
            return [];
        }

        return collect(explode(',', $label))
            ->map(fn (string $part) => trim($part))
            ->filter()
            ->take(2)
            ->values()
            ->all();
    }

    private function isGenericLocationLabel(string $label): bool
    {
        return in_array(Str::lower(trim($label)), [
            'current location',
            'my location',
            'your location',
            'device location',
            'approximate location',
        ], true);
    }

    private function looksWeatherRelated(string $query): bool
    {
        return Str::contains(Str::lower($query), [
            'weather', 'typhoon', 'storm', 'flood', 'rainfall', 'bagyo', 'forecast',
        ]);
    }

    private function matchesQuery(string $query, string $body): bool
    {
        $keywords = $this->extractKeywords($query, 5);

        if ($keywords === []) {
            return true;
        }

        $haystack = Str::lower($body);
        $matches = collect($keywords)
            ->filter(fn (string $keyword) => Str::contains($haystack, Str::lower($keyword)))
            ->count();

        return $matches >= 1;
    }

    private function weatherUnitsLabel(): string
    {
        return match ((string) config('services.openweather.units', 'metric')) {
            'imperial' => 'F',
            'standard' => 'K',
            default => 'C',
        };
    }

    private function normalizeCoordinate(mixed $value, float $min, float $max): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        if ($number < $min || $number > $max) {
            return null;
        }

        return round($number, 4);
    }

    private function normalizeDateString(mixed $value): ?string
    {
        $normalized = $this->nullableString(is_scalar($value) ? (string) $value : null);

        if ($normalized === null) {
            return null;
        }

        try {
            return Carbon::parse($normalized)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    private function formatDate(mixed $value): ?string
    {
        $normalized = $this->normalizeDateString($value);

        if ($normalized === null) {
            return null;
        }

        try {
            return Carbon::parse($normalized)->format('M d, Y');
        } catch (\Throwable) {
            return null;
        }
    }

    private function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}
