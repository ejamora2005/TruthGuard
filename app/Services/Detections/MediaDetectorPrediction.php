<?php

namespace App\Services\Detections;

/** Present the recorded detector class, keeping origin-resolution diagnostics separate. */
class MediaDetectorPrediction
{
    public static function present(array $result): array
    {
        $media = $result['media_assessment'] ?? [];
        $raw = $media['fake_probability'] ?? null;
        if (($result['primary_task'] ?? '') !== 'media' || ($result['verdict'] ?? '') !== 'INCONCLUSIVE'
            || ($media['status'] ?? '') !== 'completed' || ! in_array($media['label'] ?? '', ['fake', 'real'], true)
            || ! is_numeric($raw) || ! is_finite((float) $raw) || $raw < 0 || $raw > 1
            || (($media['media_type'] ?? '') === 'video' && (($media['frames_used'] ?? 0) < 1
                || (($media['video_scope']['face_crop_required'] ?? false) && ($media['video_scope']['face_frames'] ?? 0) < 1)))) {
            return $result;
        }
        $generated = $media['label'] === 'fake';
        $subject = ($media['media_type'] ?? '') === 'video' ? 'sampled video frames' : 'image';
        $summary = 'The primary detector classified the '.$subject.' as '.($generated ? 'likely AI-generated' : 'likely not AI-generated').'. This is the detector prediction, not proof of how the media was created.';
        if (max((float) $raw, 1 - (float) $raw) < (float) config('services.ai_origin.minimum_class_score', .9)) {
            $summary .= ' The detector signal is weak, so treat this prediction cautiously.';
        }
        $summary .= ' This screening does not verify captions or factual claims.';
        if (($media['detector_disagreement'] ?? false) || in_array('detector_disagreement', $media['origin_policy']['reasons'] ?? [], true)) {
            $summary .= ' The supporting detector disagreed; its finding is retained as a limitation.';
        }
        if (($media['input_assessment']['decision_limited'] ?? false)
            || in_array('input_quality_or_composition_limited', $media['origin_policy']['reasons'] ?? [], true)) {
            $summary .= ' Image size, compression, or composite content may affect screening reliability.';
        }
        if (($media['media_type'] ?? '') === 'video') $summary .= ' Only sampled frames were screened; full motion and audio were not verified.';
        $result['media_prediction'] = ['basis' => 'detector_class', 'label' => $media['label'], 'origin_resolution' => 'inconclusive'];
        $result['verdict'] = $generated ? 'LIKELY_AI_GENERATED' : 'LIKELY_NOT_AI_GENERATED';
        $result['assessment_label'] = AssessmentVerdict::label($result['verdict']);
        $result['summary'] = $summary;
        $result['answer_segments'] = [['text' => $summary, 'source_ids' => []]];
        $result['key_findings'] = [$summary];
        $result['score'] = AssessmentScore::media($media);
        $result['score_display'] = AssessmentScore::display($result['score']);
        $result['ui']['verdict_color'] = AssessmentVerdict::color($result['verdict']);
        $result['ui']['show_evidence_warning'] = false;
        $result['ui']['evidence_warning'] = null;
        unset($result['generated_wording']);
        return $result;
    }
}
