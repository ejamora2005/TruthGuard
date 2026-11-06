<?php

namespace App\Services\Detections;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/** Makes an exact published claim review decisive without treating related coverage as proof. */
final class ExactFactCheckReconciler
{
    public const VERSION = 'exact-fact-check-reconciliation-v2';

    public function __construct(private readonly FactCheckClaimMatcher $matcher, private readonly FactCheckEvidencePolicy $policy) {}

    /** @param array<int, array<string, mixed>> $records */
    public function apply(array $result, array $records): array
    {
        $submitted = Str::squish((string) ($result['claim'] ?? ''));
        if ($submitted === '' || count($result['claims'] ?? []) > 1) {
            return $result;
        }

        $matches = collect($records)
            ->filter(fn ($record): bool => is_array($record)
                && ($record['source_type'] ?? null) === 'fact_check'
                && ($record['retrieval_status'] ?? null) === 'retrieved'
                && filled($record['text'] ?? null)
                && filled($record['reviewed_claim'] ?? null)
                && filled($record['review_rating'] ?? null)
                && $this->policy->isFactCheckUrl((string) ($record['url'] ?? ''))
                && $this->policy->isFactCheckUrl((string) ($record['final_url'] ?? $record['url'] ?? '')))
            ->map(function (array $record) use ($submitted): ?array {
                // The publisher's title may deny the claim. Match the actual
                // reviewed proposition; combining it with a title erases scope.
                $reviewed = Str::squish((string) $record['reviewed_claim']);
                $match = $this->matcher->assess($submitted, $reviewed);
                $rating = FactCheckRatingNormalizer::normalize(
                    (string) ($record['review_rating'] ?? ''),
                );
                $verdict = $this->verdict($rating);

                return ($match['level'] ?? null) === 'exact' && $verdict !== null
                    ? compact('record', 'match', 'rating', 'verdict') : null;
            })
            ->filter()
            ->values();

        if ($matches->isEmpty()) {
            return $result;
        }

        unset($result['generated_wording']);
        if ($matches->pluck('verdict')->unique()->count() !== 1) {
            $summary = 'Exact published fact-checks disagree about this claim. No firm factual conclusion was reached; human review is required to resolve the conflicting ratings.';
            $sources = $matches->map(fn (array $row): array => $this->source($row))->all();
            $ids = array_column($sources, 'id');
            $result['sources'] = $this->mergeSources($result['sources'] ?? [], $sources);
            $result['verdict'] = 'CONFLICTING_EVIDENCE';
            $result['assessment_label'] = AssessmentVerdict::label('CONFLICTING_EVIDENCE');
            $result['summary'] = $summary;
            $result['answer_segments'] = [['text' => $summary, 'source_ids' => $ids]];
            $result['key_findings'] = $matches->map(fn ($row) => ($row['record']['name'] ?? 'The publisher').' rated the matching claim '.$row['rating'].'.')->all();
            $result['evidence_strength'] = ['label' => 'Conflicting exact reviews', 'reason' => $summary, 'method' => self::VERSION];
            $result['ui'] = array_replace($result['ui'] ?? [], ['show_source_conflict' => true,
                'show_missing_context' => false, 'missing_context' => null,
                'show_evidence_warning' => true, 'evidence_warning' => $summary, 'verdict_color' => 'amber']);
            $result['exact_fact_check'] = ['status' => 'conflicting', 'source_ids' => $ids, 'version' => self::VERSION];
            if (count($result['claims'] ?? []) === 1) {
                $result['claims'][0]['outcome'] = 'conflicting';
                $result['claims'][0]['requires_context'] = false;
                $result['claims'][0]['context_reason'] = null;
                $result['claims'][0]['relationships'] = array_map(fn ($source) => $source['relationships'][0], $sources);
            }

            return $result;
        }

        $selected = $matches->sortByDesc(fn (array $row): int => (int) data_get($row, 'match.score', 0))->first();
        $record = $selected['record'];
        $rating = $selected['rating'];
        $verdict = $selected['verdict'];
        $publisher = Str::squish((string) ($record['name'] ?? 'the linked fact-check publisher'));
        $reviewedClaim = Str::squish((string) ($record['reviewed_claim'] ?? $record['title'] ?? $submitted));
        $source = $this->source($selected);
        $sourceId = $source['id'];
        $summary = "{$publisher} reviewed the matching claim and rated it {$rating}. The reviewed claim was: {$reviewedClaim}.";

        $result['sources'] = $this->mergeSources($result['sources'] ?? [], [$source]);
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
            'show_source_conflict' => false,
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
            $result['claims'][0]['relationships'] = $source['relationships'];
        }

        return $result;
    }

    private function mergeSources(array $existing, array $sources): array
    {
        return collect($existing)->filter(fn ($source) => is_array($source))->keyBy('id')->replace(collect($sources)->keyBy('id'))->values()->all();
    }

    private function source(array $row): array
    {
        $record = $row['record'];
        $id = (string) ($record['id'] ?? 'fact-check-'.substr(hash('sha256', $record['url']), 0, 12));
        // Store literal retrieved text, never manufacture a quotation from the
        // search API's claim paraphrase. Full text remains in candidate_records.
        $excerpt = mb_substr(trim($record['text']), 0, 1200);
        $explanation = ($record['name'] ?? 'The publisher').' rated the exact reviewed claim '.$row['rating'].'.';

        return Arr::only($record, ['name', 'title', 'url', 'published_at', 'updated_at', 'retrieved_at', 'possible_copy_of']) + [
            'id' => $id, 'type' => 'fact_check', 'used_for' => $explanation,
            'reviewed_claim' => $record['reviewed_claim'], 'published_rating' => $record['review_rating'],
            'excerpt' => $excerpt, 'claim_match' => $row['match'],
            'relationships' => [['source_id' => $id,
                'stance' => match ($row['verdict']) {
                    'TRUE' => 'supporting', 'FALSE' => 'contradicting', default => 'contextual'
                },
                'excerpt' => $excerpt, 'explanation' => $explanation, 'superseded' => false]],
        ];
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
            default => $this->policy->rating($rating),
        };
    }
}
