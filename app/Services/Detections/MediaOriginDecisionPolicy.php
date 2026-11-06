<?php

namespace App\Services\Detections;

/** Reconciles independent detector outputs and input limitations without averaging unlike models. */
class MediaOriginDecisionPolicy
{
    public const VERSION = 'media-origin-agreement-v2';

    public static function apply(array $primary, ?array $supporting, array $input): array
    {
        $minimum = (float) config('services.ai_origin.minimum_class_score', .9);
        $supportMinimum = (float) config('services.ai_origin.supporting_minimum_class_score', .75);
        $primaryScore = self::classScore($primary);
        $reasons = [];
        if (($primary['origin_policy']['inconclusive'] ?? false) === true) {
            $reasons[] = 'primary_detector_abstained';
        }
        if (($input['status'] ?? '') !== 'completed') {
            $reasons[] = 'input_inspection_unavailable';
        }

        if (($primary['status'] ?? '') !== 'completed' || $primaryScore === null || $primaryScore < $minimum) {
            $reasons[] = 'primary_below_decision_threshold';
        }
        if (($input['decision_limited'] ?? false) === true) {
            $reasons[] = 'input_quality_or_composition_limited';
        }

        $ensembleRequired = (bool) config('services.ai_origin.require_detector_agreement', true);
        $supportCompleted = ($supporting['status'] ?? '') === 'completed';
        $supportScore = is_array($supporting) ? self::classScore($supporting) : null;
        $disagreement = $supportCompleted && ($supporting['label'] ?? null) !== ($primary['label'] ?? null);
        if ($ensembleRequired && ! $supportCompleted) {
            $reasons[] = 'supporting_detector_unavailable';
        }
        if ($ensembleRequired && $supportCompleted && ($supportScore === null || $supportScore < $supportMinimum)) {
            $reasons[] = 'supporting_below_decision_threshold';
        }
        if ($disagreement) {
            $reasons[] = 'detector_disagreement';
        }

        $primary['input_assessment'] = $input;
        $primary['detector_disagreement'] = $disagreement;
        if (is_array($supporting)) {
            $primary['supporting_assessments']['image_cnn'] = $supporting;
        }
        $primary['origin_policy'] = array_replace(is_array($primary['origin_policy'] ?? null) ? $primary['origin_policy'] : [], [
            'version' => self::VERSION,
            'minimum_class_score' => $minimum,
            'supporting_minimum_class_score' => $supportMinimum,
            'agreement_required' => $ensembleRequired,
            'inconclusive' => $reasons !== [],
            'reasons' => array_values(array_unique($reasons)),
        ]);

        return $primary;
    }

    private static function classScore(array $finding): ?float
    {
        $raw = $finding['fake_probability'] ?? null;
        if (! in_array($finding['label'] ?? null, ['fake', 'real'], true)
            || ! is_numeric($raw) || ! is_finite((float) $raw) || $raw < 0 || $raw > 1) {
            return null;
        }

        return ($finding['label'] ?? '') === 'fake' ? (float) $raw : 1 - (float) $raw;
    }
}
