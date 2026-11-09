<?php

namespace App\Services\Detections;

/** Present saved, relevant reference pages without promoting them to verdict evidence. */
final class AdditionalSourceReferences
{
    public function select(array $result, array $records, array $usedSources): array
    {
        if (! in_array($result['primary_task'] ?? 'claim', ['claim', 'context'], true)) {
            return [];
        }
        $claims = collect($result['claims'] ?? [])->filter(fn ($claim) => is_array($claim)
            && ($claim['kind'] ?? '') === 'factual')->pluck('text')->push($result['claim'] ?? null)
            ->filter(fn ($text) => is_string($text) && trim($text) !== '')->unique()->values()->all();
        if ($claims === []) {
            return [];
        }
        $seen = [];
        $seenHashes = [];
        $usedIds = array_column($usedSources, 'id');
        foreach ($usedSources as $source) {
            if (is_array($source) && ($url = ReportAnswer::safeUrl($source['url'] ?? null))) {
                $seen[$this->urlKey($url)] = true;
            }
        }
        foreach ($records as $record) {
            if (is_array($record) && ! empty($record['content_hash'])
                && (in_array($record['id'] ?? null, $usedIds, true)
                    || (($url = ReportAnswer::safeUrl($record['url'] ?? null)) && isset($seen[$this->urlKey($url)])))) {
                $seenHashes[$record['content_hash']] = true;
            }
        }
        $claimIds = collect($result['claims'] ?? [])->filter(fn ($claim) => is_array($claim)
            && ($claim['kind'] ?? '') === 'factual')->pluck('id')->filter()->all();
        $matcher = new FactCheckClaimMatcher;
        $references = [];
        foreach ($records as $record) {
            if (! is_array($record) || ! ($url = ReportAnswer::safeUrl($record['url'] ?? null))
                || ($record['retrieval_status'] ?? '') !== 'retrieved'
                || in_array($record['assessment_role'] ?? '', ['irrelevant', 'superseded', 'discovered'], true)
                || in_array($record['source_type'] ?? '', ['unapproved', 'social_context'], true)
                || ! empty($record['duplicate_of']) || ! empty($record['possible_copy_of'])
                || trim((string) ($record['text'] ?? '')) === ''
                || trim((string) parse_url($url, PHP_URL_PATH), '/') === ''
                || preg_match('/NoticeNotActiveErrorPage|access denied|page not found|captcha/i', $record['title'] ?? '')) {
                continue;
            }
            $key = $this->urlKey($url);
            if (isset($seen[$key]) || (! empty($record['content_hash']) && isset($seenHashes[$record['content_hash']]))) {
                continue;
            }
            $related = false;
            foreach ($record['relevance_assessments'] ?? [] as $assessment) {
                if (! is_array($assessment) || ! empty($assessment['superseded'])
                    || ! empty($assessment['validation_limitation'])
                    || ($claimIds !== [] && ! in_array($assessment['claim_id'] ?? null, $claimIds, true))
                    || (isset($assessment['source_id'], $record['id']) && $assessment['source_id'] !== $record['id'])) {
                    continue;
                }
                $matches = $assessment['matches'] ?? [];
                $excerpt = trim((string) ($assessment['excerpt'] ?? ''));
                // A partial answer may still be a useful reference about the same
                // event, but it cannot acquire a supporting/contradicting label.
                if (count(array_filter(['event', 'entities', 'action', 'date', 'location'],
                    fn ($dimension) => ($matches[$dimension] ?? false) === true)) === 5
                    && mb_strlen($excerpt) >= 20 && mb_strlen($excerpt) <= 1200
                    && str_contains($record['text'], $excerpt)
                    && (! isset($record['reviewed_text']) || str_contains($record['reviewed_text'], $excerpt))) {
                    $related = true;
                    break;
                }
            }
            // Only unassessed records may use the conservative title/claim match.
            // Never override a recorded rejection with a keyword/title match.
            if (! $related && empty($record['relevance_assessments'])
                && in_array($record['assessment_role'] ?? 'not_used', ['not_used', 'reference_only'], true)) {
                foreach ($claims as $claim) {
                    if ($matcher->assess($claim, $record['reviewed_claim'] ?? $record['title'] ?? '')['level'] === 'exact') {
                        $related = true;
                        break;
                    }
                }
            }
            if (! $related) {
                continue;
            }
            $seen[$key] = true;
            if (! empty($record['content_hash'])) {
                $seenHashes[$record['content_hash']] = true;
            }
            $host = strtolower(preg_replace('/^www\./i', '', parse_url($url, PHP_URL_HOST)));
            $type = match ($record['source_type'] ?? '') {
                'official_government' => 'Government record',
                'institution' => 'Institutional reference',
                'research' => 'Research reference',
                'fact_check' => 'Fact-check reference',
                'news' => 'News reference',
                default => 'Reference',
            };
            $references[$host][] = ['url' => $url, 'host' => $host, 'title' => $record['title'] ?? 'Related reference',
                'name' => $record['name'] ?? $host, 'type_label' => $type];
        }
        // Interleave publishers so a single site does not occupy the first rows.
        $ordered = [];
        while ($references !== []) {
            foreach ($references as $host => &$rows) {
                $ordered[] = array_shift($rows);
                if ($rows === []) {
                    unset($references[$host]);
                }
            }
            unset($rows);
        }

        return $ordered;
    }

    private function urlKey(string $url): string
    {
        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $query);
        $query = array_filter($query, fn ($key) => ! preg_match('/^(?:utm_|fbclid$|gclid$)/i', $key), ARRAY_FILTER_USE_KEY);
        ksort($query);

        return strtolower(preg_replace('/^www\./i', '', $parts['host'])).rtrim($parts['path'] ?? '/', '/')
            .($query ? '?'.http_build_query($query) : '');
    }
}
