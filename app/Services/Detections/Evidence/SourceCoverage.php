<?php

namespace App\Services\Detections\Evidence;

use App\Services\Detections\ReportAnswer;

/** Counts admitted sources, never raw search hits or copied pages. */
final class SourceCoverage
{
    public static function target(): int
    {
        return max(0, (int) config('fact_check.min_relevant_sources', 10));
    }

    public static function count(array $records, array $sourceIds): int
    {
        $records = array_column(array_values($records), null, 'id');
        $seen = [];
        $urls = [];
        foreach (array_unique($sourceIds) as $id) {
            $record = $records[$id] ?? null;
            if (! $record || ! in_array($record['retrieval_status'] ?? '', ['retrieved', 'reviewed_database'], true)
                || ($record['source_type'] ?? '') === 'unapproved'
                || ! ReportAnswer::safeUrl($record['url'] ?? null)) {
                continue;
            }
            $parts = parse_url($record['url']);
            $url = strtolower(preg_replace('/^www\./i', '', $parts['host'])).rtrim($parts['path'] ?? '/', '/');
            parse_str($parts['query'] ?? '', $query);
            $query = array_filter($query, fn ($key) => ! preg_match('/^(?:utm_|fbclid$|gclid$)/i', $key), ARRAY_FILTER_USE_KEY);
            ksort($query);
            $url .= $query ? '?'.http_build_query($query) : '';
            $root = $id;
            $visited = [];
            while (isset($records[$root]) && ! isset($visited[$root])) {
                $visited[$root] = true;
                $parent = $records[$root]['duplicate_of'] ?? $records[$root]['possible_copy_of'] ?? null;
                if (! is_string($parent) || $parent === '') break;
                $root = $parent;
            }
            $hash = $records[$root]['content_hash'] ?? $record['content_hash'] ?? null;
            $key = $hash ? 'hash:'.$hash : 'origin:'.$root;
            if (isset($urls[$url]) || isset($seen[$key]) || isset($seen['origin:'.$root])) continue;
            $urls[$url] = true;
            $seen[$key] = true;
            $seen['origin:'.$root] = true;
        }

        return count($urls);
    }

    public static function relevantIds(array $relationships, array $contexts = []): array
    {
        return array_values(array_unique(array_filter(array_column(array_merge(
            array_values(array_filter($relationships, fn ($row) => in_array($row['stance'] ?? '', ['supporting', 'contradicting'], true)
                && ! ($row['superseded'] ?? false))), $contexts), 'source_id'))));
    }
}
