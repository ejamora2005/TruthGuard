<?php

namespace App\Services\Detections;

class SubmissionInput
{
    public const GUIDE_PROMPTS = [
        'media' => 'Is this image or video AI-generated or manipulated?',
        'claim' => 'Is the claim in this post true?',
        'sources' => 'Has this claim already been fact-checked, and what ratings did trusted sources give it?',
    ];

    public static function hasEvidenceText(?string $text): bool
    {
        // Inspect without rewriting the user's saved claim or its punctuation.
        $text = preg_replace('/\p{Cf}/u', '', (string) $text);
        $text = preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $text);

        if (SubmissionTask::isPresetInstruction($text)) {
            return false;
        }

        foreach (self::GUIDE_PROMPTS as $prompt) {
            if ($text === $prompt || preg_match('/^'.preg_quote($prompt, '/').'\s/u', $text)) {
                $text = substr($text, strlen($prompt));
                break;
            }
        }

        // This is an input check, not a factual verdict or language classifier.
        return preg_match('/[\p{L}\p{N}]/u', $text) === 1;
    }
}
