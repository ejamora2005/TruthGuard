<?php

namespace App\Services\Detections;

use App\Models\FactCheckSource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class LiveVerificationEvidenceService
{
    public function __construct(
        private readonly TrustedFactCheckVerdictResolver $trustedFactCheckVerdictResolver,
        private readonly GoogleFactCheckFeedService $googleFactCheckFeedService,
    ) {
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function enrich(array $analysis, array $context): array
    {
        if (! $this->isEnabled()) {
            return $analysis;
        }

        $claimText = $this->buildClaimText($analysis, $context);
        $topic = $this->detectTopic($claimText);
        $locationContext = $this->resolveLocationContext($context);
        $factCheckQuery = $this->buildFactCheckQuery($claimText, $locationContext, $topic);
        $newsQuery = $this->buildNewsQuery($claimText, $topic, $locationContext);

        $factChecks = $factCheckQuery !== null
            ? $this->fetchFactChecks($factCheckQuery)
            : [];
        $publisherFactChecks = $this->fetchPublisherFeedMatches($claimText, $topic);
        $gnewsArticles = $newsQuery !== null
            ? $this->fetchGNewsArticles($newsQuery, $topic, $claimText)
            : [];
        $newsApiArticles = $newsQuery !== null
            ? $this->fetchNewsApiArticles($newsQuery, $topic, $claimText)
            : [];
        $weatherSnapshot = $topic === 'weather'
            ? $this->fetchWeatherSnapshot($claimText, $context)
            : null;
        $weatherForecast = $topic === 'weather'
            ? $this->fetchWeatherForecast($claimText, $context)
            : null;

        $liveSources = array_merge(
            $this->buildNewsSources($gnewsArticles, 'GNews'),
            $this->buildNewsSources($newsApiArticles, 'NewsAPI'),
            $this->buildOfficialSources($context, $topic, $weatherSnapshot, $weatherForecast),
            $this->buildSourceTraceCard($context),
            $this->buildCrossPlatformSocialSources($claimText, $context),
            $this->buildFactCheckSources($factChecks),
            $this->buildPublisherFeedFactCheckSources($publisherFactChecks),
            $this->buildManagedSourceRoutes($factCheckQuery ?? $newsQuery ?? $claimText),
        );

        $legacySources = collect($this->normalizeLegacySources($analysis['verification_sources'] ?? []))
            ->reject(fn (array $source): bool => ($source['source_type'] ?? null) === 'fact_check')
            ->values()
            ->all();
        $verificationSources = $this->deduplicateSources(
            $this->pruneIrrelevantSources(array_merge($liveSources, $legacySources), $claimText, $topic)
        );

        if ($verificationSources === []) {
            return $this->trustedFactCheckVerdictResolver->apply($analysis, $factChecks, []);
        }

        $analysis['verification_sources'] = $verificationSources;
        $analysis['verification_summary'] = $this->buildVerificationSummary(
            $factChecks,
            $gnewsArticles,
            $newsApiArticles,
            $topic,
            $weatherSnapshot !== null,
            $verificationSources,
            $locationContext,
            (string) ($analysis['verification_summary'] ?? ''),
        );
        $analysis['explanation_summary'] = $this->buildExplanationSummary(
            (string) ($analysis['verdict'] ?? 'review'),
            (int) ($analysis['fake_score'] ?? 0),
            $analysis['signals'] ?? [],
            $factChecks,
            $verificationSources,
            (string) ($analysis['explanation_summary'] ?? ''),
            $topic,
            $weatherForecast,
        );
        $analysis['recommendation'] = $this->buildRecommendation(
            (string) ($analysis['verdict'] ?? 'review'),
            $factChecks,
            $verificationSources,
            $topic,
            (string) ($analysis['recommendation'] ?? ''),
        );

        return $this->trustedFactCheckVerdictResolver->apply($analysis, $factChecks, $verificationSources);
    }

    private function isEnabled(): bool
    {
        if (app()->runningUnitTests()) {
            return false;
        }

        return (bool) config('services.live_verification.enabled', true);
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @param  array<string, mixed>  $context
     */
    private function buildClaimText(array $analysis, array $context): string
    {
        $sourceTerms = $this->extractSourceUrlTerms((string) ($context['source_url'] ?? ''));
        $analysisTerms = $this->extractAnalysisTerms($analysis);

        $raw = collect([
            $context['caption_text'] ?? null,
            $context['notes'] ?? null,
            $context['openai_extracted_claim'] ?? null,
            $sourceTerms !== '' ? $sourceTerms : null,
            $context['platform'] ?? null,
            $analysisTerms !== '' ? $analysisTerms : null,
        ])->filter()->implode(' ');

        $normalized = preg_replace('/\s+/', ' ', trim((string) $raw)) ?? trim((string) $raw);

        return Str::limit($normalized, 220, '');
    }

    /**
     * @param  array<string, mixed>  $analysis
     */
    private function extractAnalysisTerms(array $analysis): string
    {
        $analysisSummary = trim((string) ($analysis['analysis_summary'] ?? ''));
        $signalLabels = collect($analysis['signals'] ?? [])
            ->except(['ai_basis', 'ai_limitations', 'openai_usage', 'trusted_fact_check', 'verification_policy'])
            ->filter(fn ($group) => is_array($group))
            ->flatten(1)
            ->filter(fn ($signal) => is_array($signal))
            ->sortByDesc(fn (array $signal) => (int) ($signal['weight'] ?? 0))
            ->pluck('label')
            ->filter()
            ->take(3)
            ->implode(' ');

        return trim(collect([$analysisSummary, $signalLabels])->filter()->implode(' '));
    }

    private function extractSourceUrlTerms(string $url): string
    {
        $url = trim($url);

        if ($url === '' || ! Str::startsWith($url, ['http://', 'https://'])) {
            return '';
        }

        $parts = parse_url($url);

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
                'www', 'http', 'https', 'com', 'org', 'net', 'php', 'html', 'watch', 'video', 'videos',
                'image', 'images', 'photo', 'photos', 'facebook', 'instagram', 'tiktok', 'twitter', 'youtube',
                'youtu', 'fact', 'check', 'post', 'posts', 'story', 'stories',
            ], true))
            ->unique()
            ->take(6)
            ->implode(' ');
    }

    private function detectTopic(string $claimText): string
    {
        $context = Str::lower($claimText);

        return match (true) {
            $context !== '' && Str::contains($context, ['sona', 'state of the nation', 'national address', 'walang pasok', 'class suspension', 'classes suspended', 'suspension of classes', 'no classes', 'malacanang', 'malacañang', 'proclamation']) => 'class_suspension',
            $context !== '' && Str::contains($context, ['typhoon', 'storm', 'flood', 'rainfall', 'weather', 'par', 'pagasa', 'tropical depression', 'bagyo', 'philippine area of responsibility']) => 'weather',
            $context !== '' && Str::contains($context, ['earthquake', 'eruption', 'volcano', 'ashfall', 'phivolcs', 'ndrrmc']) => 'disaster',
            $context !== '' && Str::contains($context, ['vaccine', 'virus', 'outbreak', 'hospital', 'health', 'doh', 'who']) => 'health',
            $context !== '' && Str::contains($context, ['election', 'vote', 'ballot', 'president', 'senator', 'mayor', 'comelec']) => 'election',
            default => 'general',
        };
    }

    /**
     * @param  array<string, mixed>  $locationContext
     */
    private function buildFactCheckQuery(string $claimText, array $locationContext, string $topic = 'general'): ?string
    {
        $claimText = trim($claimText);

        if ($claimText === '') {
            return null;
        }

        if ($topic === 'class_suspension') {
            return $this->buildTopicAwareQuery($claimText, ['SONA', 'class suspension', 'walang pasok', 'Marcos', 'Malacanang'], $locationContext);
        }

        $query = Str::limit($claimText, 180, '');

        return $this->appendLocationToQuery($query, $locationContext, 180);
    }

    /**
     * @param  array<string, mixed>  $locationContext
     */
    private function buildNewsQuery(string $claimText, string $topic, array $locationContext): ?string
    {
        $claimText = trim($claimText);

        return match ($topic) {
            'class_suspension' => $this->buildTopicAwareQuery($claimText, ['SONA', 'class suspension', 'walang pasok', 'Marcos', 'Malacanang', 'Official Gazette', 'DepEd'], $locationContext),
            'weather' => $this->buildWeatherQuery($claimText, $locationContext),
            'disaster' => $this->buildTopicAwareQuery($claimText, ['earthquake', 'eruption', 'volcano', 'ashfall', 'phivolcs', 'philippines'], $locationContext),
            'health' => $this->buildTopicAwareQuery($claimText, ['health', 'vaccine', 'outbreak', 'virus', 'department of health'], $locationContext),
            'election' => $this->buildTopicAwareQuery($claimText, ['election', 'vote', 'ballot', 'comelec', 'philippines'], $locationContext),
            default => $claimText !== '' ? $this->appendLocationToQuery(Str::limit($claimText, 120, ''), $locationContext, 120) : null,
        };
    }

    /**
     * @param  array<string, mixed>  $locationContext
     */
    private function buildWeatherQuery(string $claimText, array $locationContext): string
    {
        $claimText = Str::lower($claimText);
        $terms = array_merge(['pagasa', 'philippines'], $this->extractQuotedPhrases($claimText), $this->extractKeywords($claimText, 6));

        if (Str::contains($claimText, ['typhoon', 'bagyo'])) {
            $terms[] = 'typhoon';
        }

        if (Str::contains($claimText, ['storm', 'tropical depression'])) {
            $terms[] = 'storm';
        }

        if (Str::contains($claimText, ['flood', 'rainfall'])) {
            $terms[] = 'flood';
        }

        if (Str::contains($claimText, ['par', 'philippine area of responsibility'])) {
            $terms[] = 'PAR';
        }

        if (Str::contains($claimText, ['manila', 'metro manila'])) {
            $terms[] = 'Manila';
        }

        $terms = array_merge($terms, $this->locationQueryTerms($locationContext));

        return collect($terms)
            ->map(fn (string $term) => trim($term))
            ->filter()
            ->unique()
            ->take(8)
            ->implode(' ');
    }

    /**
     * @param  list<string>  $fallbackTerms
     * @param  array<string, mixed>  $locationContext
     */
    private function buildTopicAwareQuery(string $claimText, array $fallbackTerms, array $locationContext): string
    {
        $keywords = $this->extractKeywords($claimText);

        if ($keywords === []) {
            $base = implode(' ', $fallbackTerms);

            return $this->appendLocationToQuery($base, $locationContext, 140);
        }

        $base = collect(array_merge($keywords, $fallbackTerms))
            ->filter()
            ->unique()
            ->take(8)
            ->implode(' ');

        return $this->appendLocationToQuery($base, $locationContext, 140);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function resolveLocationContext(array $context): array
    {
        $consent = filter_var($context['weather_consent'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $lat = $this->normalizeCoordinate($context['weather_lat'] ?? null, -90, 90);
        $lon = $this->normalizeCoordinate($context['weather_lon'] ?? null, -180, 180);

        if (! $consent || $lat === null || $lon === null) {
            return [
                'enabled' => false,
                'label' => null,
                'query_terms' => [],
            ];
        }

        $label = trim((string) ($context['weather_label'] ?? ''));

        if ($label === '' || $this->isGenericLocationLabel($label)) {
            $label = $this->reverseGeocodeOpenWeather($lat, $lon) ?? 'Current location';
        }

        return [
            'enabled' => true,
            'label' => $label,
            'query_terms' => $this->locationQueryTerms(['enabled' => true, 'label' => $label]),
        ];
    }

    /**
     * @param  array<string, mixed>  $locationContext
     * @return list<string>
     */
    private function locationQueryTerms(array $locationContext): array
    {
        if (! ($locationContext['enabled'] ?? false)) {
            return [];
        }

        $label = trim((string) ($locationContext['label'] ?? ''));

        if ($label === '' || $this->isGenericLocationLabel($label)) {
            return ['local'];
        }

        $parts = collect(explode(',', $label))
            ->map(fn (string $part) => trim($part))
            ->filter()
            ->values();

        return collect([$label, ...$parts])
            ->filter()
            ->unique()
            ->take(3)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $locationContext
     */
    private function appendLocationToQuery(string $query, array $locationContext, int $limit): string
    {
        $query = trim($query);

        if ($query === '') {
            return $query;
        }

        $terms = $this->locationQueryTerms($locationContext);

        if ($terms !== []) {
            $query = trim($query.' '.implode(' ', $terms));
        }

        return Str::limit($query, $limit, '');
    }

    /**
     * @param  array<string, mixed>  $locationContext
     */
    private function appendLocationSummary(string $summary, array $locationContext): string
    {
        if (! ($locationContext['enabled'] ?? false)) {
            return $summary;
        }

        $label = trim((string) ($locationContext['label'] ?? ''));
        $contextLine = $label === '' || $this->isGenericLocationLabel($label)
            ? 'Location context used: your device location.'
            : "Location context used: {$label}.";

        if ($contextLine === '' || Str::contains($summary, $contextLine)) {
            return $summary;
        }

        return rtrim($summary).' '.$contextLine;
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

    private function reverseGeocodeOpenWeather(float $lat, float $lon): ?string
    {
        $apiKey = trim((string) config('services.openweather.key'));

        if ($apiKey === '') {
            return null;
        }

        $data = $this->requestJson(
            'https://api.openweathermap.org/geo/1.0/reverse',
            [
                'lat' => $lat,
                'lon' => $lon,
                'limit' => 1,
                'appid' => $apiKey,
            ],
            (int) config('services.openweather.timeout', 12),
            'OpenWeather geocoding'
        );

        if (! is_array($data) || $data === []) {
            return null;
        }

        $first = $data[0] ?? null;

        if (! is_array($first)) {
            return null;
        }

        $name = trim((string) ($first['name'] ?? ''));
        $country = trim((string) ($first['country'] ?? ''));

        if ($name === '') {
            return null;
        }

        return $country !== '' ? "{$name}, {$country}" : $name;
    }

    /**
     * @return list<string>
     */
    private function extractKeywords(string $text, int $limit = 6): array
    {
        $stopWords = [
            'about', 'after', 'again', 'against', 'already', 'also', 'always', 'before', 'being', 'claim', 'claims',
            'compare', 'could', 'describe', 'detection', 'depicts', 'display', 'displays', 'displaying', 'evidence',
            'false', 'fake', 'fact', 'fact-check', 'factcheck', 'from', 'google', 'have', 'image', 'images', 'input', 'into', 'just', 'link', 'look', 'looks',
            'map', 'more', 'must', 'named', 'need', 'news', 'page', 'paste', 'post', 'presents', 'presenting',
            'rappler', 'rated', 'rating', 'review', 'reviewed', 'reporting', 'same', 'screenshot', 'screenshots',
            'share', 'should', 'show', 'showing', 'shows', 'source', 'sources', 'submission', 'submitted', 'text',
            'that', 'their', 'there', 'these', 'they', 'this', 'those', 'trusted', 'truthguard', 'upload', 'uploaded',
            'using', 'vera', 'verafiles', 'verified', 'video', 'viral', 'want', 'what', 'when', 'where', 'which', 'with', 'would',
            'year', 'years',
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
            ->take(3)
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchFactChecks(string $query): array
    {
        $apiKey = trim((string) config('services.google_fact_check.key'));

        if ($apiKey === '') {
            return [];
        }

        $data = $this->requestJson(
            'https://factchecktools.googleapis.com/v1alpha1/claims:search',
            [
                'query' => $query,
                'languageCode' => config('services.google_fact_check.language_code', 'en-US'),
                'pageSize' => (int) config('services.google_fact_check.page_size', 3),
                'key' => $apiKey,
            ],
            (int) config('services.google_fact_check.timeout', 12),
            'Google Fact Check API'
        );

        $claims = $data['claims'] ?? null;

        return is_array($claims) ? $claims : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchPublisherFeedMatches(string $claimText, string $topic): array
    {
        $keywords = $this->extractKeywords($claimText, 10);
        $quotedPhrases = $this->extractQuotedPhrases($claimText);

        if ($claimText === '' || ($keywords === [] && $quotedPhrases === [])) {
            return [];
        }

        try {
            $feed = $this->googleFactCheckFeedService->latest(20);
        } catch (\Throwable $exception) {
            Log::debug('Trusted publisher feed matching failed.', [
                'message' => Str::limit($exception->getMessage(), 180),
            ]);

            return [];
        }

        return collect($feed['items'] ?? [])
            ->filter(fn ($item) => is_array($item))
            ->map(function (array $item) use ($topic, $keywords, $quotedPhrases): ?array {
                $body = Str::lower(collect([
                    $item['publisher'] ?? null,
                    $item['headline'] ?? null,
                    $item['claim'] ?? null,
                    $item['claimant'] ?? null,
                    $item['rating'] ?? null,
                    $item['url'] ?? null,
                ])->filter()->implode(' '));

                $score = $this->calculateClaimMatchScore($body, $topic, $keywords, $quotedPhrases);

                if ($score < 3) {
                    return null;
                }

                $item['_match_score'] = $score;

                return $item;
            })
            ->filter()
            ->sortByDesc(fn (array $item): int => (int) ($item['_match_score'] ?? 0))
            ->take(2)
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchGNewsArticles(string $query, string $topic, string $claimText): array
    {
        $apiKey = trim((string) config('services.gnews.key'));

        if ($apiKey === '') {
            return [];
        }

        $params = [
            'q' => $query,
            'lang' => config('services.gnews.lang', 'en'),
            'max' => (int) config('services.gnews.max', 3),
            'apikey' => $apiKey,
            'from' => now()->subHours((int) config('services.gnews.lookback_hours', 168))->toIso8601String(),
        ];

        $country = trim((string) config('services.gnews.country'));

        if ($country !== '') {
            $params['country'] = $country;
        }

        $data = $this->requestJson(
            'https://gnews.io/api/v4/search',
            $params,
            (int) config('services.gnews.timeout', 12),
            'GNews API'
        );

        $articles = $data['articles'] ?? null;

        if (! is_array($articles)) {
            return [];
        }

        return $this->filterRelevantArticles($articles, $topic, $claimText, 'gnews');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchNewsApiArticles(string $query, string $topic, string $claimText): array
    {
        $apiKey = trim((string) config('services.newsapi.key'));

        if ($apiKey === '') {
            return [];
        }

        $data = $this->requestJson(
            'https://newsapi.org/v2/everything',
            [
                'q' => $query,
                'language' => config('services.newsapi.language', 'en'),
                'pageSize' => (int) config('services.newsapi.page_size', 3),
                'sortBy' => 'publishedAt',
                'from' => now()->subDays((int) config('services.newsapi.lookback_days', 7))->toIso8601String(),
                'apiKey' => $apiKey,
            ],
            (int) config('services.newsapi.timeout', 12),
            'NewsAPI'
        );

        $articles = $data['articles'] ?? null;

        if (! is_array($articles)) {
            return [];
        }

        return $this->filterRelevantArticles($articles, $topic, $claimText, 'newsapi');
    }

    /**
     * @param  array<int, array<string, mixed>>  $articles
     * @return array<int, array<string, mixed>>
     */
    private function filterRelevantArticles(array $articles, string $topic, string $claimText, string $provider): array
    {
        $keywords = $this->extractKeywords($claimText, 8);
        $quotedPhrases = $this->extractQuotedPhrases($claimText);

        if ($keywords === [] && $quotedPhrases === []) {
            return [];
        }

        return collect($articles)
            ->filter(fn ($article) => is_array($article))
            ->map(function (array $article) use ($topic, $keywords, $quotedPhrases, $provider): ?array {
                $title = trim((string) ($article['title'] ?? ''));
                $description = trim((string) ($article['description'] ?? ''));
                $body = Str::lower(preg_replace('/\s+/', ' ', $title.' '.$description.' '.(string) ($article['content'] ?? '')) ?? ($title.' '.$description.' '.(string) ($article['content'] ?? '')));

                if ($title === '' || ! $this->matchesTopic($body, $topic)) {
                    return null;
                }

                $keywordMatches = collect($keywords)
                    ->filter(fn (string $keyword) => Str::contains($body, Str::lower($keyword)))
                    ->count();

                $phraseMatches = collect($quotedPhrases)
                    ->filter(fn (string $phrase) => Str::contains($body, Str::lower($phrase)))
                    ->count();

                $score = $keywordMatches + ($phraseMatches * 3);

                if ($quotedPhrases !== [] && $phraseMatches === 0 && $keywordMatches < 2) {
                    return null;
                }

                if ($keywords !== [] && $keywordMatches === 0) {
                    return null;
                }

                $article['_provider'] = $provider;
                $article['_score'] = $score;

                return $article;
            })
            ->filter()
            ->sortByDesc(fn (array $article) => (int) ($article['_score'] ?? 0))
            ->take(2)
            ->values()
            ->all();
    }

    private function matchesTopic(string $body, string $topic): bool
    {
        return match ($topic) {
            'class_suspension' => Str::contains($body, ['sona', 'state of the nation', 'national address', 'walang pasok', 'class suspension', 'classes suspended', 'suspension of classes', 'no classes', 'malacañang', 'malacanang', 'marcos', 'official gazette', 'deped', 'proclamation']),
            'weather' => Str::contains($body, ['typhoon', 'storm', 'weather', 'rainfall', 'flood', 'pagasa', 'tropical', 'bagyo']),
            'disaster' => Str::contains($body, ['earthquake', 'eruption', 'volcano', 'ashfall', 'phivolcs', 'ndrrmc']),
            'health' => Str::contains($body, ['health', 'vaccine', 'virus', 'outbreak', 'hospital', 'doh', 'who']),
            'election' => Str::contains($body, ['election', 'vote', 'ballot', 'president', 'senator', 'comelec']),
            default => true,
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    private function fetchWeatherSnapshot(string $claimText, array $context): ?array
    {
        $apiKey = trim((string) config('services.openweather.key'));

        if ($apiKey === '') {
            return null;
        }

        $location = $this->resolveWeatherLocation($claimText, $context);

        if ($location === null) {
            return null;
        }

        $query = [
            'appid' => $apiKey,
            'units' => config('services.openweather.units', 'metric'),
            'lang' => config('services.openweather.lang', 'en'),
        ];

        if ($location['type'] === 'coords') {
            $query['lat'] = $location['lat'];
            $query['lon'] = $location['lon'];
        } else {
            $query['q'] = $location['query'];
        }

        $data = $this->requestJson(
            'https://api.openweathermap.org/data/2.5/weather',
            $query,
            (int) config('services.openweather.timeout', 12),
            'OpenWeather'
        );

        if (! is_array($data) || (int) ($data['cod'] ?? 200) !== 200) {
            return null;
        }

        $weather = $data['weather'][0] ?? [];
        $main = $data['main'] ?? [];
        $wind = $data['wind'] ?? [];
        $label = $location['label'];

        if ($location['type'] === 'coords') {
            $name = trim((string) ($data['name'] ?? ''));
            $country = trim((string) ($data['sys']['country'] ?? ''));

            if ($name !== '') {
                $label = $country !== '' ? "{$name}, {$country}" : $name;
            }
        }

        return [
            'label' => $label,
            'query' => $location['type'] === 'coords' ? ($label !== '' ? $label : 'Current location') : $location['query'],
            'description' => trim((string) ($weather['description'] ?? '')),
            'temperature' => isset($main['temp']) ? (float) $main['temp'] : null,
            'humidity' => isset($main['humidity']) ? (int) $main['humidity'] : null,
            'wind_speed' => isset($wind['speed']) ? (float) $wind['speed'] : null,
            'city_id' => isset($data['id']) ? (string) $data['id'] : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    private function fetchWeatherForecast(string $claimText, array $context): ?array
    {
        $apiKey = trim((string) config('services.openweather.key'));

        if ($apiKey === '') {
            return null;
        }

        $location = $this->resolveWeatherLocation($claimText, $context);

        if ($location === null) {
            return null;
        }

        $query = [
            'appid' => $apiKey,
            'units' => config('services.openweather.units', 'metric'),
            'lang' => config('services.openweather.lang', 'en'),
        ];

        if ($location['type'] === 'coords') {
            $query['lat'] = $location['lat'];
            $query['lon'] = $location['lon'];
        } else {
            $query['q'] = $location['query'];
        }

        $data = $this->requestJson(
            'https://api.openweathermap.org/data/2.5/forecast',
            $query,
            (int) config('services.openweather.timeout', 12),
            'OpenWeather forecast'
        );

        if (! is_array($data)) {
            return null;
        }

        $forecastItems = $data['list'] ?? null;

        if (! is_array($forecastItems)) {
            return null;
        }

        $days = collect($forecastItems)
            ->filter(fn ($item) => is_array($item) && filled($item['dt_txt'] ?? null))
            ->groupBy(fn (array $item): string => Carbon::parse((string) $item['dt_txt'])->format('Y-m-d'))
            ->map(function ($items, string $date): ?array {
                $items = collect($items)->filter(fn ($item) => is_array($item))->values();

                if ($items->isEmpty()) {
                    return null;
                }

                $picked = $items
                    ->sortBy(fn (array $item): int => abs(12 - (int) Carbon::parse((string) $item['dt_txt'])->format('H')))
                    ->first();

                if (! is_array($picked)) {
                    return null;
                }

                $main = $picked['main'] ?? [];
                $weather = $picked['weather'][0] ?? [];

                return [
                    'date' => Carbon::parse($date)->format('M d'),
                    'description' => trim((string) ($weather['description'] ?? '')),
                    'temp_min' => isset($main['temp_min']) ? (float) $main['temp_min'] : null,
                    'temp_max' => isset($main['temp_max']) ? (float) $main['temp_max'] : null,
                ];
            })
            ->filter()
            ->take(3)
            ->values()
            ->all();

        if ($days === []) {
            return null;
        }

        $label = $location['label'];

        if ($location['type'] === 'coords') {
            $city = $data['city'] ?? [];
            $name = is_array($city) ? trim((string) ($city['name'] ?? '')) : '';
            $country = is_array($city) ? trim((string) ($city['country'] ?? '')) : '';

            if ($name !== '') {
                $label = $country !== '' ? "{$name}, {$country}" : $name;
            }
        }

        return [
            'label' => $label,
            'query' => $location['type'] === 'coords' ? ($label !== '' ? $label : 'Current location') : $location['query'],
            'days' => $days,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{type: string, lat: float, lon: float, label: string}|array{type: string, query: string, label: string}|null
     */
    private function resolveWeatherLocation(string $claimText, array $context): ?array
    {
        $consent = filter_var($context['weather_consent'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($consent) {
            $lat = $this->normalizeCoordinate($context['weather_lat'] ?? null, -90, 90);
            $lon = $this->normalizeCoordinate($context['weather_lon'] ?? null, -180, 180);

            if ($lat !== null && $lon !== null) {
                $label = trim((string) ($context['weather_label'] ?? ''));

                if ($label === '') {
                    $label = 'Current location';
                }

                return [
                    'type' => 'coords',
                    'lat' => $lat,
                    'lon' => $lon,
                    'label' => $label,
                ];
            }
        }

        $location = $this->inferWeatherLocation($claimText);

        if ($location === null) {
            return null;
        }

        return [
            'type' => 'query',
            'query' => $location['query'],
            'label' => $location['label'],
        ];
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

    /**
     * @return array{query: string, label: string}|null
     */
    private function inferWeatherLocation(string $claimText): ?array
    {
        $claimText = Str::lower($claimText);

        foreach ([
            ['keywords' => ['cebu'], 'query' => 'Cebu City,PH', 'label' => 'Cebu City, Philippines'],
            ['keywords' => ['davao'], 'query' => 'Davao City,PH', 'label' => 'Davao City, Philippines'],
            ['keywords' => ['quezon city'], 'query' => 'Quezon City,PH', 'label' => 'Quezon City, Philippines'],
            ['keywords' => ['manila', 'metro manila'], 'query' => 'Manila,PH', 'label' => 'Manila, Philippines'],
        ] as $location) {
            if (Str::contains($claimText, $location['keywords'])) {
                return $location;
            }
        }

        return null;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function buildFactCheckSources(array $factChecks): array
    {
        return collect($factChecks)
            ->flatMap(function (array $claim): array {
                $claimText = trim((string) ($claim['text'] ?? ''));
                $reviews = $claim['claimReview'] ?? [];

                if (! is_array($reviews)) {
                    return [];
                }

                return collect($reviews)
                    ->filter(fn ($review) => is_array($review))
                    ->take(2)
                    ->map(function (array $review) use ($claimText): ?array {
                        $url = trim((string) ($review['url'] ?? ''));
                        $publisher = trim((string) ($review['publisher']['name'] ?? 'Fact-check source'));
                        $title = trim((string) ($review['title'] ?? ''));
                        $rating = $this->specificFactCheckRating(
                            trim((string) ($review['textualRating'] ?? 'Reviewed')),
                            $title,
                            (string) ($claim['text'] ?? ''),
                            $claimText,
                        );

                        if ($url === '') {
                            return null;
                        }

                        return [
                            'name' => $publisher,
                            'status' => 'Fact-check match',
                            'purpose' => $title !== '' ? $title : 'Open the public fact-check review tied to this claim.',
                            'url' => $url,
                            'summary' => $claimText !== ''
                                ? "Google Fact Check found a related claim review rated {$rating}."
                                : "Google Fact Check returned a public review rated {$rating}.",
                            'source_type' => 'fact_check',
                            'label' => 'Fact-check',
                            'rating' => $rating,
                            'published_at' => $this->formatDate($claim['claimDate'] ?? null),
                        ];
                    })
                    ->filter()
                    ->values()
                    ->all();
            })
            ->take(2)
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function buildPublisherFeedFactCheckSources(array $factChecks): array
    {
        return collect($factChecks)
            ->filter(fn ($item) => is_array($item) && filled($item['url'] ?? null))
            ->map(function (array $item): array {
                $publisher = trim((string) ($item['publisher'] ?? 'Fact-check partner'));
                $headline = trim((string) ($item['headline'] ?? 'Open the public fact-check review tied to this claim.'));
                $claim = trim((string) ($item['claim'] ?? ''));
                $rating = $this->specificFactCheckRating(trim((string) ($item['rating'] ?? 'Reviewed')), $headline, $claim);

                return [
                    'name' => $publisher,
                    'status' => 'Fact-check match',
                    'purpose' => $headline,
                    'url' => (string) $item['url'],
                    'summary' => $claim !== ''
                        ? "A matched fact-check source reviewed a matching claim: {$claim}"
                        : 'A matched fact-check source returned a related public review.',
                    'source_type' => 'fact_check',
                    'label' => 'Fact-check',
                    'rating' => $rating,
                    'published_at' => trim((string) ($item['date_label'] ?? '')),
                ];
            })
            ->take(2)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>|null  $weatherSnapshot
     * @param  array<string, mixed>|null  $weatherForecast
     * @return array<int, array<string, string>>
     */
    private function buildOfficialSources(array $context, string $topic, ?array $weatherSnapshot, ?array $weatherForecast): array
    {
        $sources = [];

        if ($topic === 'weather') {
            $sources[] = [
                'name' => 'PAGASA official advisories',
                'status' => 'Official source',
                'purpose' => 'Check PAGASA bulletins to confirm whether the claimed storm or rainfall advisory is real.',
                'url' => 'https://www.pagasa.dost.gov.ph',
                'summary' => 'Use the national weather bureau to verify if a typhoon entry, warning signal, or forecast already appears in official bulletins.',
                'source_type' => 'official',
                'label' => 'Official',
            ];

            if ($weatherSnapshot !== null) {
                $summary = collect([
                    filled($weatherSnapshot['description'] ?? null) ? ucfirst((string) $weatherSnapshot['description']) : null,
                    isset($weatherSnapshot['temperature']) ? number_format((float) $weatherSnapshot['temperature'], 1).' C' : null,
                    isset($weatherSnapshot['humidity']) ? 'Humidity '.(int) $weatherSnapshot['humidity'].'%' : null,
                    isset($weatherSnapshot['wind_speed']) ? 'Wind '.number_format((float) $weatherSnapshot['wind_speed'], 1).' m/s' : null,
                ])->filter()->implode(' | ');

                $weatherUrl = filled($weatherSnapshot['city_id'] ?? null)
                    ? 'https://openweathermap.org/city/'.rawurlencode((string) $weatherSnapshot['city_id'])
                    : 'https://openweathermap.org/find?q='.rawurlencode((string) ($weatherSnapshot['query'] ?? 'Manila'));

                $sources[] = [
                    'name' => 'OpenWeather live observation',
                    'status' => 'Weather observation',
                    'purpose' => 'Review a live weather snapshot that can help users compare a viral weather claim with actual conditions.',
                    'url' => $weatherUrl,
                    'summary' => trim(($weatherSnapshot['label'] ?? 'Observation reference').': '.$summary),
                    'source_type' => 'weather',
                    'label' => 'Live data',
                ];
            }
        }

        if ($topic === 'class_suspension') {
            $sources[] = [
                'name' => 'Official Gazette proclamations',
                'status' => 'Official government source',
                'purpose' => 'Check whether a nationwide holiday, class suspension, or government declaration was officially issued for the SONA date.',
                'url' => 'https://www.officialgazette.gov.ph/section/proclamations/',
                'summary' => 'Nationwide suspensions and non-working day declarations should be traceable to official proclamations or Palace releases.',
                'source_type' => 'official',
                'label' => 'Government',
            ];

            $sources[] = [
                'name' => 'Presidential Communications Office',
                'status' => 'Official government source',
                'purpose' => 'Review Palace and presidential communications for SONA-related announcements.',
                'url' => 'https://pco.gov.ph/',
                'summary' => 'Use PCO announcements to verify whether Malacanang made a nationwide SONA-related class suspension declaration.',
                'source_type' => 'official',
                'label' => 'Government',
            ];

            $sources[] = [
                'name' => 'Department of Education',
                'status' => 'Official government source',
                'purpose' => 'Check education advisories for national or local class suspension guidance.',
                'url' => 'https://www.deped.gov.ph/',
                'summary' => 'DepEd advisories help verify school-related suspension claims before sharing.',
                'source_type' => 'official',
                'label' => 'Government',
            ];
        }

        if ($topic === 'disaster') {
            $sources[] = [
                'name' => 'PHIVOLCS official advisories',
                'status' => 'Official source',
                'purpose' => 'Check official earthquake, volcano, and ashfall bulletins before sharing hazard claims.',
                'url' => 'https://www.phivolcs.dost.gov.ph',
                'summary' => 'Useful for validating whether the claimed seismic or volcanic event appears in the latest official bulletin.',
                'source_type' => 'official',
                'label' => 'Official',
            ];
        }

        if ($topic === 'health') {
            $sources[] = [
                'name' => 'Department of Health',
                'status' => 'Official source',
                'purpose' => 'Compare health-related claims against current public advisories.',
                'url' => 'https://doh.gov.ph',
                'summary' => 'Helpful for checking whether a viral health claim matches official local guidance.',
                'source_type' => 'official',
                'label' => 'Official',
            ];
        }

        if ($topic === 'election') {
            $sources[] = [
                'name' => 'COMELEC announcements',
                'status' => 'Official source',
                'purpose' => 'Review official election schedules, notices, and public advisories.',
                'url' => 'https://comelec.gov.ph',
                'summary' => 'Use the election commission website to verify dates, rules, and official announcements.',
                'source_type' => 'official',
                'label' => 'Official',
            ];
        }

        return $sources;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<int, array<string, string>>
     */
    private function buildSourceTraceCard(array $context): array
    {
        $platform = trim((string) ($context['platform'] ?? ''));
        $sourceUrl = trim((string) ($context['source_url'] ?? ''));

        if ($platform === '' || $sourceUrl === '' || ! Str::startsWith($sourceUrl, ['http://', 'https://'])) {
            return [];
        }

        return [[
            'name' => Str::headline($platform).' source context',
            'status' => 'Platform trace',
            'purpose' => 'Compare the uploaded claim with the original platform context, account history, and timestamp.',
            'url' => $sourceUrl,
            'summary' => 'Useful for checking whether the post has edits, replies, or newer clarifications on the original platform.',
            'source_type' => 'source_trace',
            'label' => 'Source',
        ]];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<int, array<string, string>>
     */
    private function buildCrossPlatformSocialSources(string $claimText, array $context): array
    {
        if (! (bool) config('playwright.social_context_enabled', true)) {
            return [];
        }

        $query = $this->buildSocialContextQuery($claimText, $context);

        if ($query === null) {
            return [];
        }

        $originPlatform = Str::lower(trim((string) ($context['platform'] ?? '')));
        $maxSources = max(1, (int) config('playwright.social_context_max_sources', 4));

        return collect((array) config('playwright.social_context_sources', []))
            ->filter(fn ($source): bool => is_array($source) && filled($source['url_template'] ?? null))
            ->reject(function (array $source) use ($originPlatform): bool {
                $candidatePlatform = Str::lower(trim((string) ($source['platform'] ?? '')));

                return $originPlatform !== ''
                    && $originPlatform !== 'web'
                    && $candidatePlatform !== ''
                    && $candidatePlatform === $originPlatform;
            })
            ->map(function (array $source) use ($query): ?array {
                $name = trim((string) ($source['name'] ?? 'Social media'));
                $template = trim((string) ($source['url_template'] ?? ''));

                if ($name === '' || $template === '') {
                    return null;
                }

                $url = str_replace('{query}', rawurlencode($query), $template);

                return [
                    'name' => "{$name} social context",
                    'status' => 'Cross-platform social search',
                    'purpose' => "Search public {$name} posts, videos, reposts, comments, and corrections for this claim before relying on the verdict.",
                    'url' => $url,
                    'summary' => "Use this {$name} search to see whether the same image, video, text, or link appears elsewhere with earlier context, corrections, or conflicting captions.",
                    'source_type' => 'social_context',
                    'label' => 'Social',
                ];
            })
            ->filter()
            ->take($maxSources)
            ->values()
            ->all();
    }

    private function buildSocialContextQuery(string $claimText, array $context): ?string
    {
        $sourceTerms = $this->extractSourceUrlTerms((string) ($context['source_url'] ?? ''));
        $submittedText = trim(collect([
            $context['caption_text'] ?? null,
            $context['notes'] ?? null,
            $context['openai_extracted_claim'] ?? null,
            $sourceTerms !== '' ? $sourceTerms : null,
        ])->filter()->implode(' '));

        if ($submittedText === '') {
            return null;
        }

        $quotedPhrases = $this->extractQuotedPhrases($submittedText);
        $keywords = $this->extractKeywords($submittedText, 8);
        $minimumTerms = max(1, (int) config('playwright.social_context_min_terms', 2));

        if ($quotedPhrases === [] && count($keywords) < $minimumTerms) {
            return null;
        }

        $terms = collect(array_merge(
            $quotedPhrases,
            $keywords,
        ))
            ->filter()
            ->unique()
            ->take(8)
            ->values();

        $query = $terms->isNotEmpty()
            ? $terms->implode(' ')
            : $submittedText;

        $query = trim(preg_replace('/\s+/', ' ', $query) ?? $query);

        if ($query === '') {
            return null;
        }

        return Str::limit($query, max(40, (int) config('playwright.social_context_query_length', 120)), '');
    }

    /**
     * @param  array<int, array<string, mixed>>  $articles
     * @return array<int, array<string, string>>
     */
    private function buildNewsSources(array $articles, string $provider): array
    {
        return collect($articles)
            ->map(function (array $article) use ($provider): ?array {
                $source = $article['source'] ?? [];
                $publisher = trim((string) ($source['name'] ?? $provider));
                $url = trim((string) ($article['url'] ?? ''));
                $title = trim((string) ($article['title'] ?? ''));
                $description = trim((string) ($article['description'] ?? ''));

                if ($url === '' || $title === '') {
                    return null;
                }

                return [
                    'name' => $publisher,
                    'status' => 'Related coverage',
                    'purpose' => $title,
                    'url' => $url,
                    'summary' => $description !== ''
                        ? $description
                        : 'Open this article to compare the viral claim with public reporting.',
                    'source_type' => 'news',
                    'label' => 'News',
                    'published_at' => $this->formatDate($article['publishedAt'] ?? null),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  mixed  $sources
     * @return array<int, array<string, string>>
     */
    private function normalizeLegacySources(mixed $sources): array
    {
        if (! is_array($sources)) {
            return [];
        }

        return collect($sources)
            ->filter(fn ($source) => is_array($source))
            ->map(function (array $source): ?array {
                $name = trim((string) ($source['name'] ?? ''));
                $url = trim((string) ($source['url'] ?? ''));
                $status = trim((string) ($source['status'] ?? 'Verification route'));
                $purpose = trim((string) ($source['purpose'] ?? 'Open this verification route.'));

                if ($name === '' || $url === '') {
                    return null;
                }

                return [
                    'name' => $name,
                    'status' => $status,
                    'purpose' => $purpose,
                    'url' => $url,
                    'summary' => trim((string) ($source['summary'] ?? '')),
                    'source_type' => trim((string) ($source['source_type'] ?? 'reference')),
                    'label' => trim((string) ($source['label'] ?? 'Reference')),
                    'rating' => trim((string) ($source['rating'] ?? '')),
                    'published_at' => trim((string) ($source['published_at'] ?? '')),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function buildManagedSourceRoutes(?string $query): array
    {
        $query = trim((string) $query);

        if ($query === '') {
            return [];
        }

        try {
            if (! Schema::hasTable('fact_check_sources')) {
                return [];
            }

            return FactCheckSource::query()
                ->enabled()
                ->orderBy('name')
                ->limit(8)
                ->get()
                ->map(function (FactCheckSource $source) use ($query): array {
                    $sourceType = $source->category ?: 'fact_check';
                    $label = $source->categoryLabel();

                    return [
                        'name' => $source->name,
                        'status' => 'Managed source',
                        'purpose' => "Search {$source->name} for related claims, evidence, or coverage.",
                        'url' => $source->searchUrl(Str::limit($query, 180, '')),
                        'summary' => trim((string) $source->notes) !== ''
                            ? trim((string) $source->notes)
                            : "Admin-added {$label} source.",
                        'source_type' => $sourceType,
                        'label' => $label,
                    ];
                })
                ->values()
                ->all();
        } catch (\Throwable $exception) {
            Log::debug('Managed fact-check source routes could not be loaded.', [
                'message' => Str::limit($exception->getMessage(), 180),
            ]);

            return [];
        }
    }

    private function specificFactCheckRating(string $rating, string ...$context): string
    {
        return FactCheckRatingNormalizer::normalize(trim($rating) !== '' ? trim($rating) : 'Reviewed', ...$context);
    }

    /**
     * @param  array<int, array<string, string>>  $sources
     * @return array<int, array<string, string>>
     */
    private function pruneIrrelevantSources(array $sources, string $claimText, string $topic): array
    {
        $keywords = $this->extractKeywords($claimText, 8);
        $quotedPhrases = $this->extractQuotedPhrases($claimText);

        return collect($sources)
            ->filter(function (array $source) use ($claimText, $keywords, $quotedPhrases, $topic): bool {
                $sourceType = (string) ($source['source_type'] ?? 'reference');

                if ($sourceType === 'fact_check') {
                    // A fact-check provider is not evidence by itself. Only keep a
                    // review when its title/summary actually overlaps the claim.
                    $body = Str::lower(trim(collect([
                        $source['name'] ?? null,
                        $source['purpose'] ?? null,
                        $source['summary'] ?? null,
                    ])->filter()->implode(' ')));

                    return $body !== ''
                        && ($quotedPhrases !== [] || $keywords !== [])
                        && $this->calculateClaimMatchScore($body, $topic, $keywords, $quotedPhrases) >= 1;
                }

                if (in_array($sourceType, ['official', 'weather', 'source_trace'], true)) {
                    return true;
                }

                if ($sourceType === 'social_context') {
                    // Search-result landing pages are discovery routes, not
                    // corroborating sources. Keep only an actual matched page
                    // returned by the web-search collector.
                    return in_array(strtolower((string) ($source['status'] ?? '')), [
                        'openai web-search match',
                        'scraped-social-match',
                    ], true) && filter_var($source['url'] ?? null, FILTER_VALIDATE_URL);
                }

                $url = Str::lower((string) ($source['url'] ?? ''));

                if ($sourceType === 'reference' && $this->isTrustedGenericReference($url)) {
                    return true;
                }

                if ($claimText === '' || ($keywords === [] && $quotedPhrases === [])) {
                    return false;
                }

                $haystack = Str::lower(trim(collect([
                    $source['name'] ?? null,
                    $source['purpose'] ?? null,
                    $source['summary'] ?? null,
                ])->filter()->implode(' ')));

                if ($haystack === '') {
                    return false;
                }

                return $this->calculateClaimMatchScore($haystack, $topic, $keywords, $quotedPhrases) >= 2;
            })
            ->values()
            ->all();
    }

    private function isTrustedGenericReference(string $url): bool
    {
        return Str::contains($url, [
            'toolbox.google.com/factcheck',
            'reuters.com/fact-check',
            'images.google.com',
            'officialgazette.gov.ph',
            'pco.gov.ph',
            'deped.gov.ph',
            'pagasa.dost.gov.ph',
            'openweathermap.org',
            'noaa.gov',
            'phivolcs.dost.gov.ph',
            'comelec.gov.ph',
            'doh.gov.ph',
        ]);
    }

    /**
     * @param  list<string>  $keywords
     * @param  list<string>  $quotedPhrases
     */
    private function calculateClaimMatchScore(string $body, string $topic, array $keywords, array $quotedPhrases): int
    {
        $score = $this->matchesTopic($body, $topic) ? 1 : 0;

        $score += collect($keywords)
            ->filter(fn (string $keyword) => Str::contains($body, Str::lower($keyword)))
            ->count();

        $score += collect($quotedPhrases)
            ->filter(fn (string $phrase) => Str::contains($body, Str::lower($phrase)))
            ->count() * 3;

        return $score;
    }

    /**
     * @param  array<int, array<string, string>>  $sources
     * @return array<int, array<string, string>>
     */
    private function deduplicateSources(array $sources): array
    {
        $deduplicated = collect($sources)
            ->unique(fn (array $source) => Str::lower(trim(($source['url'] ?? '').'|'.($source['name'] ?? ''))))
            ->sortBy(fn (array $source) => $this->sourcePriority((string) ($source['source_type'] ?? 'reference')))
            ->values();
        $socialSources = $deduplicated
            ->where('source_type', 'social_context')
            ->take(min(2, max(1, (int) config('playwright.social_context_max_sources', 4))))
            ->values();
        $regularLimit = max(0, 7 - $socialSources->count());

        return $deduplicated
            ->reject(fn (array $source): bool => ($source['source_type'] ?? null) === 'social_context')
            ->take($regularLimit)
            ->merge($socialSources)
            ->sortBy(fn (array $source) => $this->sourcePriority((string) ($source['source_type'] ?? 'reference')))
            ->values()
            ->all();
    }

    private function sourcePriority(string $sourceType): int
    {
        return match ($sourceType) {
            'fact_check' => 1,
            'news' => 2,
            'official', 'weather' => 3,
            'source_trace' => 3,
            'social_context' => 4,
            default => 5,
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $factChecks
     * @param  array<int, array<string, mixed>>  $gnewsArticles
     * @param  array<int, array<string, mixed>>  $newsApiArticles
     * @param  array<int, array<string, string>>  $verificationSources
     */
    private function buildVerificationSummary(
        array $factChecks,
        array $gnewsArticles,
        array $newsApiArticles,
        string $topic,
        bool $hasWeatherSnapshot,
        array $verificationSources,
        array $locationContext,
        string $fallback,
    ): string {
        $factCheckCount = count($this->buildFactCheckSources($factChecks));
        $newsCount = count($gnewsArticles) + count($newsApiArticles);
        $officialCount = collect($verificationSources)
            ->whereIn('source_type', ['official', 'weather', 'source_trace'])
            ->count();
        $socialCount = collect($verificationSources)
            ->where('source_type', 'social_context')
            ->count();

        if ($factCheckCount === 0 && $newsCount === 0 && $officialCount === 0 && $socialCount === 0) {
            $summary = $fallback !== '' ? $fallback : 'TruthGuard prepared verification routes for this case.';

            return $this->appendLocationSummary($summary, $locationContext);
        }

        $parts = [];

        if ($newsCount > 0) {
            $parts[] = "surfaced {$newsCount} related news source".($newsCount === 1 ? '' : 's');
        }

        if ($officialCount > 0) {
            $parts[] = "added {$officialCount} official or source-context route".($officialCount === 1 ? '' : 's');
        }

        if ($socialCount > 0) {
            $parts[] = "prepared {$socialCount} cross-platform social search".($socialCount === 1 ? '' : 'es');
        }

        if ($factCheckCount > 0) {
            $parts[] = "found {$factCheckCount} public fact-check match".($factCheckCount === 1 ? '' : 'es');
        } elseif ($newsCount === 0) {
            $parts[] = 'did not find an exact public fact-check match';
        }

        if ($topic === 'weather' && $hasWeatherSnapshot) {
            $parts[] = 'included a live weather observation reference';
        }

        $summary = 'TruthGuard '.implode(', ', $parts).'. Use these links to compare the claim against public evidence before sharing.';

        return $this->appendLocationSummary($summary, $locationContext);
    }

    /**
     * @param  array<string, mixed>  $signals
     * @param  array<int, array<string, mixed>>  $factChecks
     * @param  array<int, array<string, string>>  $verificationSources
     */
    private function buildExplanationSummary(
        string $verdict,
        int $fakeScore,
        array $signals,
        array $factChecks,
        array $verificationSources,
        string $fallback,
        string $topic,
        ?array $weatherForecast = null,
    ): string {
        $topSignal = collect($signals)
            ->filter(fn ($group) => is_array($group))
            ->flatten(1)
            ->filter(fn ($signal) => is_array($signal))
            ->sortByDesc(fn (array $signal) => (int) ($signal['weight'] ?? 0))
            ->first();

        $topSignalLabel = $topSignal['label'] ?? 'the available context still needs manual corroboration';
        $verdictLabel = match ($verdict) {
            'fake' => 'high risk',
            'review' => 'in need of review',
            default => 'lower risk',
        };

        $newsSources = collect($verificationSources)
            ->where('source_type', 'news')
            ->values();

        $officialNames = collect($verificationSources)
            ->whereIn('source_type', ['official', 'weather'])
            ->pluck('name')
            ->take(2)
            ->implode(' and ');

        $newsNames = $newsSources
            ->pluck('name')
            ->unique()
            ->take(2)
            ->implode(' and ');
        $socialNames = collect($verificationSources)
            ->where('source_type', 'social_context')
            ->pluck('name')
            ->unique()
            ->take(2)
            ->implode(' and ');

        if ($topic === 'weather' && is_array($weatherForecast) && ($weatherForecast['days'] ?? []) !== []) {
            $forecastSummary = collect($weatherForecast['days'])
                ->filter(fn ($day) => is_array($day))
                ->take(3)
                ->map(function (array $day): string {
                    $temperatureBand = collect([
                        isset($day['temp_min']) ? number_format((float) $day['temp_min'], 0).' C' : null,
                        isset($day['temp_max']) ? number_format((float) $day['temp_max'], 0).' C' : null,
                    ])->filter()->implode(' to ');

                    return trim(collect([
                        $day['date'] ?? null,
                        $day['description'] ?? null,
                        $temperatureBand !== '' ? $temperatureBand : null,
                    ])->filter()->implode(', '));
                })
                ->filter()
                ->implode('; ');

            if ($forecastSummary !== '') {
                $sourceLine = $newsNames !== '' ? " and recent reporting from {$newsNames}" : '';

                return "According to PAGASA guidance, forecast references for ".($weatherForecast['label'] ?? 'the monitored area')."{$sourceLine}, the upcoming weather outlook is {$forecastSummary}. TruthGuard marked this case as {$verdictLabel} at {$fakeScore}% because {$topSignalLabel}.";
            }
        }

        if ($officialNames !== '') {
            if ($newsNames !== '') {
                $coverageSummary = $newsSources
                    ->pluck('purpose')
                    ->filter()
                    ->take(2)
                    ->implode(' and ');

                if ($coverageSummary !== '') {
                    return "According to {$officialNames} and related reporting from {$newsNames}, current coverage highlights {$coverageSummary}. TruthGuard marked this case as {$verdictLabel} at {$fakeScore}% because {$topSignalLabel}.";
                }

                return "According to {$officialNames} and related reporting from {$newsNames}, users have public sources to compare with this claim. TruthGuard marked this case as {$verdictLabel} at {$fakeScore}% because {$topSignalLabel}.";
            }

            return "According to {$officialNames}, this topic should be checked against official advisories before sharing. TruthGuard marked this case as {$verdictLabel} at {$fakeScore}% because {$topSignalLabel}.";
        }

        if ($newsNames !== '') {
            $coverageSummary = $newsSources
                ->pluck('purpose')
                ->filter()
                ->take(2)
                ->implode(' and ');
            $socialLine = $socialNames !== ''
                ? " Cross-platform checks from {$socialNames} can add repost, comment, or correction context, but they are not treated as proof by themselves."
                : '';

            if ($coverageSummary !== '') {
                return "According to related reporting from {$newsNames}, recent coverage points to {$coverageSummary}. TruthGuard marked this case as {$verdictLabel} at {$fakeScore}% because {$topSignalLabel}.{$socialLine}";
            }

            return "According to related reporting from {$newsNames}, there is public coverage users can review alongside this submission. TruthGuard marked this case as {$verdictLabel} at {$fakeScore}% because {$topSignalLabel}.{$socialLine}";
        }

        $factCheckSource = collect($verificationSources)->firstWhere('source_type', 'fact_check');

        if (is_array($factCheckSource)) {
            $rating = trim((string) ($factCheckSource['rating'] ?? 'reviewed'));
            $socialLine = $socialNames !== ''
                ? " Cross-platform searches from {$socialNames} were also prepared for repost, comment, or correction context."
                : '';

            return "Based on the source, a related public fact-check was rated {$rating}. TruthGuard marks this case as {$verdictLabel} at {$fakeScore}% because that source rating is compared with the strongest internal signal: {$topSignalLabel}.{$socialLine}";
        }

        if ($socialNames !== '') {
            return "TruthGuard prepared cross-platform context checks from {$socialNames} before presenting this verdict. These searches help users look for earlier posts, reposts, comments, and corrections, while the case remains {$verdictLabel} at {$fakeScore}% because {$topSignalLabel}.";
        }

        if ($topic === 'weather' && $fallback !== '') {
            return $fallback.' Users should still compare the post with the linked weather and newsroom references.';
        }

        return $fallback !== ''
            ? $fallback
            : "TruthGuard marked this case as {$verdictLabel} at {$fakeScore}% because {$topSignalLabel}.";
    }

    /**
     * @param  array<int, array<string, mixed>>  $factChecks
     * @param  array<int, array<string, string>>  $verificationSources
     */
    private function buildRecommendation(
        string $verdict,
        array $factChecks,
        array $verificationSources,
        string $topic,
        string $fallback,
    ): string {
        $newsSource = collect($verificationSources)->firstWhere('source_type', 'news');
        $officialSource = collect($verificationSources)->firstWhere('source_type', 'official');
        $weatherSource = collect($verificationSources)->firstWhere('source_type', 'weather');
        $socialSource = collect($verificationSources)->firstWhere('source_type', 'social_context');

        if ($topic === 'weather' && (is_array($officialSource) || is_array($weatherSource) || is_array($newsSource))) {
            return 'Before sharing this weather-related post, compare it with the linked PAGASA references, live forecast data, and recent reporting so users can see whether the claimed storm, rainfall, or advisory matches current conditions.';
        }

        if ($topic === 'class_suspension' && is_array($officialSource)) {
            return $verdict === 'fake'
                ? 'Do not share this as true. Compare the matched fact-check source with Official Gazette, PCO, and DepEd announcement channels; a nationwide class suspension should have an official government record.'
                : 'Before sharing this class-suspension claim, compare it with Official Gazette, PCO, and DepEd announcement channels so the date and scope match an official record.';
        }

        if (is_array($newsSource) && is_array($officialSource)) {
            return 'Open the related news coverage first, compare it with the linked official source, and only reshare if both point to the same context as the uploaded claim.';
        }

        if (is_array($newsSource)) {
            return is_array($socialSource)
                ? 'Read the linked public coverage before sharing this post again, then compare the article details with cross-platform social context for reposts, corrections, comments, or conflicting captions.'
                : 'Read the linked public coverage before sharing this post again, then compare the article details with the uploaded image, video, or source link.';
        }

        $factCheckSource = collect($verificationSources)->firstWhere('source_type', 'fact_check');

        if (is_array($factCheckSource)) {
            if ($verdict === 'fake') {
                $rating = trim((string) ($factCheckSource['rating'] ?? 'false or misleading'));

                return is_array($socialSource)
                    ? "Do not share this as true. Open the linked fact-check source rated {$rating}, compare it with the uploaded claim, then use the social context searches to find reposts, comments, corrections, or conflicting captions before correcting the post."
                    : "Do not share this as true. Open the linked fact-check source rated {$rating}, compare it with the uploaded claim, and use that source when correcting the post.";
            }

            return is_array($socialSource)
                ? 'Open the linked fact-check review as a supporting source, then compare it with the uploaded claim, linked reporting, and cross-platform social context before resharing.'
                : 'Open the linked fact-check review as a supporting source, then compare it with the uploaded claim and any linked reporting before resharing.';
        }

        if ($topic === 'health') {
            return 'Compare the claim with current Department of Health or WHO advisories first, then review the linked public reporting before reposting it.';
        }

        if ($topic === 'election') {
            return 'Check the COMELEC announcement linked in this result and compare it with the original post before reposting election-related claims.';
        }

        $sourceTrace = collect($verificationSources)->firstWhere('source_type', 'source_trace');

        if (is_array($sourceTrace)) {
            return 'Open the original submitted source and compare its timestamp, caption, comments, and linked articles before treating the claim as verified.';
        }

        if (is_array($socialSource)) {
            return 'Open the cross-platform social searches and compare where else the uploaded image, video, text, or link appears, especially earlier posts, comments, corrections, and conflicting captions, before relying on the verdict.';
        }

        return $fallback !== ''
            ? $fallback
            : match ($verdict) {
                'fake' => 'Do not reshare this content until the linked evidence has been reviewed manually.',
                'review' => 'Hold this case for manual review and compare it with the linked public sources first.',
                default => 'Keep a quick context check against the linked sources before public reuse.',
            };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function requestJson(string $url, array $query, int $timeout, string $serviceName): ?array
    {
        try {
            $response = Http::acceptJson()
                ->connectTimeout(min(2, max(1, $timeout)))
                ->timeout($timeout)
                ->get($url, $query);

            if (! $response->successful()) {
                Log::warning("{$serviceName} request failed during detection enrichment.", [
                    'status' => $response->status(),
                    'url' => $url,
                ]);

                return null;
            }

            $data = $response->json();

            return is_array($data) ? $data : null;
        } catch (\Throwable $exception) {
            Log::warning("{$serviceName} request threw during detection enrichment.", [
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function formatDate(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            return '';
        }

        try {
            return Carbon::parse($value)->format('M d, Y');
        } catch (\Throwable) {
            return '';
        }
    }
}
