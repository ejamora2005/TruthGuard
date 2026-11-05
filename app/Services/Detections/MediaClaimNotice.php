<?php

namespace App\Services\Detections;

use Illuminate\Support\Str;

final class MediaClaimNotice
{
    public static function fromExtraction(?array $extraction): array
    {
        if (! is_array($extraction)) return self::none('not_run');
        $status = (string) ($extraction['status'] ?? 'unavailable');
        $text = Str::squish((string) ($extraction['visible_text'] ?? ''));
        if ($text === '') $text = Str::squish((string) ($extraction['reconstructed_text'] ?? ''));
        if ($text === '' || SubmissionTask::isPresetInstruction($text.'.')) return self::none($status);
        $checkable = Str::wordCount($text) >= 4 && (bool) preg_match('/[\pL\pN]/u', $text);

        return ['status' => $checkable ? 'claim_detected' : 'no_checkable_claim',
            'text' => $checkable ? Str::limit($text, 1200, '…') : '',
            'ocr_status' => $status, 'fact_check_requested' => false,
            'message' => $checkable
                ? 'Readable text may contain a factual claim. This report evaluates AI generation only and does not determine whether that claim is true or false.'
                : 'No sufficiently clear factual claim was detected for an optional fact-check.',
        ];
    }

    private static function none(string $status): array
    {
        return ['status' => 'no_checkable_claim', 'text' => '', 'ocr_status' => $status,
            'fact_check_requested' => false, 'message' => 'No sufficiently clear factual claim was detected for an optional fact-check.'];
    }
}
