<?php

namespace App\Services\Detections;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class FactCheckEvidencePolicy
{
    public const SOURCE_ORDER = ['official_government', 'institution', 'research', 'fact_check', 'reputable_news'];

    public function normalizeClaim(string $claim): string
    {
        if (class_exists(\Normalizer::class)) {
            $claim = \Normalizer::normalize($claim, \Normalizer::FORM_C) ?: $claim;
        }

        return Str::lower(Str::squish($claim));
    }

    /** Only retrieved, approved records enter the model's evidence snapshot. */
    public function sources(array $records, string $claim, string $cutoff): array
    {
        $sources = [];
        foreach ($records as $record) {
            if (! is_array($record) || ($record['evidence_verified'] ?? false) !== true
                || ($record['relevant'] ?? true) === false
                || in_array($record['evidence_kind'] ?? '', ['search_snippet', 'search_route', 'social_post'], true)) {
                continue;
            }
            $url = trim((string) ($record['url'] ?? ''));
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            $type = match ($record['type'] ?? $record['source_type'] ?? '') {
                'official' => 'official_government',
                'news' => 'reputable_news',
                default => $record['type'] ?? $record['source_type'] ?? '',
            };
            if (! filter_var($url, FILTER_VALIDATE_URL) || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)
                || parse_url($url, PHP_URL_USER) !== null || ! $this->approvedHost($host, $type)) {
                continue;
            }
            if ($this->matchesHost($host, 'pagasa.dost.gov.ph') && ! $this->isWeather($claim)) {
                continue;
            }
            $title = trim((string) ($record['title'] ?? $record['purpose'] ?? ''));
            $evidence = trim((string) ($record['evidence_text'] ?? ''));
            if ($title === '' || $evidence === '' || blank($record['name'] ?? null)) {
                continue;
            }
            $publishedAt = $this->date($record['published_at'] ?? null);
            if ($publishedAt !== null && Carbon::parse($publishedAt)->gt(Carbon::parse($cutoff))) {
                continue;
            }
            $sources[] = [
                'name' => trim((string) $record['name']),
                'title' => $title,
                'url' => $url,
                'type' => $type,
                'published_at' => $publishedAt,
                'evidence_text' => $evidence,
                'claim_text' => trim((string) ($record['claim_text'] ?? '')),
                'rating' => trim((string) ($record['evidence_rating'] ?? $record['rating'] ?? '')),
            ];
        }

        return collect($sources)->sortBy(fn (array $source) => array_search($source['type'], self::SOURCE_ORDER, true).'|'.$source['url'])
            ->unique('url')->values()->all();
    }

    public function date(mixed $date): ?string
    {
        if (! is_string($date) || ! preg_match('/(?:\d{4}-\d{2}-\d{2}|[A-Za-z]{3,9}\s+\d{1,2},?\s+\d{4}|\d{1,2}\s+[A-Za-z]{3,9}\s+\d{4})/', $date)) {
            return null;
        }
        try {
            return Carbon::parse($date)->utc()->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    public function isWeather(string $claim): bool
    {
        return (bool) preg_match('/\b(weather|climate|typhoon|rain(?:fall)?|forecast|storm|bagyo|pagasa|panahon|ulan|flood|baha)\b/iu', $claim);
    }

    public function isSensitive(string $claim): bool
    {
        if (preg_match('/\b(classes (?:are )?suspended|school closures?|suspension of classes)\b/iu', $claim)) {
            return true;
        }
        if (preg_match('/\b(covid(?:-19)?|dengue|measles|cancer|diabetes|treatment|drug|dosage|symptoms|GDP|tax|stocks?|pension|salary|wage|rescue|shooting|bomb|terror|poison|recall|constitution|ordinance|regulation)\b/iu', $claim)) {
            return true;
        }

        return $this->isWeather($claim) || (bool) preg_match('/\b(health|vacci\w+|virus|outbreak|disease|medicine|cure|hospital|doh|kalusugan|sakit|gamot|disaster|earthquake|tsunami|eruption|ndrrmc|lindol|election|vote|ballot|comelec|halalan|boto|law|legal|court|proclamation|policy|batas|finance|financial|investment|interest rate|bank|inflation|money|pera|public safety|emergency|evacuat\w+|hazard|warning|class suspension|walang pasok)\b/iu', $claim);
    }

    public function isTimeSensitive(string $claim): bool
    {
        return $this->isSensitive($claim) || (bool) preg_match('/\b(today|tomorrow|yesterday|current|currently|latest|now|this week|ngayon|bukas|\d{4})\b/iu', $claim);
    }

    public function rating(string $rating): ?string
    {
        return match (Str::lower(trim($rating))) {
            'true', 'correct', 'confirmed' => 'TRUE',
            'false', 'fake', 'pants on fire', 'ai-generated, misinformation', 'manipulated media' => 'FALSE',
            'misleading', 'missing context', 'miscaptioned' => 'MISLEADING',
            'partly true', 'partly_true', 'partly false', 'half true', 'half-true' => 'PARTLY_TRUE',
            'outdated', 'out of date' => 'OUTDATED',
            default => null,
        };
    }

    private function approvedHost(string $host, string $type): bool
    {
        foreach ((array) config('fact_check.publishers.'.$type, []) as $domain) {
            if ($this->matchesHost($host, $domain)) {
                return true;
            }
        }

        return false;
    }

    private function matchesHost(string $host, string $domain): bool
    {
        return $host === $domain || str_ends_with($host, '.'.$domain);
    }
}
