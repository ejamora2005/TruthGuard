<?php

namespace App\Services\Detections\Evidence;

use App\Services\Detections\FactCheckEvidencePolicy;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class SafeArticleFetcher
{
    public function fetch(string $url, RetrievalBudget $budget, bool $checkRobots = true): array
    {
        $base = ['url' => $url, 'retrieved_at' => now()->utc()->toIso8601String(), 'text' => '', 'published_at' => null, 'updated_at' => null, 'links' => [], 'http_status' => null];
        if (! $budget->take('pages')) {
            return $base + ['status' => 'budget_exhausted'];
        }
        try {
            $cacheKey = 'evidence-page:v2:'.hash('sha256', $url);
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
                $host = parse_url($url, PHP_URL_HOST);
                $port = parse_url($url, PHP_URL_SCHEME) === 'https' ? 443 : 80;
                $maxBytes = max(1024, (int) config('fact_check.retrieval.max_bytes', 2097152));
                $response = Http::withHeaders(['User-Agent' => 'TruthGuardEvidence/1.0', 'Accept' => 'text/html,text/plain,application/pdf', 'Accept-Encoding' => 'identity'])
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
                    ])->get($url);
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
                $type = strtolower(explode(';', $response->header('Content-Type'))[0]);
                if (Str::contains(strtolower($response->header('X-Robots-Tag')), ['nosnippet', 'noarchive'])) {
                    return $base + ['status' => 'restricted'];
                }
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
        } catch (\Throwable) {
            // Never log request exceptions: URLs can contain credentials or user content.
            return $base + ['status' => 'fetch_failed'];
        }
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
        $robots = Cache::get($cacheKey);
        if (! is_array($robots)) {
            $robots = $this->fetch($origin.'/robots.txt', $budget, false);
            Cache::put($cacheKey, $robots, 300);
        }
        if (in_array($robots['status'], ['http_404', 'http_410'], true)) {
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
        foreach ($xpath->query('//script[@type="application/ld+json"]') as $node) {
            $json = json_decode($node->textContent, true);
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
        if (Str::contains(strtolower($meta('robots')), ['nosnippet', 'noarchive'])) {
            return ['text' => '', 'title' => $title, 'published_at' => $published, 'updated_at' => $updated, 'links' => []];
        }
        foreach ($xpath->query('//script|//style|//nav|//header[not(ancestor::article) and not(ancestor::main)]|//footer|//aside|//form|//*[@hidden or @data-nosnippet]') as $node) {
            $node->parentNode?->removeChild($node);
        }
        // A union is in document order: <main> enclosing <article> wins,
        // leaking navigation and unrelated recommendations into paid reviews.
        $root = $xpath->query('//article')->item(0) ?? $xpath->query('//main')->item(0) ?? $xpath->query('//body')->item(0);
        $links = [];
        foreach ($xpath->query('.//a[@href]', $root) as $node) {
            try {
                $link = (string) UriResolver::resolve(new Uri($url), new Uri($node->getAttribute('href')));
            } catch (\Throwable) {
                continue;
            }
            if (preg_match('/\.(?:gov\.ph|gov|gov\.uk)$/i', (string) parse_url($link, PHP_URL_HOST))) {
                $links[] = $link;
            }
        }
        $text = trim(preg_replace('/\s+/u', ' ', $root?->textContent ?? '') ?? '');
        if (preg_match('/(?:verify you are human|checking your browser|enable javascript and cookies|subscribe to (?:continue|read))/i', $text)) {
            $text = '';
        }

        return ['text' => $text, 'title' => $title, 'published_at' => $published, 'updated_at' => $updated, 'links' => array_slice(array_values(array_unique($links)), 0, 6)];
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
