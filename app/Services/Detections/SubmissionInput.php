<?php

namespace App\Services\Detections;

class SubmissionInput
{
    public const GUIDE_PROMPTS = [
        'media' => SubmissionTask::MEDIA_INSTRUCTION.'.',
        'claim' => SubmissionTask::CLAIM_INSTRUCTION.'.',
        'context' => SubmissionTask::CONTEXT_INSTRUCTION.'.',
        'sources' => SubmissionTask::SOURCES_INSTRUCTION.'.',
    ];

    public static function hasEvidenceText(?string $text): bool
    {
        // Inspect without rewriting the user's saved claim or its punctuation.
        $text = preg_replace('/\p{Cf}/u', '', (string) $text);
        $text = preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $text);

        $text = SubmissionTask::claimText($text);

        // This is an input check, not a factual verdict or language classifier.
        return preg_match('/[\p{L}\p{N}]/u', $text) === 1;
    }
}
