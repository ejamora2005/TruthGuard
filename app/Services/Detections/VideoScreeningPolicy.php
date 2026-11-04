<?php

namespace App\Services\Detections;

/** A face-screening negative cannot establish the origin of an entire video. */
class VideoScreeningPolicy
{
    public const VERSION = 'video-scope-v1';

    public static function apply(array $result): array
    {
        $media = $result['media_assessment'] ?? [];
        if (($result['primary_task'] ?? '') !== 'media' || ($media['media_type'] ?? '') !== 'video'
            || ! in_array($result['verdict'] ?? '', ['LIKELY_NOT_AI_GENERATED', 'NO_STRONG_AI_SIGNAL', 'LIKELY_AI_GENERATED', 'INCONCLUSIVE'], true)) {
            return $result;
        }
        $noFaces = ($media['video_scope']['face_crop_required'] ?? false) && ($media['video_scope']['face_frames'] ?? 0) === 0;
        $negative = in_array($result['verdict'], ['LIKELY_NOT_AI_GENERATED', 'NO_STRONG_AI_SIGNAL'], true)
            || ($media['label'] ?? '') === 'real';
        $noFrames = ($media['frames_used'] ?? 0) < 1;
        if (! $negative && ! $noFaces && ! $noFrames) return $result;

        $summary = $noFaces
            ? 'No usable human faces were found in the sampled frames. The deployed video detector is designed for face screening, so it cannot determine the origin of this clip. Animal, animated, and other non-face content needs a detector validated for that content.'
            : ($noFrames ? 'No usable video frames were recorded for this assessment. The video origin could not be determined.'
                : 'The sampled-frame detector did not flag strong synthetic signals, but this does not establish that the video is not AI-generated. Its human-face screening can miss generated scenes, animals, animation, and newer generators. The video origin remains inconclusive.');
        if (! $noFrames) $summary .= ' The detector screened '.(int) $media['frames_used'].' sampled frames.';
        $result['evidence_strength'] = ['label' => 'Insufficient video evidence', 'method' => 'Human-face frame screening cannot establish whole-video origin.'];
        $summary .= ' The complete motion and audio were not verified. Check the original upload and creation records before relying on the clip.';
        $result['verdict'] = 'INCONCLUSIVE';
        $result['assessment_label'] = 'Inconclusive';
        $result['summary'] = $summary;
        $result['answer_segments'] = [['text' => $summary, 'source_ids' => []]];
        $result['key_findings'] = [$summary];
        $result['score'] = AssessmentScore::unavailable('Sampled face screening does not establish whole-video origin.');
        $result['video_policy_version'] = self::VERSION;
        unset($result['generated_wording']);
        $result['ui']['verdict_color'] = 'gray';
        $result['ui']['show_evidence_warning'] = true;
        $result['ui']['evidence_warning'] = 'A low detector score is not proof that a video was filmed rather than generated.';
        return $result;
    }
}
