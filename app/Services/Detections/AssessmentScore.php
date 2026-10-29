<?php

namespace App\Services\Detections;

/** Numbers are supplied by a recorded method, never inferred from a legacy risk score. */
class AssessmentScore
{
    public const RUBRIC = 'evidence-strength-v1';

    public static function unavailable(string $reason): array
    {
        return ['type' => 'unavailable', 'value' => null, 'label' => 'Score unavailable',
            'method_version' => null, 'validation' => 'not_validated', 'explanation' => $reason];
    }

    public static function media(array $media): array
    {
        $raw = $media['fake_probability'] ?? null;
        $contract = $media['output_contract'] ?? [];
        if (($media['status'] ?? '') !== 'completed' || ! is_numeric($raw) || ! is_finite((float) $raw) || $raw < 0 || $raw > 1
            || ($contract['activation'] ?? '') !== 'sigmoid' || ($contract['positive_class'] ?? '') !== 'fake'
            || ($contract['binary_exclusive'] ?? false) !== true || ($media['label_mapping'] ?? []) !== ['real', 'fake']
            || ! in_array($media['label'] ?? '', ['real', 'fake'], true)) {
            return self::unavailable('No validated normalized class-output contract was recorded for this prediction.');
        }
        // The video top-k aggregate is a screening statistic, not a whole-video class probability.
        if (($media['media_type'] ?? '') !== 'image') {
            return self::unavailable('Sampled-frame screening has no validated whole-video percentage interpretation.');
        }
        $synthetic = $media['label'] === 'fake';

        $score = ['type' => 'model_score', 'value' => $synthetic ? (float) $raw : 1 - (float) $raw, 'label' => 'Model score',
            'assigned_class' => $media['label'], 'raw_positive_score' => (float) $raw, 'method_version' => 'binary-class-score-v1',
            'validation' => 'uncalibrated', 'explanation' => 'This is the normalized score for the displayed model class, not measured accuracy or a calibrated probability of authenticity.',
            'formula' => $synthetic ? 'synthetic sigmoid output' : '1 - synthetic sigmoid output (two mutually exclusive model classes)',
            'model_version' => $media['model_version'] ?? null, 'config_sha256' => $media['config_sha256'] ?? null];

        return MediaScoreCalibration::apply($score, $media);
    }

    public static function evidence(array $result, array $records): array
    {
        if (! in_array($result['verdict'], ['TRUE', 'FALSE', 'PARTLY_TRUE', 'MISLEADING'], true)
            || (($result['primary_task'] ?? '') === 'context' && ! in_array($result['verdict'], ['TRUE', 'FALSE'], true))) {
            return self::unavailable('A percentage has not been validated for unresolved, conflicting, non-factual, or contextual assessments.');
        }
        $records = collect($records)->keyBy('id');
        $claims = array_values(array_filter($result['claims'] ?? [], fn ($c) => $c['kind'] === 'factual'));
        $components = [];
        foreach ($claims as $claim) {
            if (! in_array($claim['outcome'], ['supported', 'refuted'], true)) {
                return self::unavailable('Not every factual component has resolved evidence.');
            }
            $stance = $claim['outcome'] === 'supported' ? 'supporting' : 'contradicting';
            $rows = array_filter($claim['relationships'], fn ($r) => $r['stance'] === $stance && ! ($r['superseded'] ?? false));
            $independent = [];
            $evaluated = [];
            foreach ($rows as $row) {
                $record = $records->get($row['source_id']);
                if (! $record || ($record['retrieval_status'] ?? '') !== 'retrieved' || empty($row['excerpt'])
                    || ! str_contains($record['text'] ?? '', $row['excerpt']) || in_array(false, $row['matches'] ?? [false], true)) {
                    continue;
                }
                $host = strtolower((string) parse_url($record['url'], PHP_URL_HOST));
                if (empty($record['possible_copy_of']) && empty($record['duplicate_of'])) {
                    // Host diversity is only a conservative proxy; repeated/copy-flagged reports earn no bonus.
                    $independent[$host] = true;
                }
                $primary = $row['basis'] === 'primary_record' && in_array($record['source_type'], ['official_government', 'institution', 'research'], true);
                $evaluated[] = ['source_id' => $row['source_id'], 'exact_relevance' => 1.0, 'direct_passage' => 1.0,
                    'source_suitability' => $primary ? 1.0 : ($row['basis'] === 'fact_check' ? .8 : .6),
                    'temporal_match' => empty($record['published_at']) ? .5 : 1.0,
                    'geographic_match' => ($row['matches']['location'] ?? false) ? 1.0 : 0.0, 'primary_record' => $primary];
            }
            if ($evaluated === []) {
                return self::unavailable('The saved passages do not supply the inputs required by the evidence rubric.');
            }
            foreach ($evaluated as &$row) {
                $row['corroboration'] = $row['primary_record'] || count($independent) >= 2 ? 1.0 : 0.0;
                $row['value'] = .25 * $row['exact_relevance'] + .25 * $row['direct_passage'] + .2 * $row['source_suitability']
                    + .1 * $row['temporal_match'] + .1 * $row['geographic_match'] + .1 * $row['corroboration'];
            }
            unset($row);
            $components[] = ['claim_id' => $claim['id'], 'assigned_outcome' => $claim['outcome'],
                'value' => max(array_column($evaluated, 'value')), 'sources' => $evaluated, 'independent_hosts' => array_keys($independent)];
        }
        if ($components === []) {
            return self::unavailable('No scored factual claims were recorded.');
        }

        return ['type' => 'evidence_strength', 'label' => 'Evidence strength', 'value' => min(array_column($components, 'value')),
            'assigned_verdict' => $result['verdict'], 'method_version' => self::RUBRIC, 'validation' => 'provisional_unvalidated',
            'explanation' => 'A provisional heuristic of the evidence supporting this verdict, not a calibrated probability or system accuracy.',
            'formula' => 'minimum across claims of best passage: 25% relevance + 25% directness + 20% suitability + 10% timing + 10% geography + 10% corroboration',
            'components' => $components, 'coverage' => 1, 'unresolved_contradictions' => 0];
    }

