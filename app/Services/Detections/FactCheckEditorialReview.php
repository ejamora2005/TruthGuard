<?php

namespace App\Services\Detections;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/** Deterministic editorial checks and disclosures, independent of generated wording. */
final class FactCheckEditorialReview
{
    public static function apply(array $result): array
    {
        if (! in_array($result['primary_task'] ?? '', ['claim', 'context'], true)) {
            return $result;
        }

        $claims = is_array($result['claims'] ?? null) ? $result['claims'] : [];
        $sources = is_array($result['sources'] ?? null) ? $result['sources'] : [];
        $result['atomic_claims'] = self::atomicClaims($claims);
        $result['evidence_families'] = self::families($sources);
        $result['evidence_balance'] = self::balance($claims);
        $result['claim_origin'] = self::origin($result);
        $result['negative_evidence'] = self::negativeEvidence($result);
        $result['freshness'] = self::freshness($result);
        $result['source_assessments'] = array_map([self::class, 'sourceAssessment'], $sources);
        $result['verdict_audit'] = self::verdictAudit($result);
        $result['editorial_review'] = self::editorialReview($result);

        return $result;
    }

    private static function atomicClaims(array $claims): array
    {
        return array_values(array_map(function (array $claim): array {
            $relationships = is_array($claim['relationships'] ?? null) ? $claim['relationships'] : [];

            return [
                'id' => (string) ($claim['id'] ?? ''),
                'text' => Str::squish((string) ($claim['text'] ?? '')),
                'outcome' => in_array($claim['outcome'] ?? null, ['supported', 'refuted', 'insufficient', 'conflicting', 'needs_context'], true)
                    ? $claim['outcome'] : 'insufficient',
                'supporting_source_ids' => self::relationshipIds($relationships, 'supporting'),
                'contradicting_source_ids' => self::relationshipIds($relationships, 'contradicting'),
                'limitation' => Str::squish((string) ($claim['context_reason'] ?? '')),
            ];
        }, array_filter($claims, 'is_array')));
    }

    private static function relationshipIds(array $rows, string $stance): array
    {
        return array_values(array_unique(array_filter(array_map(fn ($row) => is_array($row)
            && ($row['stance'] ?? null) === $stance && ! ($row['superseded'] ?? false)
                ? ($row['source_id'] ?? null) : null, $rows), 'is_string')));
    }

    private static function families(array $sources): array
    {
        $ids = array_fill_keys(array_filter(array_column($sources, 'id'), 'is_string'), true);
        $families = [];
        foreach ($sources as $source) {
            if (! is_array($source) || ! is_string($source['id'] ?? null)) continue;
            $root = is_string($source['possible_copy_of'] ?? null) && isset($ids[$source['possible_copy_of']])
                ? $source['possible_copy_of'] : $source['id'];
            $families[$root] ??= ['id' => 'family-'.$root, 'root_source_id' => $root, 'source_ids' => [], 'independent_unit' => true];
            $families[$root]['source_ids'][] = $source['id'];
        }

        return array_values($families);
    }

    private static function balance(array $claims): array
    {
        $supporting = $contradicting = [];
        foreach ($claims as $claim) {
            if (! is_array($claim)) continue;
            $supporting = array_merge($supporting, self::relationshipIds($claim['relationships'] ?? [], 'supporting'));
            $contradicting = array_merge($contradicting, self::relationshipIds($claim['relationships'] ?? [], 'contradicting'));
        }

        return ['supporting_source_ids' => array_values(array_unique($supporting)),
            'contradicting_source_ids' => array_values(array_unique($contradicting)),
            'both_sides_considered' => $supporting !== [] && $contradicting !== []];
    }

    private static function origin(array $result): array
    {
        $record = $result['extraction']['source_record'] ?? [];
        $url = is_array($record) ? ReportAnswer::safeUrl($record['url'] ?? null) : null;

        return ['claimant' => null, 'first_appearance_url' => null, 'submitted_appearance_url' => $url,
            'first_seen_at' => null, 'submitted_wording' => Str::squish((string) ($result['claim'] ?? '')),
            'origin_status' => $url ? 'submitted_appearance_recorded' : 'unknown'];
    }

