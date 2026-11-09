<?php

namespace App\Services\Detections;

use Illuminate\Support\Str;

/** User reports identify review candidates; they are never ground-truth labels. */
final class PipelineFeedback
{
    public static function options(bool $media = false): array
    {
        $options = [
            'incorrect_extraction' => ['stage' => 'extraction', 'label' => 'Text or image details were misread'],
            'wrong_interpretation' => ['stage' => 'claims', 'label' => 'Claim was misunderstood'],
            'missing_evidence' => ['stage' => 'retrieval', 'label' => 'Relevant evidence was missed'],
            'unsupported_source' => ['stage' => 'evidence', 'label' => 'Source does not support it'],
            'verdict_review' => ['stage' => 'verdict', 'label' => 'Verdict needs review'],
            'unclear_explanation' => ['stage' => 'explanation_citations', 'label' => 'Explanation or citation needs review'],
        ];
        if ($media) {
            $options['media_false_positive'] = ['stage' => 'media_detection', 'label' => 'This is a real image'];
            $options['media_false_negative'] = ['stage' => 'media_detection', 'label' => 'This is AI-generated'];
        }

        return $options;
    }

    public static function details(array $result, string $category, ?string $note = null, ?string $checksum = null): array
    {
        $details = [
            'review_status' => 'unverified_user_report',
            'stage' => self::options(true)[$category]['stage'],
            'note' => $note === null ? null : Str::squish($note),
            'reported_verdict' => $result['verdict'] ?? null,
            'report_sha256' => hash('sha256', json_encode($result, JSON_THROW_ON_ERROR)),
            'assessed_at' => $result['assessed_at'] ?? null,
        ];
        if (in_array($category, ['media_false_positive', 'media_false_negative'], true)) {
            $media = is_array($result['media_assessment'] ?? null) ? $result['media_assessment'] : [];
            $details += [
                'reported_origin' => $category === 'media_false_positive' ? 'real' : 'ai_generated',
                'detector_version' => $media['model_version'] ?? null,
                'detector_label' => $media['label'] ?? null,
                'raw_ai_signal' => $media['fake_probability'] ?? null,
                'decision_threshold' => data_get($media, 'origin_policy.minimum_class_score'),
                'quality_flags' => data_get($media, 'input_assessment.quality_flags', []),
                'media_checksum' => $checksum,
            ];
        }

        return $details;
    }
}
