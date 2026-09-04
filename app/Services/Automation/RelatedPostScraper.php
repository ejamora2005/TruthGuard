<?php

namespace App\Services\Automation;

use App\Models\Detection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class RelatedPostScraper
{
    public function __construct(private readonly ScrapeOrchestrator $scrapeOrchestrator)
    {
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function scrapeForDetection(Detection $detection, array $context = []): void
    {
        if (! config('playwright.related_posts_enabled')) {
            return;
        }

        if ($this->hasRecentRelatedPosts($detection)) {
            return;
        }

        $query = $this->buildQuery($detection, $context);

        if ($query === null) {
            return;
        }

        $template = trim((string) config('playwright.related_posts_template'));

        if ($template === '') {
            return;
        }

        $targetUrl = str_replace('{query}', rawurlencode($query), $template);

        try {
            $this->scrapeOrchestrator->run([
                'target_url' => $targetUrl,
                'source_key' => config('playwright.related_posts_source', config('playwright.default_source')),
                'post_limit' => (int) config('playwright.related_posts_limit', 6),
                'take_screenshot' => false,
                'analyze_posts' => false,
                'trigger_source' => 'related-posts',
                'associate_detection_id' => $detection->id,
            ], $detection->user);
        } catch (Throwable $throwable) {
            Log::channel((string) config('playwright.log_channel', 'playwright'))->warning('Related post scraping failed', [
                'detection_id' => $detection->id,
                'error' => $throwable->getMessage(),
            ]);
        }
    }

    private function hasRecentRelatedPosts(Detection $detection): bool
    {
        $ttlHours = (int) config('playwright.related_posts_ttl_hours', 6);

        return $detection->scrapedPosts()
            ->whereHas('scrapeRun', fn ($query) => $query->where('trigger_source', 'related-posts'))
            ->where('scraped_at', '>=', now()->subHours($ttlHours))
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function buildQuery(Detection $detection, array $context): ?string
    {
        $parts = [
            $detection->caption_text,
            $detection->analysis_summary,
            $detection->notes,
        ];

        $base = trim(collect($parts)->filter()->implode(' '));

        if ($base === '' && $detection->source_url) {
            $host = parse_url($detection->source_url, PHP_URL_HOST) ?: '';
            $base = $host !== '' ? $host : '';
        }

        $base = trim(preg_replace('/\s+/', ' ', $base) ?? $base);

        if ($base === '') {
            return null;
        }

        $keywords = $this->extractKeywords($base, 6);
        $query = $keywords !== [] ? implode(' ', $keywords) : Str::limit($base, 90, '');

        $locationLabel = trim((string) ($context['weather_label'] ?? ''));
        $hasConsent = filter_var($context['weather_consent'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($hasConsent && $locationLabel !== '' && ! $this->isGenericLocationLabel($locationLabel)) {
            $query = trim($query.' '.$locationLabel);
        }

        return Str::limit($query, 90, '');
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

    /**
     * @return list<string>
     */
    private function extractKeywords(string $text, int $limit = 6): array
    {
        $stopWords = [
            'about', 'after', 'again', 'against', 'already', 'also', 'always', 'before', 'being', 'claim', 'claims',
            'compare', 'could', 'describe', 'detection', 'depicts', 'display', 'displays', 'displaying', 'evidence',
            'false', 'fake', 'from', 'have', 'image', 'images', 'input', 'into', 'just', 'link', 'look', 'looks',
            'map', 'more', 'must', 'named', 'need', 'news', 'page', 'paste', 'post', 'presents', 'presenting',
            'reporting', 'same', 'screenshot', 'screenshots', 'share', 'should', 'show', 'showing', 'shows', 'source',
            'sources', 'submission', 'submitted', 'text', 'that', 'their', 'there', 'these', 'they', 'this', 'those',
            'upload', 'uploaded', 'using', 'video', 'viral', 'want', 'what', 'when', 'where', 'which', 'with', 'would',
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
}
