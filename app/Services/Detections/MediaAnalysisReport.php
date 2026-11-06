<?php

namespace App\Services\Detections;

class MediaAnalysisReport
{
    public static function inconclusiveOriginSummary(array $media): string
    {
        $subject = ($media['media_type'] ?? '') === 'video' ? 'video' : 'image';
        $raw = $media['fake_probability'] ?? null;
        $minimum = (float) ($media['origin_policy']['minimum_class_score']
            ?? config('services.ai_origin.minimum_class_score', .9));
        $hasScore = is_numeric($raw) && is_finite((float) $raw) && $raw >= 0 && $raw <= 1;
        $leaning = ($media['label'] ?? '') === 'fake' ? 'AI-generated' : 'not AI-generated';
        $classScore = $hasScore && ($media['label'] ?? '') === 'real' ? 1 - (float) $raw : (float) $raw;
        $reasons = $media['origin_policy']['reasons'] ?? [];
        $basis = $hasScore
            ? 'The detector leaned '.$leaning.' with a recorded AI-signal score of '.round((float) $raw * 100).'%. '
            : 'The detector did not provide a usable AI-signal score. ';
        if (! $hasScore || $classScore < $minimum || in_array('primary_below_decision_threshold', $reasons, true)) {
            $basis .= 'The prediction did not reach the required '.round($minimum * 100).'% class-decision threshold. ';
        }
        if (in_array('detector_disagreement', $reasons, true)) {
            $basis .= 'The independent detectors produced different class findings. ';
        } elseif (in_array('supporting_detector_unavailable', $reasons, true)) {
            $basis .= 'The independent supporting detector did not return a usable finding. ';
        } elseif (in_array('supporting_below_decision_threshold', $reasons, true)) {
            $basis .= 'The independent supporting detector did not clear its decision threshold. ';
        }
        if (in_array('input_quality_or_composition_limited', $reasons, true)) {
            $basis .= 'The uploaded file is compressed, small, or appears to be a screenshot/composite, so the visible interface, text, and embedded image may affect screening reliability. ';
        }
        if (in_array('primary_detector_abstained', $reasons, true)) {
            $basis .= 'The primary detector withheld an origin decision. ';
        }
        if (in_array('input_inspection_unavailable', $reasons, true)) {
            $basis .= 'The original file could not be inspected reliably. ';
        }

        return $basis
            ."The {$subject}'s origin therefore remains inconclusive: this result does not establish either AI generation or human origin. "
            .'The displayed score is an uncalibrated model signal, not the probability that the media is AI-generated and not proof of authenticity. '
            .'Check the original file, first publication, or a reliable creation record before relying on the media. '
            .'This screening also does not verify any caption or factual claim shown in the upload.';
    }

    public static function unsupportedTask(string $task, array $result): array
    {
        $clarify = $task === 'clarification';
        $summary = $clarify ? 'The saved assessment did not resolve the requested check. No origin or factual conclusion was established.'
            : 'TruthGuard does not currently have a supported detector for general image editing or manipulation. Its AI-generation score cannot establish whether this upload was edited.';
        $result['primary_task'] = $task;
        $result['verdict'] = $clarify ? 'CLARIFICATION_NEEDED' : 'UNSUPPORTED_ANALYSIS';
        $result['assessment_label'] = AssessmentVerdict::label($result['verdict']);
        $result['score'] = AssessmentScore::unavailable($summary);
        $result['summary'] = $summary;
        $result['key_findings'] = [];
        $result['evidence_strength'] = ['label' => $clarify ? 'Task unclear' : 'Unavailable', 'method' => 'AI-generation screening is not a general editing detector.'];
        $result['ui']['show_evidence_warning'] = ! $clarify;
        $result['ui']['evidence_warning'] = $clarify ? null : $summary;

        return $result;
    }

