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
        'UNREADABLE_CONTENT' => 'Unreadable Content', 'CLARIFICATION_NEEDED' => 'Insufficient Input',
    ];

    public static function label(string $verdict): string
    {
        return self::LABELS[$verdict] ?? str_replace('_', ' ', $verdict);
    }

    public static function meaning(string $verdict): string
    {
        return match ($verdict) {
            'TRUE', 'VERIFIED' => 'The reviewed evidence supports the factual claim.',
            'FALSE' => 'The reviewed evidence contradicts the factual claim.',
            'MISLEADING' => 'The content uses real or partly true details in a way that creates a false or inaccurate overall impression.',
            'PARTLY_TRUE', 'PARTIALLY_TRUE' => 'Some parts of the claim are supported, while other important parts are inaccurate or unsupported.',
            'MIXED' => 'The reviewed evidence supports some important parts of the claim and contradicts or cannot verify others.',
            'NEEDS_CONTEXT', 'MISSING_CONTEXT' => 'Important details are missing and could change how the claim is understood.',
            'OUT_OF_CONTEXT' => 'The content is presented in a setting that changes its original meaning.',
            'CONFLICTING_EVIDENCE' => 'Reliable sources disagree, so the claim cannot be settled confidently.',
            'OUTDATED' => 'The claim relies on information that is no longer current.',
            'UNVERIFIED' => 'The available evidence does not establish whether the claim is true or false.',
            'UNVERIFIABLE' => 'There is not enough reliable evidence to confirm or refute the claim.',
            'AI_GENERATED' => 'The media assessment indicates AI generation; this does not verify any factual claim shown with it.',
            'LIKELY_AI_GENERATED' => 'The detector found signals consistent with AI generation, but this is not proof of origin.',
            'LIKELY_NOT_AI_GENERATED' => 'No strong AI-generation signal was detected; this does not prove the media is authentic.',
            'MANIPULATED' => 'The supported analysis found evidence that the media was altered.',
            'LIKELY_MANIPULATED' => 'Possible manipulation signals were found, but the result is not conclusive.',
            'NO_MANIPULATION_DETECTED' => 'No manipulation signal was detected; this does not prove the media was never edited.',
            'INCONCLUSIVE' => 'The available analysis cannot determine a reliable answer.',
            'SATIRE_PARODY' => 'The content appears intended as satire or parody rather than a literal factual claim.',
            'OPINION_NOT_FACT_CHECKABLE' => 'The content is an opinion or value judgment that cannot be verified as a factual claim.',
            'ANALYSIS_UNAVAILABLE' => 'The requested analysis could not be completed, so no result was established.',
            'UNSUPPORTED_ANALYSIS' => 'The requested type of analysis is not supported for this submission.',
            'UNREADABLE_CONTENT' => 'There was not enough readable content to assess the submission.',
            'CLARIFICATION_NEEDED' => 'No safe checkable interpretation could be formed from the available input; the assessment is complete without a follow-up request.',
            default => 'This result describes how the available evidence relates to the submitted content.',
        };
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