    private static function negativeEvidence(array $result): array
    {
        $verdict = $result['verdict'] ?? '';
        $applicable = in_array($verdict, ['UNVERIFIED', 'UNVERIFIABLE', 'MISSING_CONTEXT'], true);
        $attempts = $result['diagnostics']['search_attempts'] ?? [];
        if (is_int($attempts)) $attempts = [];

        return ['applicable' => $applicable, 'absence_treated_as_disproof' => false,
            'search_scope_recorded' => is_array($attempts) && $attempts !== [],
            'conclusion' => $applicable
                ? 'No matching record is not, by itself, proof that the claim is false.'
                : 'The verdict does not rely solely on absence of search results.'];
    }

    private static function freshness(array $result): array
    {
        $text = Str::lower((string) ($result['claim'] ?? ''));
        $days = match (true) {
            (bool) preg_match('/\b(?:weather|typhoon|storm|rainfall|earthquake|warning|advisory|outbreak)\b/u', $text) => 1,
            (bool) preg_match('/\b(?:election|court|ruling|appointment|investigation|missing person)\b/u', $text) => 7,
            (bool) preg_match('/\b(?:bill|resolution|law|policy|proposal|current|latest|still)\b/u', $text) => 30,
            default => null,
        };
        $assessed = Carbon::parse($result['assessed_at'] ?? now()->utc());

        return ['time_sensitive' => $days !== null, 'status' => $days === null ? 'not_time_sensitive' : 'current_at_assessment',
            'valid_through' => $days === null ? null : $assessed->copy()->addDays($days)->toIso8601String(),
            'next_review_at' => $days === null ? null : $assessed->copy()->addDays($days)->toIso8601String()];
    }

    public static function sourceAssessment(array $source): array
    {
        $host = Str::lower((string) parse_url((string) ($source['url'] ?? ''), PHP_URL_HOST));
        $official = str_ends_with($host, '.gov.ph') || in_array($source['type'] ?? '', ['official_government', 'institution'], true);
        $denial = preg_match('/\b(?:deny|denies|denied|denial)\b/u', Str::lower((string) (($source['title'] ?? '').' '.($source['used_for'] ?? ''))));

        return ['source_id' => (string) ($source['id'] ?? ''), 'primary_or_official' => $official,
            'authority_scope' => $official ? 'The source can establish records and statements within its institutional responsibility.' : 'Independent reporting or analysis.',
            'interest_disclosure' => $denial ? 'This source reports or contains a denial by an interested party.' : null,
            'cannot_alone_establish' => $denial ? 'A denial alone does not prove every underlying fact.' : null];
    }

    private static function verdictAudit(array $result): array
    {
        $outcomes = array_column($result['atomic_claims'] ?? [], 'outcome');
        $verdict = $result['verdict'] ?? '';
        $problems = [];
        if ($outcomes !== [] && $verdict === 'TRUE' && collect($outcomes)->contains(fn ($o) => $o !== 'supported')) {
            $problems[] = 'A TRUE verdict contains an atomic claim that was not supported.';
        }
        if ($outcomes !== [] && $verdict === 'FALSE' && collect($outcomes)->contains(fn ($o) => $o !== 'refuted')) {
            $problems[] = 'A FALSE verdict contains an atomic claim that was not refuted.';
        }
        if (in_array('conflicting', $outcomes, true) && $verdict !== 'CONFLICTING_EVIDENCE') {
            $problems[] = 'Conflicting atomic evidence was not reflected in the overall verdict.';
        }

        return ['status' => $problems === [] ? 'passed' : 'failed', 'problems' => $problems,
            'method' => 'deterministic-independent-verdict-audit-v1'];
    }

    private static function editorialReview(array $result): array
    {
        $highImpact = (bool) preg_match('/\b(?:election|vote|health|medicine|court|crime|shoot|kill|death|investment|bank)\b/u', Str::lower((string) ($result['claim'] ?? '')));
        $auditFailed = ($result['verdict_audit']['status'] ?? '') === 'failed';
        $conflict = ($result['verdict'] ?? '') === 'CONFLICTING_EVIDENCE';

        return ['required' => $highImpact || $auditFailed || $conflict,
            'reasons' => array_values(array_filter([$highImpact ? 'Consequential public-interest claim.' : null,
                $auditFailed ? 'Independent verdict audit failed.' : null, $conflict ? 'Reliable evidence conflicts.' : null])),
            'status' => ($highImpact || $auditFailed || $conflict) ? 'pending' : 'not_required'];
    }
}