    public static function result(array $media, array $claim): array
    {
        $completed = ($media['status'] ?? '') === 'completed';
        $additional = $media['supporting_assessments']['image_cnn'] ?? null;
        $partial = ! $completed && ($additional['status'] ?? '') === 'completed';
        // Enforce selective classification here as well as in the classifier.
        // Saved, mocked, retried, or older detector payloads may omit the
        // origin_policy field; a borderline raw output must never become a
        // public AI-generated/authenticity verdict merely because it is on one
        // side of the model's internal 0.5 decision boundary.
        $minimumClassScore = (float) config('services.ai_origin.minimum_class_score', .9);
        $rawAiScore = $media['fake_probability'] ?? null;
        $derivedUncertain = $completed
            && (! is_numeric($rawAiScore) || ! is_finite((float) $rawAiScore) || $rawAiScore < 0 || $rawAiScore > 1
                || ! in_array($media['label'] ?? null, ['fake', 'real'], true)
                || (($media['label'] ?? '') === 'fake' ? (float) $rawAiScore : 1 - (float) $rawAiScore) < $minimumClassScore);
        $uncertain = $completed && (($media['origin_policy']['inconclusive'] ?? false) === true || $derivedUncertain);
        if ($completed) {
            $media['origin_policy'] = array_replace([
                'version' => 'origin-selective-v1',
                'minimum_class_score' => $minimumClassScore,
            ], is_array($media['origin_policy'] ?? null) ? $media['origin_policy'] : [], [
                'inconclusive' => $uncertain,
            ]);
        }
        $label = match (true) {
            ! $completed => 'Analysis Unavailable',
            ($media['label'] ?? '') === 'fake' => 'Likely AI-Generated',
            ($media['label'] ?? '') === 'real' => 'Likely Not AI-Generated',
            default => 'Inconclusive',
        };
        $verdict = ! $completed ? 'ANALYSIS_UNAVAILABLE' : match ($media['label'] ?? '') {
            'fake' => 'LIKELY_AI_GENERATED', 'real' => 'LIKELY_NOT_AI_GENERATED', default => 'INCONCLUSIVE',
        };
        if ($partial || $uncertain) {
            $verdict = 'INCONCLUSIVE';
            $label = 'Inconclusive';
        }
        $subject = ($media['media_type'] ?? '') === 'video' ? 'video' : 'image';
        $summary = ! $completed ? ucfirst($subject).' analysis could not be completed. Please try uploading the original '.$subject.' again.'
            : match ($media['label'] ?? '') {
                'fake' => "The trained {$subject} detector found signals consistent with AI generation in the submitted upload. This supports a likely AI-generated finding, but does not prove how the {$subject} was made.",
                'real' => "The trained {$subject} detector's score was below its AI-generation threshold, so its finding is likely not AI-generated. This does not prove the {$subject} is authentic or unedited.",
                default => 'The detector did not provide a conclusive screening label. No authenticity conclusion can be drawn.',
            };
        if (($media['media_type'] ?? '') === 'video' && $completed) {
            $limitationsNote = 'Video screening covers '.($media['frames_used'] ?? 0).' sampled frames, not every frame or the audio. The video model focuses on faces and does not detect all forms of editing.';
        }
        $limitations = [
            'The class score is uncalibrated; it is not a measured probability or a confidence percentage.',
            'This model screens for synthetic-media signals. It does not establish provenance, verify claims, or detect every form of editing or manipulation.',
            'Performance on stylized illustrations and unfamiliar generators has not been established in this deployment; a low score does not rule out AI generation.',
            'The supplied model configuration documents its labels and threshold, but a reproducible threshold-validation dataset and full training preprocessing record are not bundled.',
        ];
        if (isset($limitationsNote)) {
            $limitations[] = $limitationsNote;
        }

        $score = AssessmentScore::media($media);
        if ($uncertain) {
            $score = AssessmentScore::unavailable('The model output is insufficiently separated to support an origin finding.');
        }
        if ((($score['type'] ?? '') === 'confidence' && ($score['validation'] ?? '') === 'calibrated')
            || (($score['type'] ?? '') === 'model_score' && ($score['validation'] ?? '') === 'calibrated_benchmark')) {
            $limitations[0] = $score['explanation'];
        }
        if ($completed) {
            $summary .= $subject === 'image'
                ? ' Performance on stylized illustrations and unfamiliar generators is uncertain; creation records or an original publication would help confirm the origin.'
                : ' Only '.($media['frames_used'] ?? 0).' sampled frames were screened; the remaining frames and audio were not assessed.';
        }
        if (($media['detector_disagreement'] ?? false) === true) {
            $summary .= ' The additional image classifier gave a different finding, so check the original creation record before relying on this result.';
            $limitations[] = 'The AI-origin detector and the additional image classifier disagree. Both findings are retained; their scores were not averaged.';
        }
        if ($partial) {
            $summary = 'The AI-origin detector could not complete. Additional image screening '.(($additional['label'] ?? '') === 'fake'
                ? 'flagged possible synthetic content' : 'did not flag synthetic content').', but it cannot establish the origin on its own. Retry the analysis or check the original creation record.';
            $limitations[] = 'The primary origin analysis is incomplete; a complete origin finding is not available.';
        }
        if ($uncertain) {
            $summary = self::inconclusiveOriginSummary($media);
            $limitations[] = 'An uncertain classification cannot establish whether an image was made by a person or generated by AI.';
            $limitations = array_values(array_unique(array_merge($limitations, $media['input_assessment']['limitations'] ?? [])));
        }
        $additionalFinding = is_array($additional) ? match ($additional['label'] ?? '') {
            'fake' => 'The additional image classifier flagged possible synthetic content.',
            'real' => 'The additional image classifier did not flag synthetic content; that is not proof of human origin.',
            default => 'The additional image classifier did not complete.',
        } : null;

        return [
            'schema_version' => 4, 'primary_task' => 'media', 'verdict' => $verdict, 'assessment_label' => $label, 'score' => $score,
            'claim' => '', 'confidence' => null, 'summary' => $summary,
            'evidence_strength' => ['label' => $completed ? 'Screening complete' : 'Unavailable', 'method' => $limitations[0]],
            'media_assessment' => $media, 'claim_assessment' => $claim,
            'key_findings' => array_values(array_filter([$completed ? $summary : null, $additionalFinding])),
            'method_references' => ! empty($media['method_reference']) ? [
                ['url' => $media['method_reference'], 'name' => 'Published model documentation',
                    'title' => 'AI-origin detector: training scope and limitations'],
            ] : [],
            'sources' => [], 'limitations' => $limitations, 'assessed_at' => $media['assessed_at'] ?? now()->utc()->toIso8601String(),
            'evidence_cutoff' => now()->utc()->toIso8601String(),
            'ui' => ['show_evidence_warning' => ! $completed, 'evidence_warning' => ! $completed ? $summary : null,
                'show_missing_context' => false, 'missing_context' => null, 'show_source_conflict' => false, 'source_conflict_message' => null,
                'show_official_verification_notice' => false, 'official_verification_notice' => null, 'highlight_evidence_cutoff' => false,
                'verdict_color' => $verdict === 'LIKELY_AI_GENERATED' ? 'red' : 'gray'],
        ];
    }
}
