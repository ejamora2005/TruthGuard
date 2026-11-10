<?php

namespace App\Services\Detections;

/** Observed share of assessed statements with recorded problems; not a probability. */
final class RecordedFactRisk
{
    public const METHOD = 'saved-claim-risk-share-v1';

    public static function calculate(array $saved, array $public): ?array
    {
        if (($saved['schema_version'] ?? 0) < 4
            || ! in_array($public['primary_task'] ?? '', ['claim', 'context'], true)
            || AssessmentVerdict::technical($public['verdict'] ?? 'ANALYSIS_UNAVAILABLE')) {
            return null;
        }
        $sources = collect($public['sources'] ?? [])->filter(fn ($source) => ! ($source['context_only'] ?? false));
        $total = $assessed = $flagged = 0;
        $seen = [];
        foreach ($saved['claims'] ?? [] as $claim) {
            if (! is_array($claim) || ($claim['kind'] ?? '') !== 'factual'
                || ! ($id = ResultContract::text($claim['id'] ?? null)) || isset($seen[$id])
                || ResultContract::text($claim['text'] ?? $claim['quote'] ?? null) === null) {
                continue;
            }
            $seen[$id] = true;
            $total++;
            if (($claim['requires_context'] ?? false)
                || ! in_array($claim['outcome'] ?? '', ['supported', 'refuted'], true)) {
                continue;
            }
            $stances = [];
            foreach ($sources as $source) {
                foreach ($source['relationships'] ?? [] as $row) {
                    if (($row['claim_id'] ?? '') !== $id || ($row['superseded'] ?? false)
                        || ! in_array($row['stance'] ?? '', ['supporting', 'contradicting'], true)
                        || empty($row['excerpt']) || empty($row['explanation'])
                        || ! self::admitted($claim, $source, $row)) continue;
                    $stances[$row['stance']] = true;
                }
            }
            $expected = $claim['outcome'] === 'refuted' ? 'contradicting' : 'supporting';
            // Conflicting passages remain unresolved; do not choose a side to
            // manufacture a score, even if an older claim outcome is decisive.
            if (count($stances) !== 1 || ! isset($stances[$expected])) continue;
            $assessed++;
            if ($claim['outcome'] === 'refuted') $flagged++;
        }
        if ($assessed === 0) return null;
        $value = (int) round(100 * $flagged / $assessed);
        $unresolved = $total - $assessed;
        $scope = $flagged.' of '.$assessed.' assessed factual statements have a recorded evidence-backed problem.';
        if ($unresolved) $scope .= ' '.$unresolved.' unresolved '.($unresolved === 1 ? 'statement is' : 'statements are').' excluded from the percentage.';

        return ['value' => $value, 'display' => $value.'%', 'caption' => FactCheckReportPresenter::riskLevel($value),
            'method' => self::METHOD, 'color' => FactCheckReportPresenter::riskColor($value),
            'explanation' => $scope.' This is the observed share of assessed statements, not the probability that the whole submission is false, a measure of harm severity, or model confidence.',
            'counts' => ['total' => $total, 'assessed' => $assessed, 'flagged' => $flagged, 'unresolved' => $unresolved],
            'show_explanation' => true];
    }

    private static function admitted(array $claim, array $source, array $row): bool
    {
        $original = $claim['relationships'] ?? [];
        if ($original === []) return true; // Older schema-4 reports saved admission only on the source.
        foreach ($original as $candidate) {
            if (($candidate['claim_id'] ?? $claim['id']) !== $claim['id']
                || ($candidate['source_id'] ?? '') !== $source['id'] || ($candidate['stance'] ?? '') !== $row['stance']
                || ($candidate['excerpt'] ?? '') !== $row['excerpt'] || ($candidate['superseded'] ?? false)
                || ($candidate['basis'] ?? '') === 'background' || ! empty($candidate['validation_limitation'])) continue;
            if (isset($candidate['matches']) && collect(['event', 'entities', 'action', 'date', 'location', 'quantities', 'qualifiers'])
                ->contains(fn ($dimension) => ($candidate['matches'][$dimension] ?? null) !== true)) continue;

            return true;
        }

        return false;
    }
}
