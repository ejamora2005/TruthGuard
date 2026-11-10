<?php

namespace App\Services\Detections;

use App\Services\Detections\Evidence\ArticleSearch;

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

    /** Related discovery links stay separate from evidence and citations. */
    public function discovered(array $result, array $records, array $displayedSources): array
    {
        if (! in_array($result['primary_task'] ?? 'claim', ['claim', 'context'], true)) {
            return [];
        }
        $seen = [];
        foreach ($displayedSources as $source) {
            if (is_array($source) && ($url = ReportAnswer::safeUrl($source['url'] ?? null))) {
                $seen[$this->urlKey($url)] = true;
            }
        }
        $records = array_merge($records, $result['diagnostics']['candidate_records'] ?? []);
        $claims = collect($result['claims'] ?? [])->filter(fn ($claim) => is_array($claim))
            ->pluck('text')->push($result['claim'] ?? null)
            ->push($result['extraction']['analysis_text'] ?? $result['extraction']['visible_text'] ?? null)
            ->filter(fn ($text) => is_string($text) && trim($text) !== '')->unique()->all();
        $claimIds = array_column($result['claims'] ?? [], 'id');
        $references = [];
        $search = app(ArticleSearch::class);
        foreach ($records as $record) {
            if (! is_array($record) || ! ($url = ReportAnswer::safeUrl($record['url'] ?? null))
                || ! is_string($record['retrieval_status'] ?? null) || $search->isGenericLandingPage($url)
                || in_array($record['assessment_role'] ?? '', ['irrelevant', 'superseded'], true)
                || in_array($record['retrieval_status'], ['duplicate', 'after_cutoff'], true)
                || ! empty($record['duplicate_of']) || ! empty($record['possible_copy_of'])
                || preg_match('/NoticeNotActiveErrorPage|access denied|page not found|captcha/i', $record['title'] ?? '')
                || ! $this->relatedDiscovery($record, $claims, $claimIds)) {
                continue;
            }
            $key = $this->urlKey($url);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $host = strtolower(preg_replace('/^www\./i', '', parse_url($url, PHP_URL_HOST)));
            $status = $record['retrieval_status'];
            $role = $record['assessment_role'] ?? '';
            $description = match (true) {
                $role === 'superseded' => 'Superseded record',
                $status === 'duplicate' => 'Duplicate page',
                $status === 'discovered' => 'Found; not reviewed',
                $status === 'after_cutoff' => 'Published after the assessment cutoff',
                $status === 'undated_historical' => 'Publication date could not be established',
                in_array($status, ['reference_only', 'reviewed_database'], true) => 'Reference found during research',
                $status !== 'retrieved' => 'Page could not be read',
                in_array($role, ['irrelevant', 'background_or_rejected_match'], true) => 'Did not establish this claim',
                default => 'Retrieved during research',
            };
            $title = is_string($record['title'] ?? null) ? trim(strip_tags($record['title'])) : '';
            $name = is_string($record['name'] ?? null) ? $record['name'] : $host;
            $references[] = ['url' => $url, 'host' => $host,
                'name' => ReportAnswer::publisherName($name, $url), 'title' => $title !== '' ? mb_substr($title, 0, 300) : $host,
                'status_label' => $description];
        }

        return $references;
    }

    private function relatedDiscovery(array $record, array $claims, array $claimIds): bool
    {
        if (! empty($record['relevance_assessments'])) {
            foreach ($record['relevance_assessments'] as $assessment) {
                if (! is_array($assessment) || ! empty($assessment['superseded']) || ! empty($assessment['validation_limitation'])
                    || ($claimIds !== [] && ! in_array($assessment['claim_id'] ?? null, $claimIds, true))
                    || (isset($assessment['source_id'], $record['id']) && $assessment['source_id'] !== $record['id'])) {
                    continue;
                }
                $matches = $assessment['matches'] ?? [];
                if (($matches['event'] ?? false) === true && ($matches['entities'] ?? false) === true && ($matches['date'] ?? false) === true) {
                    return true;
                }
            }

            return false; // Do not replace a saved rejection with keyword overlap.
        }
        $description = implode(' ', array_filter(array_map(fn ($field) => is_string($record[$field] ?? null)
            ? mb_substr($record[$field], 0, 2400) : '', ['reviewed_claim', 'title', 'snippet', 'description', 'text'])));
        $matcher = new FactCheckClaimMatcher;
        foreach ($claims as $claim) {
            $match = $matcher->assess($claim, $description);
            $terms = array_filter($match['matched_terms'], fn ($term) => ! is_numeric($term)
                && ! preg_match('/^(?:january|february|march|april|may|june|july|august|september|october|november|december|official|public|announcement|announced|announces|advisory|news|record|according|reported|report|says|said|denied|denies|statement|issued|issues|update|false|true)$/i', $term));
            if ($match['date_match'] && $match['level'] !== 'unrelated' && count($terms) >= 2) {
                return true;
            }
        }

        return false;
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