    public static function display(?array $score): string
    {
        $value = $score['value'] ?? null;
        if (! in_array($score['type'] ?? '', ['model_score', 'evidence_strength', 'confidence'], true)
            || ! is_numeric($value) || ! is_finite((float) $value) || $value < 0 || $value > 1
            || (($score['type'] ?? '') === 'confidence' && ($score['validation'] ?? '') !== 'calibrated')) {
            return 'Score unavailable';
        }
        $percent = $value > .995 ? '>99' : ($value > 0 && $value < .005 ? '<1' : (string) round($value * 100));

        $label = ($score['label'] ?? '') === 'Calibrated model score'
            ? 'Assessment score'
            : $score['label'];

        return $label.': '.$percent.'%';
    }

    /** Read-only badge: measured outputs and evidence status have distinct labels. */
    public static function badge(array $saved): array
    {
        $result = ReportAnswer::forPresentation($saved);
        $score = $result['score'];
        $text = self::display($score);
        if ($text !== 'Score unavailable') {
            return ['title' => $text, 'description' => match ($score['type']) {
                'confidence' => 'Calibrated on validation data',
                'evidence_strength' => 'Provisional evidence assessment',
                default => ($score['validation'] ?? '') === 'calibrated_benchmark'
                    ? 'Calibrated on benchmark images' : 'Uncalibrated model output',
            }, 'detail' => $score['explanation'], 'has_percentage' => true, 'type' => $score['type']];
        }
        // An inconclusive verdict can still have a valid raw detector output.
        // Show its AI-class score as a diagnostic, never confidence in that verdict.
        $media = $saved['media_assessment'] ?? null;
        if ($result['primary_task'] === 'media' && $result['verdict'] === 'INCONCLUSIVE' && is_array($media)) {
            $raw = self::media($media);
            if (($raw['type'] ?? '') !== 'unavailable') {
                $raw['type'] = 'model_score';
                $raw['label'] = 'AI-generation score';
                $raw['value'] = (float) $media['fake_probability'];

                return ['title' => self::display($raw), 'description' => 'Uncalibrated; origin remains inconclusive',
                    'detail' => 'The recorded AI-class output, not confidence in an origin verdict. The detector did not separate the classes strongly enough to resolve origin.',
                    'has_percentage' => true, 'type' => 'model_score'];
            }
        }
        $title = match ($result['verdict']) {
            'MISSING_CONTEXT' => 'Missing claim context',
            'UNVERIFIED', 'UNVERIFIABLE' => 'Evidence insufficient',
            'CONFLICTING_EVIDENCE' => 'Evidence conflicting',
            'OPINION_NOT_FACT_CHECKABLE' => 'Not fact-checkable',
            'ANALYSIS_UNAVAILABLE', 'UNSUPPORTED_ANALYSIS', 'UNREADABLE_CONTENT', 'CLARIFICATION_NEEDED' => 'Analysis incomplete',
            default => 'Confidence not available',
        };

        return ['title' => $title, 'description' => AssessmentVerdict::technical($result['verdict'])
            ? 'No completed assessment to score' : 'No calibrated confidence recorded',
            'detail' => $score['explanation'], 'has_percentage' => false, 'type' => 'unavailable'];
    }
}
