<?php

namespace App\Services\Detections;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/** Makes an exact published claim review decisive without treating related coverage as proof. */
final class ExactFactCheckReconciler
{
    public const VERSION = 'exact-fact-check-reconciliation-v1';

    public function __construct(private readonly FactCheckClaimMatcher $matcher) {}

    /** @param array<int, array<string, mixed>> $records */
    public function apply(array $result, array $records): array
    {
        $submitted = Str::squish((string) ($result['claim'] ?? ''));
        if ($submitted === '') {
            return $result;
        }

        $matches = collect($records)
            ->filter(fn ($record): bool => is_array($record)
                && ($record['source_type'] ?? null) === 'fact_check'
                && filled($record['review_rating'] ?? null)
                && filled($record['url'] ?? null))
            ->map(function (array $record) use ($submitted): ?array {
                $reviewed = Str::squish(collect([
                    $record['reviewed_claim'] ?? null,
                    $record['title'] ?? null,
                ])->filter()->implode(' '));
                $match = $this->matcher->assess($submitted, $reviewed);
                $rating = FactCheckRatingNormalizer::normalize(
                    (string) ($record['review_rating'] ?? ''),
                    $reviewed,
                );
                $verdict = $this->verdict($rating);

                return ($match['level'] ?? null) === 'exact' && $verdict !== null
                    ? compact('record', 'match', 'rating', 'verdict') : null;
            })
            ->filter()
            ->values();

        if ($matches->isEmpty() || $matches->pluck('verdict')->unique()->count() !== 1) {
            return $result;
        }

        $selected = $matches->sortByDesc(fn (array $row): int => (int) data_get($row, 'match.score', 0))->first();
        $record = $selected['record'];
        $rating = $selected['rating'];
        $verdict = $selected['verdict'];
        $publisher = Str::squish((string) ($record['name'] ?? 'the linked fact-check publisher'));
        $reviewedClaim = Str::squish((string) ($record['reviewed_claim'] ?? $record['title'] ?? $submitted));
        $sourceId = (string) ($record['id'] ?? 'fact-check-'.substr(hash('sha256', (string) $record['url']), 0, 12));
        $summary = "{$publisher} reviewed the matching claim and rated it {$rating}. The reviewed claim was: {$reviewedClaim}.";

        $source = Arr::only($record, [
            'name', 'title', 'url', 'published_at', 'updated_at', 'retrieved_at', 'possible_copy_of',
        ]) + [
            'id' => $sourceId,
            'type' => 'fact_check',
            'used_for' => "Exact published fact-check rating: {$rating}.",
            'relationships' => [],
            'claim_match' => $selected['match'],
        ];

        $result['sources'] = collect($result['sources'] ?? [])
            ->filter(fn ($item): bool => is_array($item))
            ->push($source)
            ->unique('id')
            ->values()
            ->all();
        $result['verdict'] = $verdict;
        $result['assessment_label'] = AssessmentVerdict::label($verdict);
        $result['summary'] = $summary;
        $result['answer_segments'] = [['text' => $summary, 'source_ids' => [$sourceId]]];
        $result['key_findings'] = ["Exact fact-check match: {$publisher} rated the claim {$rating}."];
        $result['evidence_strength'] = [
            'label' => 'Exact fact-check match',
            'reason' => $summary,
            'method' => self::VERSION,
        ];
        $result['ui'] = array_replace($result['ui'] ?? [], [
            'verdict_color' => match ($verdict) {
                'TRUE' => 'green', 'FALSE' => 'red', default => 'amber',
            },
            'show_evidence_warning' => false,
            'evidence_warning' => null,
            'show_missing_context' => $verdict === 'MISSING_CONTEXT',
            'missing_context' => $verdict === 'MISSING_CONTEXT'
                ? "The matched fact-check itself rated the claim {$rating}; open it for the omitted context." : null,
        ]);
        $result['exact_fact_check'] = [
            'status' => 'applied',
            'publisher' => $publisher,
            'rating' => $rating,
            'source_id' => $sourceId,
            'claim_match' => $selected['match'],
            'version' => self::VERSION,
        ];

        $outcome = match ($verdict) {
            'TRUE' => 'supported',
            'FALSE' => 'refuted',
            'PARTLY_TRUE', 'MISLEADING', 'OUT_OF_CONTEXT', 'MISSING_CONTEXT' => 'needs_context',
            default => 'insufficient',
        };
        if (count($result['claims'] ?? []) === 1) {
            $result['claims'][0]['outcome'] = $outcome;
            $result['claims'][0]['requires_context'] = $verdict === 'MISSING_CONTEXT';
            $result['claims'][0]['context_reason'] = $verdict === 'MISSING_CONTEXT'
                ? "The exact fact-check rating is {$rating}." : null;
            $result['claims'][0]['relationships'] = [[
                'source_id' => $sourceId,
                'stance' => $outcome === 'supported' ? 'supporting' : ($outcome === 'refuted' ? 'contradicting' : 'contextual'),
                'excerpt' => $reviewedClaim,
                'explanation' => $summary,
                'superseded' => false,
            ]];
        }

        return $result;
    }

    private function verdict(string $rating): ?string
    {
        return match ($rating) {
            FactCheckRatingNormalizer::CONFIRMED => 'TRUE',
            FactCheckRatingNormalizer::FALSE,
            FactCheckRatingNormalizer::AI_GENERATED_MISINFORMATION,
            FactCheckRatingNormalizer::MANIPULATED_MEDIA => 'FALSE',
            FactCheckRatingNormalizer::PARTLY_FALSE => 'PARTLY_TRUE',
            FactCheckRatingNormalizer::MISLEADING => 'MISLEADING',
            FactCheckRatingNormalizer::MISCAPTIONED => 'OUT_OF_CONTEXT',
            FactCheckRatingNormalizer::MISSING_CONTEXT => 'MISSING_CONTEXT',
            default => null,
        };
    }
}
