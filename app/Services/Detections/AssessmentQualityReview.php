<?php

namespace App\Services\Detections;

use Illuminate\Support\Str;

/** Produce a deterministic quality gate and human-review advisory for an assessment. */
class AssessmentQualityReview
{
    private const REVIEW_VERDICTS = [
        'UNVERIFIED', 'UNVERIFIABLE', 'CONFLICTING_EVIDENCE', 'MIXED', 'MISSING_CONTEXT',
        'INCONCLUSIVE', 'ANALYSIS_UNAVAILABLE', 'UNSUPPORTED_ANALYSIS', 'UNREADABLE_CONTENT',
        'CLARIFICATION_NEEDED',
    ];

    public static function evaluate(array $result): array
    {
        $verdict = (string) ($result['verdict'] ?? 'ANALYSIS_UNAVAILABLE');
        $segments = is_array($result['answer_segments'] ?? null) ? $result['answer_segments'] : [];
        $sources = is_array($result['sources'] ?? null) ? $result['sources'] : [];
        $requiresEvidence = AssessmentVerdict::requiresEvidence($verdict)
            && in_array($result['primary_task'] ?? 'claim', ['claim', 'context'], true);
        $factualSegments = array_values(array_filter($segments, fn ($row) => is_array($row)
            && filled($row['text'] ?? null)));
        $citedSegments = array_values(array_filter($factualSegments, fn ($row) => is_array($row['source_ids'] ?? null)
            && $row['source_ids'] !== []));
        $coverage = $factualSegments === [] ? ($requiresEvidence ? 0 : 100)
            : (int) round((count($citedSegments) / count($factualSegments)) * 100);

        $reasons = [];
        if (in_array($verdict, self::REVIEW_VERDICTS, true)) {
            $reasons[] = 'The automated assessment did not reach a single fully resolved conclusion.';
        }
        if ($requiresEvidence && $sources === []) {
            $reasons[] = 'No decisive reviewed source is attached to the factual conclusion.';
        }
        if ($requiresEvidence && $coverage < 100) {
            $reasons[] = 'At least one factual explanation segment is not linked to a reviewed source.';
        }
        if (($result['processing_status'] ?? 'completed') !== 'completed') {
            $reasons[] = 'One or more requested analysis stages did not complete.';
        }
        if (($result['verdict_audit']['status'] ?? 'passed') === 'failed') {
            $reasons[] = 'The independent verdict-consistency audit found a mismatch.';
        }
        foreach ($result['editorial_review']['reasons'] ?? [] as $reason) {
            if (is_string($reason) && $reason !== '') $reasons[] = $reason;
        }

        $subject = Str::lower(Str::squish(implode(' ', array_filter([
            $result['claim'] ?? '', $result['request']['question'] ?? '',
        ], 'is_string'))));
        if ($subject !== '' && preg_match('/\b(?:election|vote|ballot|candidate|president|senator|congress|court|arrest|crime|shoot|kill|death|disease|medicine|vaccine|health|disaster|earthquake|typhoon|financial|investment|bank)\b/u', $subject)) {
            $reasons[] = 'The claim concerns a consequential public-interest topic where human review is advisable before action or publication.';
        }

        $reasons = array_values(array_unique($reasons));

        return [
            'status' => $reasons === [] ? 'passed' : 'review_recommended',
            'human_review_recommended' => $reasons !== [],
            'reasons' => $reasons,
            'citation_coverage_percent' => $coverage,
            'reviewed_source_count' => count($sources),
        ];
    }
}
