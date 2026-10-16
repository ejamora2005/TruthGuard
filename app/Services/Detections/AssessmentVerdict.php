<?php

namespace App\Services\Detections;

/** Stable public labels. Legacy JSON is deliberately not rewritten. */
class AssessmentVerdict
{
    public const VERSION = 'assessment-v5';

    public const FACTUAL = ['TRUE', 'FALSE', 'PARTLY_TRUE', 'MISLEADING', 'UNVERIFIED', 'CONFLICTING_EVIDENCE'];

    public const CONTEXT = ['OUTDATED', 'OUT_OF_CONTEXT', 'MISSING_CONTEXT', 'SATIRE_PARODY', 'OPINION_NOT_FACT_CHECKABLE'];

    public const MEDIA = ['AI_GENERATED', 'LIKELY_AI_GENERATED', 'LIKELY_NOT_AI_GENERATED', 'MANIPULATED', 'LIKELY_MANIPULATED', 'NO_MANIPULATION_DETECTED', 'INCONCLUSIVE'];

    public static function group(string $verdict): string
    {
        return match (true) {
            in_array($verdict, self::FACTUAL, true) => 'factual',
            in_array($verdict, self::CONTEXT, true) => 'context',
            in_array($verdict, self::MEDIA, true) => 'media',
            default => 'processing',
        };
    }

    public static function requiresEvidence(string $verdict): bool
    {
        return in_array($verdict, ['TRUE', 'FALSE', 'PARTLY_TRUE', 'MISLEADING', 'MIXED',
            'CONFLICTING_EVIDENCE', 'OUTDATED', 'OUT_OF_CONTEXT', 'SATIRE_PARODY'], true);
    }

    public const LABELS = [
        'TRUE' => 'True', 'FALSE' => 'False', 'PARTLY_TRUE' => 'Partly True',
        'MISLEADING' => 'Misleading', 'UNVERIFIED' => 'Unverified', 'UNVERIFIABLE' => 'Unverified',
        'MIXED' => 'Mixed', 'CONFLICTING_EVIDENCE' => 'Conflicting Evidence',
        'AI_GENERATED' => 'AI-Generated', 'LIKELY_AI_GENERATED' => 'Likely AI-Generated',
        'LIKELY_NOT_AI_GENERATED' => 'Likely Not AI-Generated', 'MANIPULATED' => 'Manipulated',
        'LIKELY_MANIPULATED' => 'Likely Manipulated', 'NO_MANIPULATION_DETECTED' => 'No Manipulation Detected',
        'INCONCLUSIVE' => 'Inconclusive', 'OUTDATED' => 'Outdated', 'OUT_OF_CONTEXT' => 'Out of Context',
        'MISSING_CONTEXT' => 'Missing Context', 'SATIRE_PARODY' => 'Satire / Parody',
        'OPINION_NOT_FACT_CHECKABLE' => 'Opinion / Not Fact-checkable',
        'ANALYSIS_UNAVAILABLE' => 'Analysis Unavailable', 'UNSUPPORTED_ANALYSIS' => 'Unsupported Analysis',
        'UNREADABLE_CONTENT' => 'Unreadable Content', 'CLARIFICATION_NEEDED' => 'Choose what to check',
    ];

    public static function label(string $verdict): string
    {
        return self::LABELS[$verdict] ?? str_replace('_', ' ', $verdict);
    }

    public static function color(string $verdict): string
    {
        return match ($verdict) {
            'TRUE' => 'green',
            'FALSE', 'MISLEADING', 'AI_GENERATED', 'LIKELY_AI_GENERATED', 'MANIPULATED', 'LIKELY_MANIPULATED' => 'red',
            'PARTLY_TRUE', 'OUTDATED', 'OUT_OF_CONTEXT', 'MISSING_CONTEXT', 'CONFLICTING_EVIDENCE' => 'amber',
            default => 'gray',
        };
    }

    public static function technical(string $verdict): bool
    {
        return in_array($verdict, ['ANALYSIS_UNAVAILABLE', 'UNSUPPORTED_ANALYSIS', 'UNREADABLE_CONTENT', 'CLARIFICATION_NEEDED'], true);
    }
}
