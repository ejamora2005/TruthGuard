<?php

namespace App\Services\Detections\Evidence;

use App\Services\Detections\FactCheckEvidencePolicy;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ArticleSearch
{
    public function __construct(
        private readonly SafeArticleFetcher $fetcher,
        private readonly FactCheckEvidencePolicy $policy,
        private readonly OpenAiWebSearch $webSearch,
    ) {}

    public function retrieve(array $queries, RetrievalBudget $budget, string $cutoff, array $records = [], bool $reserveNextRound = false, ?int $candidateCeiling = null): array
    {
        $budget->historicalCutoff ??= Carbon::parse($cutoff)->lt(now()->subMinute());
        if (! config('services.live_verification.enabled', true)) {
            $budget->providers['discovery'] = 'disabled';

            return $records;
        }
        $limit = max(0, (int) config('fact_check.retrieval.max_candidates', 40));
        $limit = $candidateCeiling === null ? $limit : min($limit, max(0, $candidateCeiling));
        $searchCeiling = max(0, (int) config('fact_check.retrieval.max_search_requests', 12));
        $overallSearchCeiling = $searchCeiling;
        $pageCeiling = max(0, (int) config('fact_check.retrieval.max_pages', 12));
        if ($reserveNextRound) {
            $searchCeiling = $budget->searches + (int) ceil(max(0, $searchCeiling - $budget->searches) / 2);
            $pageCeiling = $budget->pages + (int) ceil(max(0, $pageCeiling - $budget->pages) / 2);
            // Keep a supplementary provider available in the first round even
            // when the total budget is only two searches and web search fails.
            if (config('fact_check.web_search.enabled', true) && filled(config('services.openai.key'))
                && collect(['google_fact_check', 'gnews', 'newsapi'])->contains(fn ($provider) => filled(config('services.'.$provider.'.key')))) {
                $searchCeiling = min($overallSearchCeiling, max($searchCeiling, $budget->searches + 2));
            }
        }
        if ($queries !== [] && count($records) < $limit && $budget->searches < $searchCeiling) {
            $web = $this->webSearch->discover($queries, $budget, $cutoff);
            $status = $web['status'];
            if ($status !== 'skipped' || ! isset($budget->providers['openai_web_search'])) {
                $budget->providers['openai_web_search'] = $status;
            }
            if (! in_array($status, ['disabled', 'not_configured', 'skipped', 'time_budget_reserved', 'budget_exhausted'], true)) {
                $items = $web['candidates'];
                $budget->found += count($items);
                $rejected = ['invalid_url' => 0, 'duplicate_url' => 0, 'candidate_limit' => 0];
                $added = 0;
                foreach ($items as $item) {
                    $key = $this->canonicalUrl($item['url']);
                    if ($key === null) {
                        $rejected['invalid_url']++;

                        continue;
                    }
                    if (isset($records[$key])) {
                        $rejected['duplicate_url']++;
                        $records[$key]['discovered_by'] = array_values(array_unique([...($records[$key]['discovered_by'] ?? [$records[$key]['provider']]), 'openai_web_search']));
                        $records[$key]['web_search_citation'] = ($records[$key]['web_search_citation'] ?? false) || $item['origin'] === 'citation';
                        if (blank($records[$key]['title'] ?? null) && filled($item['title'])) {
                            $records[$key]['title'] = $item['title'];
                        }

                        continue;
                    }
                    if (count($records) >= $limit) {
                        $rejected['candidate_limit']++;

                        continue;
                    }
                    $records[$key] = ['id' => 's'.substr(hash('sha256', $key), 0, 16), 'url' => $item['url'],
                        'title' => $item['title'], 'name' => (string) parse_url($item['url'], PHP_URL_HOST),
                        'source_type' => $this->sourceType($item['url']), 'published_at' => null,
                        'provider' => 'openai_web_search', 'discovered_by' => ['openai_web_search'],
                        'web_search_citation' => $item['origin'] === 'citation', 'retrieval_status' => 'discovered'];
                    $added++;
                }
                $meta = $web['metadata'];
                $budget->attempts[] = ['provider' => 'openai_web_search', 'query' => implode(' ; ', array_slice($queries, 0, 2)),
                    'page' => 1, 'status' => $status, 'http_status' => $meta['http_status'],
                    'content_type' => $meta['content_type'], 'cache_hit' => false,
                    'raw_results' => count($items), 'provider_total' => count($meta['consulted_sources']),
                    'response_fields' => $meta['response_fields'], 'sanitized_error' => $meta['sanitized_error'],
                    'availability' => null, 'results' => count($items), 'unique_added' => $added, 'rejected' => $rejected,
                    'web_search' => $meta];
            }
        }
        // Read the most relevant web-search documents while the retrieval window
        // still has time. A long sequence of API discovery calls must not leave
        // all returned URLs unfetched.
        if ($records !== []) {
            $this->fetchCandidates($records, $budget, $cutoff, $pageCeiling, 2, $limit);
        }
        foreach ($queries as $index => $query) {
            foreach (['google_fact_check', 'gnews', 'newsapi'] as $provider) {
                if (count($records) >= $limit || $budget->remaining() < 8 || $budget->searches >= $searchCeiling) {
                    break 2;
                }
                if (blank(config('services.'.$provider.'.key'))) {
                    $budget->providers[$provider] = 'not_configured';

                    continue;
                }
                $page = 1;
                $token = null;
                do {
                    $data = $this->search($provider, $query, $index, $page, $token, $cutoff, $budget);
                    $items = $this->candidates($provider, $data ?? []);
                    $budget->found += count($items);
                    $rejected = ['invalid_url' => 0, 'duplicate_url' => 0, 'candidate_limit' => 0];
                    $added = 0;
                    foreach ($items as $item) {
                        $key = $this->canonicalUrl($item['url']);
                        if ($key === null) {
                            $rejected['invalid_url']++;

                            continue;
                        }
                        if (isset($records[$key])) {
                            $rejected['duplicate_url']++;
                            $records[$key]['discovered_by'] = array_values(array_unique([...($records[$key]['discovered_by'] ?? [$records[$key]['provider']]), $provider]));

                            continue;
                        }
                        if (count($records) >= $limit) {
                            $rejected['candidate_limit']++;

                            continue;
                        }
                        $records[$key] = $item + ['id' => 's'.substr(hash('sha256', $key), 0, 16), 'retrieval_status' => 'discovered', 'provider' => $provider, 'discovered_by' => [$provider]];
                        $added++;
                    }
                    $budget->attempts[] = ['provider' => $provider, 'query' => $query, 'page' => $page,
                        'status' => $budget->providers[$provider] ?? 'budget_exhausted',
                        'http_status' => $budget->lastSearch['http_status'] ?? null,
                        'content_type' => $budget->lastSearch['content_type'] ?? null,
                        'cache_hit' => $budget->lastSearch['cache_hit'] ?? false,
                        'raw_results' => $budget->lastSearch['raw_results'] ?? 0,
                        'provider_total' => $budget->lastSearch['provider_total'] ?? null,
                        'response_fields' => $budget->lastSearch['response_fields'] ?? null,
                        'sanitized_error' => $budget->lastSearch['sanitized_error'] ?? null,
                        'availability' => $budget->lastSearch['availability'] ?? null,
                        'results' => count($items), 'unique_added' => $added, 'rejected' => $rejected];
                    $token = $data['nextPageToken'] ?? null;
                    $page++;
                    // Only Google pagination is used; news subscription limits stay unchanged.
                } while ($provider === 'google_fact_check' && $token && $page <= min(3, max(1, (int) config('fact_check.retrieval.google_pages', 2))) && count($records) < $limit && $budget->searches < $searchCeiling);
            }
        }
        uasort($records, function ($a, $b) use ($queries) {
            $rank = function ($r) use ($queries) {
                $overlap = count(array_intersect($this->tokens(implode(' ', $queries)), $this->tokens($r['title'])));

                return $overlap * 20 + ($overlap > 0 && $r['source_type'] === 'official_government' ? 10 : 0);
            };

            return $rank($b) <=> $rank($a);
        });
        $this->fetchCandidates($records, $budget, $cutoff, $pageCeiling, null, $limit);

        return $this->deduplicate($records);
    }

    private function fetchCandidates(array &$records, RetrievalBudget $budget, string $cutoff, int $pageCeiling, ?int $maxDocuments = null, ?int $candidateCeiling = null): void
    {
        $limit = max(0, (int) config('fact_check.retrieval.max_candidates', 40));
        $limit = $candidateCeiling === null ? $limit : min($limit, max(0, $candidateCeiling));
        $pending = array_keys($records);
        $attempted = 0;
        foreach ($pending as $key) {
            if ($budget->pages >= $pageCeiling || $budget->remaining() < 2 || ($maxDocuments !== null && $attempted >= $maxDocuments)) {
                break;
            }
            if ($records[$key]['retrieval_status'] !== 'discovered') {
                continue;
            }
            if ($this->isGenericLandingPage($records[$key]['url'])) {
                $records[$key]['retrieval_status'] = 'reference_only';
                $budget->pageOutcomes[] = ['source_id' => $records[$key]['id'], 'status' => 'reference_only'];

                continue;
            }
            $page = $this->fetcher->fetch($records[$key]['url'], $budget);
            $attempted++;
            $records[$key] = $this->mergePage($records[$key], $page, $cutoff, $budget->historicalCutoff);
            $budget->pageOutcomes[] = ['source_id' => $records[$key]['id'], 'status' => $records[$key]['retrieval_status'],
                'http_status' => $page['http_status'] ?? null, 'robots_detail' => $page['robots_detail'] ?? null,
                'cache_hit' => (bool) ($page['cached'] ?? false)];
            // Follow original public records linked by reviewed reporting, within the SAME budget.
            $followed = 0;
            foreach ($page['links'] ?? [] as $link) {
                // Leave room to read independent reporting instead of exhausting
                // the page budget on every government link in the first article.
                if ($followed >= 1) {
                    break;
                }
                $linkedKey = $this->canonicalUrl($link);
                if (! $linkedKey || $this->isGenericLandingPage($link) || isset($records[$linkedKey]) || count($records) >= $limit || $budget->remaining() < 1 || $budget->pages >= $pageCeiling) {
                    continue;
                }
                $primary = ['id' => 's'.substr(hash('sha256', $linkedKey), 0, 16), 'url' => $link, 'title' => $link,
                    'name' => (string) parse_url($link, PHP_URL_HOST), 'source_type' => 'official_government', 'provider' => 'linked_primary_record', 'retrieval_status' => 'discovered', 'published_at' => null];
                $linkedPage = $this->fetcher->fetch($link, $budget);
                $records[$linkedKey] = $this->mergePage($primary, $linkedPage, $cutoff, $budget->historicalCutoff);
                $followed++;
                $budget->pageOutcomes[] = ['source_id' => $records[$linkedKey]['id'], 'status' => $records[$linkedKey]['retrieval_status'],
                    'http_status' => $linkedPage['http_status'] ?? null, 'robots_detail' => $linkedPage['robots_detail'] ?? null,
                    'cache_hit' => (bool) ($linkedPage['cached'] ?? false)];
            }
        }

    }

    private function search(string $provider, string $query, int $index, int $page, ?string $token, string $cutoff, RetrievalBudget $budget): ?array
    {
        $budget->lastSearch = ['http_status' => null, 'content_type' => null, 'cache_hit' => false,
            'raw_results' => 0, 'provider_total' => null, 'response_fields' => null,
            'sanitized_error' => null, 'availability' => null];
        if (! $budget->take('searches')) {
            $budget->providers[$provider] = 'budget_exhausted';

            return null;
        }
        $key = (string) config('services.'.$provider.'.key');
        $date = Carbon::parse($cutoff)->utc();
        [$searchQuery, $officialDomain] = $this->searchTerms($query);
        if ($provider === 'google_fact_check') {
            $languages = (array) config('fact_check.retrieval.google_languages', ['en', 'fil']);
            $params = ['query' => $searchQuery, 'languageCode' => $languages[$index % max(1, count($languages))] ?? 'en',
                'pageSize' => max(1, min(100, (int) config('services.google_fact_check.page_size', 3))), 'key' => $key];
            if ($token) {
                $params['pageToken'] = $token;
            }
            if (config('fact_check.retrieval.google_max_age_days', 0) > 0 && $date->gte(now()->subMinute())) {
                $params['maxAgeDays'] = (int) config('fact_check.retrieval.google_max_age_days');
            }
            $url = 'https://factchecktools.googleapis.com/v1alpha1/claims:search';
        } elseif ($provider === 'gnews') {
            $params = ['q' => $this->gnewsQuery($searchQuery), 'lang' => config('services.gnews.lang', 'en'), 'max' => max(1, min(100, (int) config('services.gnews.max', 3))),
                'apikey' => $key, 'to' => $date->toIso8601String(), 'sortby' => 'relevance'];
            if (filled(config('services.gnews.country'))) {
                $params['country'] = config('services.gnews.country');
            }
            $url = 'https://gnews.io/api/v4/search';
        } else {
            $params = ['q' => $searchQuery, 'language' => config('services.newsapi.language', 'en'), 'pageSize' => max(1, min(100, (int) config('services.newsapi.page_size', 3))),
                'to' => $date->toIso8601String(), 'sortBy' => 'relevancy'];
            if ($officialDomain) {
                // NewsAPI documents a domains filter; neither other provider
                // supports searching the general web within an official host.
                $params['domains'] = $officialDomain;
            }
            $url = 'https://newsapi.org/v2/everything';
        }
        // Provider-account identity is hashed; credentials never enter cache values or diagnostics.
        $cacheKey = 'evidence-search:v2:'.hash('sha256', $provider.$key.json_encode(array_diff_key($params, ['key' => 1, 'apikey' => 1])));
        if (! $budget->freshSources && is_array($data = Cache::get($cacheKey)) && $this->validResponse($provider, $data)) {
            $availability = $this->availability($provider, $data);
            $budget->providers[$provider] = $this->providerStatus($provider, $data, true);
            $budget->lastSearch = ['http_status' => null, 'cache_hit' => true, 'raw_results' => $this->rawCount($provider, $data),
                'provider_total' => $this->providerTotal($provider, $data), 'response_fields' => array_keys($data),
                'availability' => $availability];

            return $data;
        }
        if (Cache::has('evidence-rate-limit:'.hash('sha256', $provider.$key))) {
            $budget->providers[$provider] = 'rate_limited';

            return null;
        }
        try {
            $budget->providerRequests++;
            $response = Http::acceptJson()->withHeaders($provider === 'newsapi' ? ['X-Api-Key' => $key] : [])
                ->connectTimeout(min(2, max(1, $budget->remaining())))
                ->timeout(min(max(1, $budget->remaining()), max(1, (int) config('services.'.$provider.'.timeout', 3))))
                ->withOptions(['allow_redirects' => false, 'on_headers' => function ($r) {
                    if ((int) $r->getHeaderLine('Content-Length') > 2097152) {
                        throw new \RuntimeException('Provider response too large');
                    }
                }, 'progress' => function ($total, $downloaded) {
                    if ($downloaded > 2097152) {
                        throw new \RuntimeException('Provider response too large');
                    }
                }])
                ->get($url, $params);
            if ($response->status() === 429) {
                $retry = $response->header('Retry-After');
                $seconds = is_numeric($retry) ? (int) $retry : 60;
                Cache::put('evidence-rate-limit:'.hash('sha256', $provider.$key), true, max(1, min(3600, $seconds)));
            }
            $budget->lastSearch['http_status'] = $response->status();
            $budget->lastSearch['content_type'] = $response->header('Content-Type');
            $budget->providers[$provider] = match (true) {
                $provider === 'gnews' && $response->status() === 403 => 'quota_or_subscription_limited',
                $provider === 'gnews' && $response->status() === 400 => 'invalid_request',
                in_array($response->status(), [401, 403], true) => 'authentication_failed',
                $response->status() === 429 => 'rate_limited',
                $response->successful() => 'ok',
                default => 'http_'.$response->status(),
            };
            $data = $response->json();
            if (is_array($data)) {
                $budget->lastSearch['response_fields'] = array_keys($data);
                $budget->lastSearch['sanitized_error'] = $this->safeError($data, $key);
            }
            if (! $response->successful() || ! is_array($data) || strlen($response->body()) > 2097152) {
                if ($response->successful()) {
                    $budget->providers[$provider] = 'invalid_response';
                }

                return null;
            }
            if (isset($data['error']) || isset($data['errors']) || ($data['status'] ?? '') === 'error') {
                $budget->providers[$provider] = 'provider_error';

                return null;
            }
            if (! $this->validResponse($provider, $data)) {
                $budget->providers[$provider] = 'invalid_response';

                return null;
            }
            $budget->lastSearch['raw_results'] = $this->rawCount($provider, $data);
            $budget->lastSearch['provider_total'] = $this->providerTotal($provider, $data);
            $budget->lastSearch['availability'] = $this->availability($provider, $data);
            $budget->providers[$provider] = $this->providerStatus($provider, $data);
            Cache::put($cacheKey, $data, max(1, (int) config('fact_check.retrieval.cache_seconds', 300)));

            return $data;
        } catch (ConnectionException $error) {
            $budget->providers[$provider] = preg_match('/timed? out|timeout|cURL error 28/i', $error->getMessage()) ? 'timeout' : 'network_failure';

            return null;
        } catch (\Throwable) {
            $budget->providers[$provider] = 'request_failed';

            return null;
        }
    }

    private function searchTerms(string $query): array
    {
        if (preg_match('/\s+site:([a-z0-9.-]+)\s*$/i', $query, $match)
            && $this->sourceType('https://'.$match[1].'/record') === 'official_government') {
            return [trim(substr($query, 0, -strlen($match[0]))), strtolower($match[1])];
        }

        return [$query, null];
    }

    private function validResponse(string $provider, array $data): bool
    {
        if ($provider === 'google_fact_check') {
            if (! isset($data['claims'])) {
                return $data === [] || (array_keys($data) === ['nextPageToken'] && is_string($data['nextPageToken']));
            }
            if (! is_array($data['claims']) || (isset($data['nextPageToken']) && ! is_string($data['nextPageToken']))) {
                return false;
            }
            foreach ($data['claims'] as $claim) {
                if (! is_array($claim) || (isset($claim['claimReview']) && ! is_array($claim['claimReview']))) {
                    return false;
                }
            }

            return true;
        }

        if (! isset($data['articles']) || ! is_array($data['articles'])) {
            return false;
        }
        foreach ($data['articles'] as $article) {
            if (! is_array($article)) {
                return false;
            }
        }

        $total = $this->providerTotal($provider, $data);

        // GNews can remove an entire result page under its account's archive
        // limits while totalArticles still counts matching records.
        return ($provider === 'gnews' || ($data['status'] ?? null) === 'ok') && $total !== null;
    }

    private function providerStatus(string $provider, array $data, bool $cached = false): string
    {
        if ($provider === 'gnews' && $data['articles'] === []
            && ($this->availability($provider, $data)['historical_articles_removed'] ?? 0) > 0) {
            return 'archive_limited';
        }

        return $cached ? 'cached' : 'ok';
    }

    private function availability(string $provider, array $data): ?array
    {
        if ($provider !== 'gnews') {
            return null;
        }
        $historicalMessage = data_get($data, 'articlesRemovedFromResponse.historicalArticles.message');
        $realTimeMessage = data_get($data, 'information.realTimeArticles.message');
        $removed = is_string($historicalMessage) && preg_match('/\b(\d+) articles? (?:was|were) removed\b/i', $historicalMessage, $matches)
            ? (int) $matches[1] : null;
        $delayHours = is_string($realTimeMessage) && preg_match('/\b(\d+)[- ]hour delay\b/i', $realTimeMessage, $matches)
            ? (int) $matches[1] : null;

        return ['historical_articles_removed' => $removed, 'realtime_delay_hours' => $delayHours];
    }

    private function safeError(array $data, string $key): ?string
    {
        $error = $data['errors'] ?? $data['error'] ?? $data['message'] ?? null;
        if ($error === null) {
            return null;
        }
        $message = is_scalar($error) ? (string) $error : json_encode($error, JSON_UNESCAPED_SLASHES);
        $message = strip_tags(str_replace($key, '[redacted]', $message));
        $message = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[email]', $message);
        $message = preg_replace('/\b(apikey|api[_-]?key|authorization|token)\s*[:=]\s*[^\s,&]+/i', '$1=[redacted]', $message);

        return mb_substr($message, 0, 240);
    }

    private function rawCount(string $provider, array $data): int
    {
        return count($provider === 'google_fact_check' ? ($data['claims'] ?? []) : ($data['articles'] ?? []));
    }

    private function providerTotal(string $provider, array $data): ?int
    {
        $total = $provider === 'gnews' ? ($data['totalArticles'] ?? null) : ($provider === 'newsapi' ? ($data['totalResults'] ?? null) : null);

        return is_numeric($total) && (int) $total >= 0 ? (int) $total : null;
    }

    public function gnewsQuery(string $query): string
    {
        // GNews rejects unquoted punctuation. Quote each literal term/phrase and
        // bound whole tokens so a long headline cannot leave an open quote.
        preg_match_all('/"[^"]*"|[^\s]+/u', $query, $matches);
        $parts = [];
        foreach ($matches[0] as $part) {
            $part = str_replace(['"', '\\'], '', $part);
            if (! preg_match('/[\pL\pN]/u', $part)) {
                continue;
            }
            $quoted = '"'.mb_substr($part, 0, 180).'"';
            if (mb_strlen(implode(' ', [...$parts, $quoted])) > 200) {
                break;
            }
            $parts[] = $quoted;
        }

        return implode(' ', $parts);
    }

    private function candidates(string $provider, array $data): array
    {
        $items = [];
        if ($provider === 'google_fact_check') {
            foreach ($data['claims'] ?? [] as $claim) {
                foreach ($claim['claimReview'] ?? [] as $review) {
                    if (! is_array($review) || ! is_string($review['url'] ?? null)) {
                        continue;
                    }
                    $items[] = ['url' => $review['url'], 'title' => $review['title'] ?? '', 'name' => $review['publisher']['name'] ?? parse_url($review['url'], PHP_URL_HOST),
                        'published_at' => $this->policy->date($review['reviewDate'] ?? null), 'source_type' => 'fact_check', 'reviewed_claim' => $claim['text'] ?? '',
                        'review_rating' => $review['textualRating'] ?? '', 'claim_date' => $this->policy->date($claim['claimDate'] ?? null)];
                }
            }
        } else {
            foreach ($data['articles'] ?? [] as $article) {
                if (! is_array($article) || ! is_string($article['url'] ?? null)) {
                    continue;
                }
                $items[] = ['url' => $article['url'], 'title' => $article['title'] ?? '', 'name' => $article['source']['name'] ?? parse_url($article['url'], PHP_URL_HOST),
                    'published_at' => $this->policy->date($article['publishedAt'] ?? null), 'source_type' => $this->sourceType($article['url'], 'reputable_news'), 'snippet' => $article['description'] ?? ''];
            }
        }

        return $items;
    }

    public function sourceType(string $url, ?string $preferred = null): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($preferred === 'reputable_news') {
            foreach (config('fact_check.publishers.reputable_news', []) as $domain) {
                if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                    return 'reputable_news';
                }
            }
        }
        foreach (config('fact_check.publishers', []) as $type => $domains) {
            foreach ($domains as $domain) {
                if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                    return $type;
                }
            }
        }

        return 'unapproved';
    }

    public function reviewedSubmission(array $page, string $cutoff): array
    {
        $key = $this->canonicalUrl($page['url']);

        return $this->mergePage(['id' => 's'.substr(hash('sha256', $key ?? $page['url']), 0, 16), 'url' => $page['url'],
            'title' => $page['title'] ?? $page['url'], 'name' => (string) parse_url($page['url'], PHP_URL_HOST),
            'source_type' => $this->sourceType($page['url']), 'provider' => 'submitted_link', 'published_at' => $page['published_at']], $page, $cutoff);
    }

    private function mergePage(array $record, array $page, string $cutoff, ?bool $historical = null): array
    {
        $record['retrieval_status'] = $page['status'];
        $record['retrieval_http_status'] = $page['http_status'] ?? null;
        $record['robots_detail'] = $page['robots_detail'] ?? null;
        $record['retrieved_at'] = $page['retrieved_at'];
        $record['updated_at'] = $page['updated_at'];
        $record['published_at'] = $page['published_at'] ?? $record['published_at'];
        $record['final_url'] = $page['url'];
        $resolvedType = $this->sourceType($page['url'], in_array($record['provider'] ?? null, ['gnews', 'newsapi'], true) ? 'reputable_news' : null);
        $record['source_type'] = in_array($resolvedType, ['official_government', 'institution', 'research'], true)
            ? $resolvedType : ($record['source_type'] === 'fact_check' && $resolvedType !== 'unapproved' ? 'fact_check' : $resolvedType);
        $record['text'] = $page['text'];
        $record['content_hash'] = $page['content_hash'] ?? null;
        $record['text_truncated'] = $page['text_truncated'] ?? false;
        $record['content_type'] = $page['content_type'] ?? null;
        if (filled($page['title'] ?? null)) {
            $record['title'] = $page['title'];
        }
        $historical ??= Carbon::parse($cutoff)->lt(now()->subMinute());
        if (($record['published_at'] && Carbon::parse($record['published_at'])->gt($cutoff)) || ($record['updated_at'] && Carbon::parse($record['updated_at'])->gt($cutoff))) {
            $record['retrieval_status'] = 'after_cutoff';
        } elseif ($historical && ! $record['published_at']) {
            $record['retrieval_status'] = 'undated_historical';
        }

        return $record;
    }

    public function deduplicate(array $records): array
    {
        $seen = [];
        foreach ($records as $key => $record) {
            if (($record['retrieval_status'] ?? '') !== 'retrieved') {
                continue;
            }
            $tokens = $this->tokens($record['text']);
            foreach ($seen as $other) {
                $union = count(array_unique(array_merge($tokens, $other['tokens'])));
                if ($record['content_hash'] && $record['content_hash'] === $other['hash']) {
                    $records[$key]['retrieval_status'] = 'duplicate';
                    $records[$key]['duplicate_of'] = $other['id'];

                    continue 2;
                }
                // A near-copy can contain a crucial changed number or negation.
                // Flag shared provenance without discarding potentially conflicting text.
                if ($union > 0 && count($tokens) >= 30 && count(array_intersect($tokens, $other['tokens'])) / $union >= 0.9) {
                    $records[$key]['possible_copy_of'] = $other['id'];
                }
            }
            $seen[] = ['id' => $record['id'], 'hash' => $record['content_hash'], 'tokens' => $tokens];
        }

        return $records;
    }

    public function canonicalUrl(string $url): ?string
    {
        if (! filter_var($url, FILTER_VALIDATE_URL) || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true) || parse_url($url, PHP_URL_USER) !== null) {
            return null;
        }
        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $query);
        foreach (array_keys($query) as $key) {
            if (str_starts_with($key, 'utm_') || in_array($key, ['fbclid', 'gclid'], true)) {
                unset($query[$key]);
            }
        }
        ksort($query);

        return strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '').rtrim($parts['path'] ?? '/', '/').($query ? '?'.http_build_query($query) : '');
    }

    public function isGenericLandingPage(string $url): bool
    {
        $path = strtolower(trim((string) parse_url($url, PHP_URL_PATH), '/'));

        return in_array($path, ['', 'index', 'index.html', 'index.php', 'home', 'home.php', 'about', 'about-us', 'news'], true);
    }

    private function tokens(string $text): array
    {
        return array_values(array_unique(preg_split('/[^\pL\pN]+/u', Str::lower($text), -1, PREG_SPLIT_NO_EMPTY) ?: []));
    }
}
