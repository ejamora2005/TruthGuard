<?php

namespace App\Services\Detections\Evidence;

use App\Services\Detections\FactCheckEvidencePolicy;
use App\Services\Detections\ReportAnswer;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class SafeArticleFetcher
{
    private array $prefetchedResponses = [];

    private array $reservedPages = [];

    /** Concurrent transport, followed by the same validated parsing as fetch(). */
    public function fetchMany(array $urls, RetrievalBudget $budget, ?int $pageCeiling = null): array
    {
        $urls = array_values(array_unique($urls));
        if (count($urls) < 2 || (int) config('fact_check.retrieval.fetch_concurrency', 4) < 2) {
            return array_combine($urls, array_map(fn ($url) => $this->fetch($url, $budget), $urls));
        }
        $pageCeiling = min($pageCeiling ?? PHP_INT_MAX, ResearchLimits::ceiling('fact_check.retrieval.max_pages', 50));
        $prepared = $robots = $results = [];
        try {
            foreach ($urls as $url) {
                if (isset($budget->fetchedPages['page:'.$url])
                    || (! $budget->freshSources && Cache::has('evidence-page:v6:'.hash('sha256', $url)))) {
                    $results[$url] = $this->fetch($url, $budget);

                    continue;
                }
                $ip = $this->publicAddress($url);
                if ($ip === null) {
                    $results[$url] = $this->fetch($url, $budget);

                    continue;
                }
                $origin = parse_url($url, PHP_URL_SCHEME).'://'.parse_url($url, PHP_URL_HOST);
                $robotsUrl = $origin.'/robots.txt';
                $needsRobots = config('fact_check.retrieval.respect_robots', true)
                    && ! isset($budget->fetchedPages['robots:'.$robotsUrl]) && ! isset($robots[$robotsUrl])
                    && ($budget->freshSources || ! Cache::has('evidence-robots:v1:'.hash('sha256', $origin)));
                if ($budget->pages + ($needsRobots ? 2 : 1) > $pageCeiling || $budget->remaining() < 1) {
                    $results[$url] = ['url' => $url, 'status' => 'budget_exhausted', 'http_status' => null];

                    continue;
                }
                if (! $budget->take('pages')) {
                    continue;
                }
                $this->reservedPages[$url] = true;
                $prepared[$url] = $ip;
                if ($needsRobots && $budget->take('pages')) {
                    $this->reservedPages[$robotsUrl] = true;
                    $robots[$robotsUrl] = $ip;
                }
            }
            $this->prefetch($robots, $budget);
            $allowed = [];
            foreach ($prepared as $url => $ip) {
                $reason = null;
                if (! config('fact_check.retrieval.respect_robots', true) || $this->robotsAllow($url, $budget, $reason)) {
                    $allowed[$url] = $ip;
                }
            }
            $this->prefetch($allowed, $budget);
            foreach ($prepared as $url => $ip) {
                $results[$url] = $this->fetch($url, $budget);
            }

            return $results;
        } finally {
            $this->prefetchedResponses = $this->reservedPages = [];
        }
    }

    private function prefetch(array $addresses, RetrievalBudget $budget): void
    {
        if ($addresses === []) {
            return;
        }
        $concurrency = max(1, min(4, (int) config('fact_check.retrieval.fetch_concurrency', 4)));
        $responses = Http::pool(function (Pool $pool) use ($addresses, $budget) {
            foreach ($addresses as $url => $ip) {
                $this->request($url, $ip, $budget, $pool->as($url))->get($url);
            }
        }, $concurrency);
        $this->prefetchedResponses = array_replace($this->prefetchedResponses, $responses);
        $budget->parallelFetchBatches++;
        $budget->peakFetchConcurrency = max($budget->peakFetchConcurrency, min($concurrency, count($addresses)));
    }

    public function fetch(string $url, RetrievalBudget $budget, bool $checkRobots = true): array
    {
        $key = ($checkRobots ? 'page:' : 'robots:').$url;
        if (isset($budget->fetchedPages[$key])) {
            return array_replace($budget->fetchedPages[$key], ['cached' => true]);
        }

        return $budget->fetchedPages[$key] = $this->fetchOnce($url, $budget, $checkRobots);
    }

    private function fetchOnce(string $url, RetrievalBudget $budget, bool $checkRobots): array
    {
        $base = ['url' => $url, 'retrieved_at' => now()->utc()->toIso8601String(), 'text' => '', 'published_at' => null, 'updated_at' => null, 'links' => [], 'http_status' => null];
        app(ReportApiBudget::class)->assertActive();
        $reserved = isset($this->reservedPages[$url]);
        unset($this->reservedPages[$url]);
        if (! $reserved && ! $budget->take('pages')) {
            return $base + ['status' => 'budget_exhausted'];
        }
        try {
            $cacheKey = 'evidence-page:v6:'.hash('sha256', $url);
            if (! $budget->freshSources && ($cached = Cache::get($cacheKey))) {
                return $cached + ['cached' => true];
            }
            for ($redirects = 0; $redirects <= 3; $redirects++) {
                $ip = $this->publicAddress($url);
                if ($ip === null) {
                    return $base + ['status' => 'blocked_url'];
                }
                $robotsReason = null;
                if ($checkRobots && config('fact_check.retrieval.respect_robots', true) && ! $this->robotsAllow($url, $budget, $robotsReason)) {
                    return $base + ['status' => 'robots_restricted_or_unavailable', 'robots_detail' => $robotsReason];
                }
                $maxBytes = max(1024, (int) config('fact_check.retrieval.max_bytes', 2097152));
                $response = $this->response($url, $ip, $budget);
                $base['http_status'] = $response->status();
                if (in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                    $location = $response->header('Location');
                    if (! $location || $budget->remaining() < 1) {
                        break;
                    }
                    $url = (string) UriResolver::resolve(new Uri($url), new Uri($location));

                    continue;
                }
                if (! $response->successful()) {
                    return $base + ['status' => 'http_'.$response->status()];
                }
                $body = $response->body();
                if (strlen($body) > $maxBytes) {
                    return $base + ['status' => 'too_large'];
                }
                // Some origins/CDNs send compression despite Accept-Encoding:
                // identity. Decode explicitly within the same byte ceiling;
                // compressed bytes must never reach the HTML/PDF parser.
                $encoding = strtolower(trim($response->header('Content-Encoding')));
                if ($encoding !== '' && $encoding !== 'identity') {
                    if (! in_array($encoding, ['gzip', 'x-gzip', 'deflate'], true)) {
                        return $base + ['status' => 'unsupported_content_encoding'];
                    }
                    $decoded = @zlib_decode($body, $maxBytes + 1);
                    if ($decoded === false) {
                        return $base + ['status' => 'invalid_or_oversized_content_encoding'];
                    }
                    if (strlen($decoded) > $maxBytes) {
                        return $base + ['status' => 'too_large'];
                    }
                    $body = $decoded;
                }
                $type = strtolower(explode(';', $response->header('Content-Type'))[0]);
                // Search-preview directives are not access controls. Read the
                // public response after the separate robots.txt access check.
                if ($type === 'application/pdf') {
                    $content = ['text' => $this->pdfText($body), 'title' => '', 'published_at' => null, 'updated_at' => null, 'links' => []];
                } elseif (in_array($type, ['text/html', 'application/xhtml+xml'], true)) {
                    $content = $this->html($body, $url);
                } elseif ($type === 'text/plain') {
                    $content = ['text' => trim($body), 'title' => '', 'published_at' => null, 'updated_at' => null, 'links' => []];
                } else {
                    return $base + ['status' => 'unsupported_content_type'];
                }
                if (mb_strlen($content['text']) < 80) {
                    return array_replace($base, $content, ['status' => 'missing_full_text']);
                }
                $limit = max(1000, (int) config('fact_check.retrieval.max_text_chars', 18000));
                $content['text_truncated'] = mb_strlen($content['text']) > $limit;
                $content['content_hash'] = hash('sha256', Str::lower(Str::squish($content['text'])));
                $content['text'] = mb_substr($content['text'], 0, $limit);
                $record = array_replace($base, $content, ['url' => $url, 'status' => 'retrieved', 'content_type' => $type]);
                Cache::put($cacheKey, $record, max(1, (int) config('fact_check.retrieval.cache_seconds', 300)));

                return $record;
            }

            return $base + ['status' => 'redirect_limit'];
        } catch (ConnectionException $error) {
            return $base + ['status' => preg_match('/timed? out|timeout|cURL error 28/i', $error->getMessage()) ? 'timeout' : 'network_failure'];
        } catch (\Throwable $error) {
            // Never log request exceptions: URLs can contain credentials or user content.
            $oversized = str_contains($error->getMessage(), 'Response too large')
                || str_contains($error->getPrevious()?->getMessage() ?? '', 'Response too large');

            return $base + ['status' => $oversized ? 'too_large' : 'fetch_failed'];
        }
    }

    private function request(string $url, string $ip, RetrievalBudget $budget, ?PendingRequest $pending = null): PendingRequest
    {
        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_SCHEME) === 'https' ? 443 : 80;
        $maxBytes = max(1024, (int) config('fact_check.retrieval.max_bytes', 2097152));

        return ($pending ?? Http::createPendingRequest())->withHeaders(['User-Agent' => 'TruthGuardEvidence/1.0', 'Accept' => 'text/html,text/plain,application/pdf', 'Accept-Encoding' => 'identity'])
            ->beforeSending(fn () => app(ReportApiBudget::class)->assertActive())
            ->connectTimeout(min(2, max(1, $budget->remaining())))
            ->timeout(min(max(1, $budget->remaining()), max(1, (int) config('fact_check.retrieval.timeout', 5))))
            ->withOptions([
                'allow_redirects' => false, 'proxy' => '', 'decode_content' => false,
                // Pin the validated address: redirects are resolved and checked afresh.
                'curl' => [CURLOPT_RESOLVE => [$host.':'.$port.':'.(str_contains($ip, ':') ? '['.$ip.']' : $ip)], CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS],
                'on_headers' => function ($response) use ($maxBytes) {
                    if ((int) $response->getHeaderLine('Content-Length') > $maxBytes) {
                        throw new \RuntimeException('Response too large');
                    }
                },
                'progress' => function ($total, $downloaded) use ($maxBytes) {
                    if ($downloaded > $maxBytes) {
                        throw new \RuntimeException('Response too large');
                    }
                },
            ])
            // One bounded retry reduces transient failures without
            // repeatedly processing an inaccessible user link.
            ->retry(2, 150, fn ($exception): bool => $exception instanceof ConnectionException
                || ($exception instanceof RequestException
                    && (in_array($exception->response->status(), [408, 429], true) || $exception->response->serverError())), false);
    }

    private function response(string $url, string $ip, RetrievalBudget $budget): Response
    {
        if (array_key_exists($url, $this->prefetchedResponses)) {
            $response = $this->prefetchedResponses[$url];
            unset($this->prefetchedResponses[$url]);
            if ($response instanceof \Throwable) {
                throw $response;
            }

            return $response;
        }

        return $this->request($url, $ip, $budget)->get($url);
    }

    public function publicAddress(string $url): ?string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || preg_match('/[\x00-\x20\\\\]/', $url)) {
            return null;
        }
        $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
        if (! in_array($port, [80, 443], true)) {
            return null;
        }
        $host = strtolower(trim($parts['host'] ?? '', '[]'));
        if ($host === '' || ! str_contains($host, '.') && ! str_contains($host, ':')) {
            return null;
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->resolve($host);
        if ($ips === []) {
            return null;
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
                || str_starts_with(strtolower($ip), '::ffff:') || str_starts_with($ip, '169.254.')
                || str_starts_with($ip, '100.64.') || preg_match('/^100\.(?:6[4-9]|[7-9]\d|1[01]\d|12[0-7])\./', $ip)) {
                return null;
            }
        }

        return $ips[0];
    }

    private function robotsAllow(string $url, RetrievalBudget $budget, ?string &$reason = null): bool
    {
        $origin = parse_url($url, PHP_URL_SCHEME).'://'.parse_url($url, PHP_URL_HOST);
        $cacheKey = 'evidence-robots:v1:'.hash('sha256', $origin);
        $robots = $budget->freshSources ? null : Cache::get($cacheKey);
        if (! is_array($robots)) {
            $robots = $this->fetch($origin.'/robots.txt', $budget, false);
            Cache::put($cacheKey, $robots, 300);
        }
        // RFC 9309: a 4xx robots response means the rules are unavailable,
        // not that the article is forbidden. Preserve rate-limit backoff.
        if (preg_match('/^http_4\d{2}$/', $robots['status']) && $robots['status'] !== 'http_429') {
            return true;
        }
        if (! in_array($robots['status'], ['retrieved', 'missing_full_text'], true)) {
            $reason = 'robots_fetch_'.$robots['status'];

            return false;
        }

        $permitted = $this->robotsPermit($robots['text'], (string) (parse_url($url, PHP_URL_PATH) ?: '/').(parse_url($url, PHP_URL_QUERY) ? '?'.parse_url($url, PHP_URL_QUERY) : ''));
        if (! $permitted) {
            $reason = 'robots_disallow';
        }

        return $permitted;
    }

    public function robotsPermit(string $text, string $path): bool
    {
        $groups = [];
        $agents = [];
        $rules = [];
        foreach (explode("\n", $text."\nUser-agent: __end__") as $line) {
            $line = trim(explode('#', $line, 2)[0]);
            if (! str_contains($line, ':')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode(':', $line, 2));
            $key = strtolower($key);
            if ($key === 'user-agent') {
                if ($rules !== []) {
                    $groups[] = [$agents, $rules];
                    $agents = [];
                    $rules = [];
                }
                $agents[] = strtolower($value);
            } elseif (in_array($key, ['allow', 'disallow'], true) && $agents !== [] && $value !== '') {
                $rules[] = [$key, $value];
            }
        }
        $specific = array_filter($groups, fn ($group) => in_array('truthguardevidence', $group[0], true));
        $selected = $specific ?: array_filter($groups, fn ($group) => in_array('*', $group[0], true));
        $length = -1;
        $allow = true;
        foreach ($selected as [, $rules]) {
            foreach ($rules as [$kind, $pattern]) {
                $expression = str_replace('\\*', '.*', preg_quote($pattern, '~'));
                if (str_ends_with($pattern, '$')) {
                    $expression = substr($expression, 0, -2).'$';
                }
                if (preg_match('~^'.$expression.'~', $path) && (strlen($pattern) > $length || strlen($pattern) === $length && $kind === 'allow')) {
                    $length = strlen($pattern);
                    $allow = $kind === 'allow';
                }
            }
        }

        return $allow;
    }

    protected function resolve(string $host): array
    {
        return array_values(array_filter(array_map(fn ($r) => $r['ip'] ?? $r['ipv6'] ?? null, @dns_get_record($host, DNS_A | DNS_AAAA) ?: [])));
    }

    public function pdfText(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tg-evidence-');
        if ($path === false) {
            return '';
        }
        try {
            file_put_contents($path, $bytes);
            $process = new Process([(string) config('fact_check.pdf_binary', 'pdftotext'), '-f', '1', '-l', '30', '-layout', $path, '-']);
            $process->setTimeout(5)->run();

            return $process->isSuccessful() ? trim($process->getOutput()) : '';
        } catch (\Throwable) {
            return '';
        } finally {
            @unlink($path);
        }
    }

    public function html(string $body, string $url): array
    {
        $doc = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $doc->loadHTML('<?xml encoding="UTF-8">'.$body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new \DOMXPath($doc);
        $meta = fn ($name) => trim($xpath->evaluate('string(//meta[@property="'.$name.'" or @name="'.$name.'"]/@content)'));
        $title = $meta('og:title') ?: trim($xpath->evaluate('string(//title)'));
        $policy = app(FactCheckEvidencePolicy::class);
        $published = $policy->date($meta('article:published_time') ?: $meta('datePublished') ?: $meta('date'));
        $updated = $policy->date($meta('article:modified_time') ?: $meta('dateModified'));
        $claimReviews = [];
        foreach ($xpath->query('//script[@type="application/ld+json"]') as $node) {
            $json = json_decode($node->textContent, true);
            foreach ($this->jsonClaimReviews(is_array($json) ? $json : []) as $review) {
                $reviewUrl = $review['url'] ?? $url;
                if (! is_string($reviewUrl) || rtrim(explode('?', $reviewUrl)[0], '/') !== rtrim(explode('?', $url)[0], '/')) {
                    continue;
                }
                $claim = $review['claimReviewed'] ?? null;
                $rating = $review['reviewRating']['alternateName'] ?? $review['reviewRating']['name'] ?? null;
                if (is_string($claim) && trim($claim) !== '' && is_string($rating) && trim($rating) !== '') {
                    $claimReviews[] = ['reviewed_claim' => trim($claim), 'review_rating' => trim($rating)];
                    $published ??= $policy->date($review['datePublished'] ?? null);
                }
            }
            foreach ($this->jsonArticles(is_array($json) ? $json : []) as $article) {
                if (($article['isAccessibleForFree'] ?? true) === false || ($article['isAccessibleForFree'] ?? '') === 'False') {
                    return ['text' => '', 'title' => $title, 'published_at' => $published, 'updated_at' => $updated, 'links' => []];
                }
                $published ??= $policy->date($article['datePublished'] ?? null);
                $updated ??= $policy->date($article['dateModified'] ?? null);
            }
        }
        // A document's own <time datetime> is an issuance/publication signal;
        // retrieval time remains a separate field on the fetched record.
        $published ??= $policy->date(trim($xpath->evaluate('string((//article//time[@datetime] | //main//time[@datetime])[1]/@datetime)')));
        foreach ($xpath->query('//script|//style|//nav|//header[not(ancestor::article) and not(ancestor::main)]|//footer|//aside|//form|//*[@hidden]') as $node) {
            $node->parentNode?->removeChild($node);
        }
        // A union is in document order: <main> enclosing <article> wins,
        // leaking navigation and unrelated recommendations into paid reviews.
        // Page builders can put the main post outside <article>, while related
        // cards use that tag. Prefer an explicitly marked full article body.
        $root = $xpath->query('//*[@itemprop="articleBody"]')->item(0)
            ?? $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " elementor-widget-theme-post-content ")]')->item(0)
            ?? $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " entry-content ")]')->item(0)
            ?? $xpath->query('//article')->item(0) ?? $xpath->query('//main')->item(0) ?? $xpath->query('//body')->item(0);
        $links = [];
        foreach ($xpath->query('.//a[@href]', $root) as $node) {
            try {
                $link = (string) UriResolver::resolve(new Uri($url), new Uri($node->getAttribute('href')));
            } catch (\Throwable) {
                continue;
            }
            if (ReportAnswer::safeUrl($link) !== null && $link !== $url
                && ($policy->isPrimarySourceUrl($link) || parse_url($link, PHP_URL_HOST) !== parse_url($url, PHP_URL_HOST))) {
                $links[$link] = $policy->isPrimarySourceUrl($link) ? 0 : 1;
            }
        }
        asort($links, SORT_NUMERIC);
        // DOM textContent does not insert spaces between adjacent table cells
        // or block elements. Preserve boundaries for readable, citable evidence.
        foreach ($xpath->query('.//th|.//td|.//tr|.//p|.//li|.//h1|.//h2|.//h3|.//br', $root) as $node) {
            $node->parentNode?->insertBefore($doc->createTextNode(' '), $node);
            $node->appendChild($doc->createTextNode(' '));
        }
        $text = trim(preg_replace('/\s+/u', ' ', $root?->textContent ?? '') ?? '');
        if (preg_match('/(?:verify you are human|checking your browser|enable javascript and cookies|subscribe to (?:continue|read))/i', $text)) {
            $text = '';
        }

        return ['text' => $text, 'title' => $title, 'published_at' => $published, 'updated_at' => $updated,
            'claim_reviews' => array_values(array_unique($claimReviews, SORT_REGULAR)),
            'links' => array_slice(array_keys($links), 0, 6)];
    }

    private function jsonClaimReviews(array $data): array
    {
        $rows = in_array('ClaimReview', (array) ($data['@type'] ?? []), true) ? [$data] : [];
        foreach ($data as $child) {
            if (is_array($child)) {
                $rows = array_merge($rows, $this->jsonClaimReviews($child));
            }
        }

        return $rows;
    }

    private function jsonArticles(array $data): array
    {
        $rows = [];
        if (array_intersect((array) ($data['@type'] ?? []), ['NewsArticle', 'Article', 'Report', 'ScholarlyArticle'])) {
            $rows[] = $data;
        }
        foreach ($data as $child) {
            if (is_array($child)) {
                $rows = array_merge($rows, $this->jsonArticles($child));
            }
        }

        return $rows;
    }
}
