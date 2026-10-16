<?php

namespace App\Services\Detections;

use Illuminate\Support\Str;

/** A typed boundary between internal assessment records and a readable result. */
class ResultContract
{
    private const TASKS = ['claim', 'context', 'media', 'manipulation', 'clarification'];

    private const VERDICTS = [
        'TRUE', 'FALSE', 'PARTLY_TRUE', 'MISLEADING', 'UNVERIFIED', 'UNVERIFIABLE',
        'CONFLICTING_EVIDENCE', 'MIXED', 'OUTDATED', 'OUT_OF_CONTEXT', 'MISSING_CONTEXT',
        'SATIRE_PARODY', 'OPINION_NOT_FACT_CHECKABLE', 'AI_GENERATED', 'LIKELY_AI_GENERATED',
        'LIKELY_NOT_AI_GENERATED', 'NO_STRONG_AI_SIGNAL', 'MANIPULATED', 'LIKELY_MANIPULATED',
        'NO_MANIPULATION_DETECTED', 'INCONCLUSIVE', 'ANALYSIS_UNAVAILABLE',
        'UNSUPPORTED_ANALYSIS', 'UNREADABLE_CONTENT', 'CLARIFICATION_NEEDED',
    ];

    public static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $text = trim($value);
        if ($text === '' || str_contains($text, "\0")) {
            return null;
        }
        // A provider envelope, serialized object or fenced response is data, not prose.
        // Parse to classify it; never remove punctuation until it looks readable.
        if (str_starts_with($text, '```') || str_starts_with($text, '{') || str_starts_with($text, '[')) {
            return null;
        }
        for ($i = 0; $i < 2; $i++) {
            try {
                $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                break;
            }
            if (is_array($decoded) || is_object($decoded)) {
                return null;
            }
            if (! is_string($decoded)) {
                break;
            }
            $text = trim($decoded);
        }
        if ($text === '' || str_starts_with($text, '```') || str_starts_with($text, '{') || str_starts_with($text, '[')) {
            return null;
        }

        return Str::squish($text);
    }

    public static function normalize(array $result, int $depth = 0): array
    {
        $errors = [];
        $verdict = $result['verdict'] ?? null;
        if (! is_string($verdict) || ! in_array($verdict, self::VERDICTS, true)) {
            $verdict = 'ANALYSIS_UNAVAILABLE';
            $errors[] = 'invalid_verdict';
        }
        $task = $result['primary_task'] ?? 'claim';
        if (! is_string($task) || ! in_array($task, self::TASKS, true)) {
            $task = 'claim';
            $errors[] = 'invalid_primary_task';
        }
        $result['primary_task'] = $task;
        $result['claim'] = self::text($result['claim'] ?? null) ?? '';
        $result['sources'] = self::sources($result['sources'] ?? null);
        $result['contextual_sources'] = self::sources($result['contextual_sources'] ?? null, true);
        $sourceIds = array_fill_keys(array_column(array_merge($result['sources'], $result['contextual_sources']), 'id'), true);
        $invalidReferences = false;
        $segments = self::segments($result['answer_segments'] ?? null, $sourceIds, $invalidReferences);
        $summary = self::text($result['summary'] ?? null);
        if ($summary === null && $segments !== []) {
            $summary = implode(' ', array_column($segments, 'text'));
        }
        if ($summary === null) {
            $summary = 'The assessment did not return a readable explanation. Retry the analysis to obtain a complete result.';
            $verdict = 'ANALYSIS_UNAVAILABLE';
            $errors[] = 'invalid_explanation';
        }
        if ($invalidReferences && AssessmentVerdict::requiresEvidence($verdict)) {
            $summary = 'The factual finding could not be displayed because its citation relationships did not match the saved reviewed sources. Recheck the submission.';
            $verdict = 'ANALYSIS_UNAVAILABLE';
            $segments = [];
            $errors[] = 'invalid_citation_relationship';
        }
        if ($result['sources'] === [] && AssessmentVerdict::requiresEvidence($verdict)
            && in_array($task, ['claim', 'context'], true)) {
            $summary = 'The saved factual finding has no validated source record. Recheck the submission before relying on it.';
            $verdict = 'ANALYSIS_UNAVAILABLE';
            $segments = [];
            $errors[] = 'missing_decisive_source';
        }
        $result['verdict'] = $verdict;
        $result['assessment_group'] = AssessmentVerdict::group($verdict);
        $result['summary'] = $summary;
        $result['answer_segments'] = $segments;
        $result['key_findings'] = self::texts($result['key_findings'] ?? null);
        $result['limitations'] = self::texts($result['limitations'] ?? null);
        if (in_array('invalid_explanation', $errors, true)) {
            $result['limitations'][] = 'The explanation could not be validated; no conclusion is available for that part of the analysis.';
        }
        $result['score'] = AssessmentVerdict::technical($verdict)
            ? AssessmentScore::unavailable('No percentage can be shown for an incomplete assessment.')
            : self::score($result['score'] ?? null);
        $result['score_display'] = AssessmentScore::display($result['score']);
        $result['confidence'] = null;
        $result['assessment_label'] = AssessmentVerdict::label($verdict);
        $result['ui'] = self::ui($result['ui'] ?? null, $verdict);
        $result['request'] = self::request($result['request'] ?? null, $task);
        $result['official_references'] = self::references($result['official_references'] ?? null);
        $result['method_references'] = self::references($result['method_references'] ?? null);
        $result['context_assessments'] = self::contextFindings($result['context_assessments'] ?? null, $sourceIds);
        foreach (['assessed_at', 'evidence_cutoff', 'original_claim_date'] as $dateField) {
            $result[$dateField] = self::date($result[$dateField] ?? null);
        }
        if ($depth < 2) {
            $result['secondary_assessments'] = self::components($result['secondary_assessments'] ?? null, $depth);
            $claim = $result['claim_assessment'] ?? null;
            if (is_array($claim) && isset($claim['result']) && is_array($claim['result'])) {
                $claim['result'] = self::normalize($claim['result'], $depth + 1);
                $result['claim_assessment'] = $claim;
            }
        } else {
            $result['secondary_assessments'] = [];
            $result['claim_assessment'] = null;
        }
        $findings = array_merge([$result], $result['secondary_assessments']);
        $result['completed_tasks'] = array_values(array_unique(array_column(array_filter($findings,
            fn ($finding) => ! AssessmentVerdict::technical($finding['verdict'])), 'primary_task')));
        $result['processing_status'] = count($result['completed_tasks']) === count(array_unique(array_column($findings, 'primary_task')))
            ? 'completed' : ($result['completed_tasks'] !== [] ? 'partial' : match ($verdict) {
                'UNSUPPORTED_ANALYSIS' => 'unsupported', 'CLARIFICATION_NEEDED' => 'needs_clarification', default => 'failed',
            });
        if (($result['media_assessment']['status'] ?? '') === 'unavailable'
            && ($result['media_assessment']['supporting_assessments']['image_cnn']['status'] ?? '') === 'completed') {
            $result['processing_status'] = 'partial';
        }
        if ($errors !== []) {
            $diagnostics = is_array($result['diagnostics'] ?? null) ? $result['diagnostics'] : [];
            $diagnostics['result_contract_errors'] = array_values(array_unique(array_merge($diagnostics['result_contract_errors'] ?? [], $errors)));
            $result['diagnostics'] = $diagnostics;
        }

        return $result;
    }

    /** A compatible, read-only projection for existing saved reports and JSON clients. */
    public static function forPresentation(array $saved, int $depth = 0): array
    {
        $legacy = ! is_int($saved['schema_version'] ?? null) || $saved['schema_version'] < 4;
        $historicalVerdict = is_string($saved['verdict'] ?? null) && in_array($saved['verdict'], self::VERDICTS, true)
            ? $saved['verdict'] : null;
        $historicalReferences = $legacy ? self::references($saved['sources'] ?? null) : [];
        $result = self::normalize($saved);
        $question = $result['request']['question'] ?? '';
        $selectedMode = $result['request']['selected_mode'] ?? 'auto';
        if ($depth === 0 && $result['primary_task'] === 'media' && $question !== '' && $selectedMode === 'auto'
            && SubmissionTask::primary(['caption_text' => $question, 'analysis_task' => 'auto', 'media_type' => 'image']) === 'claim') {
            // Quarantine an older wrong-task report at the read-only boundary.
            // Its detector output remains in storage, but cannot answer a fact check.
            $result = self::normalize([
                'schema_version' => 4, 'primary_task' => 'claim', 'verdict' => 'ANALYSIS_UNAVAILABLE',
                'summary' => 'This saved report assessed image origin instead of verifying the post. It did not establish whether the news claim is true. A new factual assessment is required.',
                'request' => ['question' => $question, 'tasks' => ['claim']],
                'limitations' => ['The earlier image-model result cannot establish the truth of the submitted news claim.'],
                'score' => AssessmentScore::unavailable('The earlier score evaluated a different task.'),
                'assessed_at' => $result['assessed_at'], 'evidence_cutoff' => $result['evidence_cutoff'],
            ]);
        }
        if ($result['verdict'] === 'CLARIFICATION_NEEDED') {
            // Older task-choice screens remain read-only, but do not ask another
            // question or imply that their skipped analysis actually completed.
            $result['assessment_label'] = 'Inconclusive';
            $result['summary'] = 'The saved assessment did not resolve the requested check. No origin or factual conclusion was established.';
            $result['answer_segments'] = [['text' => $result['summary'], 'source_ids' => []]];
        }
        $public = array_intersect_key($result, array_flip([
            'schema_version', 'verdict_version', 'assessment_group', 'processing_status', 'completed_tasks', 'primary_task', 'verdict',
            'assessment_label', 'claim', 'summary', 'answer_segments', 'score', 'score_display',
            'key_findings', 'limitations', 'sources', 'contextual_sources', 'official_references', 'method_references',
            'context_assessments', 'secondary_assessments', 'ui', 'request', 'assessed_at',
            'evidence_cutoff', 'original_claim_date', 'evidence_strength', 'claim_assessment',
            'media_assessment',
        ]));
        $public['processing_status'] = in_array($public['processing_status'] ?? null, ['queued', 'processing', 'failed', 'completed', 'partial', 'unsupported', 'needs_clarification'], true)
            ? $public['processing_status'] : 'completed';
        $public['request'] = ['question' => $result['request']['question'], 'tasks' => $result['request']['tasks']];
        $public['answer_segments'] = array_values(array_filter(array_map(function (array $segment): ?array {
            $text = self::publicDetail($segment['text']);
            if ($text === null) {
                return null;
            }
            $segment['text'] = $text;

            return $segment;
        }, $public['answer_segments'])));
        $main = self::publicDetail($public['summary']);
        if ($main === null && $public['answer_segments'] !== []) {
            $main = implode(' ', array_column($public['answer_segments'], 'text'));
        }
        if ($main === null) {
            $public['verdict'] = 'ANALYSIS_UNAVAILABLE';
            $public['assessment_label'] = AssessmentVerdict::label('ANALYSIS_UNAVAILABLE');
            $main = 'The saved explanation could not be displayed safely. Recheck the submission for a complete result.';
            $public['answer_segments'] = [];
            $public['score'] = self::publicScore(AssessmentScore::unavailable('No percentage is available for an incomplete assessment.'));
            $public['score_display'] = 'Score unavailable';
            $public['ui'] = self::ui(['show_evidence_warning' => true, 'evidence_warning' => $main], 'ANALYSIS_UNAVAILABLE');
        }
        $public['summary'] = $main;
        if ($public['answer_segments'] === []) {
            $public['answer_segments'] = [['text' => $main, 'source_ids' => []]];
        }
        $public['claim'] = self::assessedClaim($saved, $result['claim']);
        $public['key_findings'] = self::publicDetails($result['key_findings']);
        $public['limitations'] = self::publicDetails($result['limitations']);
        $public['context_assessments'] = array_values(array_filter(array_map(function (array $finding): ?array {
            $explanation = self::publicDetail($finding['explanation']);
            if ($explanation === null) {
                return null;
            }
            $finding['explanation'] = $explanation;

            return $finding;
        }, $public['context_assessments'])));
        foreach (['evidence_warning', 'missing_context', 'source_conflict_message', 'official_verification_notice'] as $notice) {
            $public['ui'][$notice] = self::publicDetail($public['ui'][$notice]);
        }
        $public['sources'] = array_map([self::class, 'publicSource'], $public['sources']);
        $public['contextual_sources'] = array_map([self::class, 'publicSource'], $public['contextual_sources']);
        $public['evidence_strength'] = self::publicStrength($result['evidence_strength'] ?? null);
        $public['media_assessment'] = self::publicMedia($result['media_assessment'] ?? null);
        $claimAssessment = $result['claim_assessment'] ?? null;
        $public['claim_assessment'] = $depth < 2 && is_array($claimAssessment) ? [
            'status' => in_array($claimAssessment['status'] ?? null, ['completed', 'partial', 'unavailable', 'not_requested', 'not_applicable'], true)
                ? $claimAssessment['status'] : 'unavailable',
            'result' => is_array($claimAssessment['result'] ?? null) ? self::forPresentation($claimAssessment['result'], $depth + 1) : null,
        ] : null;
        $public['secondary_assessments'] = $depth < 2
            ? array_map(fn (array $secondary) => self::forPresentation($secondary, $depth + 1), $result['secondary_assessments']) : [];
        $public['score'] = self::publicScore($result['score']);
        if ($legacy) {
            $label = $historicalVerdict ? AssessmentVerdict::label($historicalVerdict) : 'Unrecorded';
            $summary = 'This saved historical report labeled the claim '.$label.'. Its old links do not include validated passage relationships, so they cannot establish that verdict. Recheck the submission for a current source-based assessment.';
            $public['historical_assessment'] = ['verdict' => $historicalVerdict, 'label' => $label];
            $public['historical_references'] = $historicalReferences;
            $public['verdict'] = 'UNVERIFIED';
            $public['assessment_label'] = 'Historical: '.$label;
            $public['summary'] = $summary;
            $public['answer_segments'] = [['text' => $summary, 'source_ids' => []]];
            $public['sources'] = [];
            $public['contextual_sources'] = [];
            $public['context_assessments'] = [];
            $public['key_findings'] = [];
            $public['limitations'] = ['The older result did not save the reviewed passages and source relationships needed to validate its historical finding.'];
            $public['score'] = self::publicScore(AssessmentScore::unavailable('No validated percentage was recorded for this historical assessment.'));
            $public['score_display'] = 'Score unavailable';
            $public['ui'] = self::ui(['show_evidence_warning' => true,
                'evidence_warning' => $public['limitations'][0]], 'UNVERIFIED');
        }

        $public['assessment_group'] = AssessmentVerdict::group($public['verdict']);

        return $public;
    }

    private static function sources(mixed $rows, bool $context = false): array
    {
        if (! is_array($rows) || ! array_is_list($rows)) {
            return [];
        }
        $valid = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! ($url = ReportAnswer::safeUrl($row['url'] ?? null))) {
                continue;
            }
            // Discovery candidates and explicitly unreviewed documents are not evidence.
            if ((isset($row['retrieval_status']) && $row['retrieval_status'] !== 'retrieved')
                || (isset($row['reviewed']) && $row['reviewed'] !== true)
                || in_array($row['assessment_role'] ?? '', ['not_used', 'irrelevant', 'discovered'], true)) {
                continue;
            }
            $id = self::text($row['id'] ?? null) ?? 'saved-'.substr(hash('sha256', $url), 0, 16);
            $title = self::text($row['title'] ?? null) ?? 'Original source';
            $name = self::text($row['name'] ?? null) ?? 'Publisher not recorded';
            $explanation = self::text($row['explanation'] ?? null);
            $usedFor = self::text($row['used_for'] ?? null);
            $valid[] = [
                'id' => $id, 'name' => $name, 'title' => $title, 'url' => $url,
                'type' => self::text($row['type'] ?? null) ?? 'source',
                'published_at' => self::date($row['published_at'] ?? null),
                'updated_at' => self::date($row['updated_at'] ?? null),
                'retrieved_at' => self::date($row['retrieved_at'] ?? null),
                'used_for' => $usedFor ?? $explanation ?? 'The saved report did not record what this source established.',
                'explanation' => $explanation ?? $usedFor ?? 'The saved report did not record what this source established.',
                'excerpt' => self::text($row['excerpt'] ?? null) ?? '',
                'context_only' => $context || ($row['context_only'] ?? false) === true,
                'relationships' => self::relationships($row['relationships'] ?? null),
                'possible_copy_of' => self::text($row['possible_copy_of'] ?? null),
            ];
        }

        return collect($valid)->unique('url')->values()->all();
    }

    private static function relationships(mixed $rows): array
    {
        if (! is_array($rows) || ! array_is_list($rows)) {
            return [];
        }
        $valid = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! in_array($row['stance'] ?? null, ['supporting', 'contradicting', 'insufficient'], true)) {
                continue;
            }
            $excerpt = self::text($row['excerpt'] ?? null);
            $explanation = self::text($row['explanation'] ?? null);
            if ($excerpt === null || $explanation === null) {
                continue;
            }
            $valid[] = ['stance' => $row['stance'], 'excerpt' => $excerpt,
                'explanation' => $explanation, 'superseded' => ($row['superseded'] ?? false) === true];
        }

        return $valid;
    }

    private static function segments(mixed $rows, array $sourceIds, bool &$invalidReferences): array
    {
        if (! is_array($rows) || ! array_is_list($rows)) {
            return [];
        }
        $segments = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! ($text = self::text($row['text'] ?? null))) {
                continue;
            }
            // Inline numeric markers have no authenticated relationship to saved records.
            if (preg_match('/\[\d+\]/u', $text)) {
                continue;
            }
            $ids = is_array($row['source_ids'] ?? null) ? $row['source_ids'] : [];
            foreach ($ids as $id) {
                if (! is_string($id) || ! isset($sourceIds[$id])) {
                    $invalidReferences = true;
                }
            }
            $ids = array_values(array_unique(array_filter($ids,
                fn ($id) => is_string($id) && isset($sourceIds[$id]))));
            $segments[] = ['text' => $text, 'source_ids' => $ids];
        }

        return $segments;
    }

    private static function texts(mixed $rows): array
    {
        if (! is_array($rows) || ! array_is_list($rows)) {
            return [];
        }

        return collect($rows)->map(fn ($item) => self::text($item))->filter()->unique()->values()->all();
    }

    private static function score(mixed $score): array
    {
        if (! is_array($score) || ! in_array($score['type'] ?? null, ['unavailable', 'model_score', 'evidence_strength', 'confidence'], true)) {
            return AssessmentScore::unavailable('No validated percentage was recorded for this assessment.');
        }
        if (($score['type'] ?? '') === 'unavailable') {
            return AssessmentScore::unavailable(self::text($score['explanation'] ?? null) ?? 'No validated percentage was recorded for this assessment.');
        }
        $value = $score['value'] ?? null;
        if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value < 0 || (float) $value > 1
            || ! in_array($score['label'] ?? null, ['Model score', 'Evidence strength', 'Confidence'], true)
            || (($score['type'] ?? '') === 'confidence' && ($score['validation'] ?? '') !== 'calibrated')) {
            return AssessmentScore::unavailable('The saved score has no valid method or value.');
        }
        $score['value'] = (float) $value;
        $score['explanation'] = self::text($score['explanation'] ?? null) ?? 'This percentage describes the recorded method, not overall system accuracy.';
        $score['method_version'] = self::text($score['method_version'] ?? null);
        $score['validation'] = in_array($score['validation'] ?? null,
            ['calibrated', 'uncalibrated', 'provisional_unvalidated'], true) ? $score['validation'] : 'not_validated';
        if ($score['method_version'] === null || ($score['type'] === 'model_score' && $score['validation'] !== 'uncalibrated')
            || ($score['type'] === 'evidence_strength' && $score['validation'] !== 'provisional_unvalidated')) {
            return AssessmentScore::unavailable('The saved percentage has no traceable method and validation status.');
        }

        return $score;
    }

    private static function publicScore(array $score): array
    {
        $public = array_intersect_key($score, array_flip(['type', 'value', 'label', 'validation', 'method_version', 'explanation']));
        $public['explanation'] = self::publicDetail($public['explanation'] ?? null)
            ?? 'No further public explanation of this score was recorded.';

        return $public;
    }

    private static function ui(mixed $ui, string $verdict): array
    {
        $ui = is_array($ui) ? $ui : [];
        $legacyColor = ($verdict === 'MISLEADING' && ($ui['verdict_color'] ?? null) === 'orange')
            || ($verdict === 'OUTDATED' && ($ui['verdict_color'] ?? null) === 'blue');
        $ui['verdict_color'] = $legacyColor ? $ui['verdict_color'] : AssessmentVerdict::color($verdict);
        foreach (['evidence_warning', 'missing_context', 'source_conflict_message', 'official_verification_notice'] as $field) {
            $ui[$field] = self::text($ui[$field] ?? null);
        }
        foreach (['show_evidence_warning', 'show_missing_context', 'show_source_conflict', 'show_official_verification_notice', 'highlight_evidence_cutoff'] as $field) {
            $ui[$field] = ($ui[$field] ?? false) === true;
        }
        if (AssessmentVerdict::technical($verdict)) {
            $ui['show_missing_context'] = false;
            $ui['missing_context'] = null;
        }
        $ui['source_display_order'] = FactCheckEvidencePolicy::SOURCE_ORDER;

        return array_intersect_key($ui, array_flip([
            'verdict_color', 'show_evidence_warning', 'evidence_warning', 'show_missing_context',
            'missing_context', 'show_source_conflict', 'source_conflict_message',
            'show_official_verification_notice', 'official_verification_notice',
            'highlight_evidence_cutoff', 'source_display_order',
        ]));
    }

    private static function request(mixed $request, string $task): array
    {
        $request = is_array($request) ? $request : [];
        $tasks = $request['tasks'] ?? [$task];
        $tasks = is_array($tasks) ? array_values(array_unique(array_filter($tasks,
            fn ($value) => is_string($value) && in_array($value, self::TASKS, true)))) : [$task];

        return ['question' => self::text($request['question'] ?? null) ?? '', 'tasks' => $tasks ?: [$task],
            'selected_mode' => self::text($request['selected_mode'] ?? null) ?? 'auto',
            'resolved_task' => $task, 'resolver_version' => self::text($request['resolver_version'] ?? null)];
    }

    private static function contextFindings(mixed $rows, array $sourceIds): array
    {
        if (! is_array($rows) || ! array_is_list($rows)) {
            return [];
        }
        $findings = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['label'] ?? null)
                || ! in_array($row['label'], self::VERDICTS, true)
                || ! isset($sourceIds[$row['source_id'] ?? ''])
                || ! ($explanation = self::text($row['explanation'] ?? null))) {
                continue;
            }
            $findings[] = ['label' => $row['label'], 'explanation' => $explanation, 'source_id' => $row['source_id']];
        }

        return $findings;
    }

    private static function references(mixed $rows): array
    {
        if (! is_array($rows) || ! array_is_list($rows)) {
            return [];
        }
        $valid = [];
        foreach ($rows as $row) {
            if (is_array($row) && ($url = ReportAnswer::safeUrl($row['url'] ?? null))) {
                $valid[] = ['url' => $url, 'title' => self::text($row['title'] ?? null) ?? 'Official reference',
                    'name' => self::text($row['name'] ?? null) ?? 'Publisher not recorded',
                    'published_at' => self::date($row['published_at'] ?? null)];
            }
        }

        return collect($valid)->unique('url')->values()->all();
    }

    private static function components(mixed $rows, int $depth): array
    {
        if (! is_array($rows) || ! array_is_list($rows)) {
            return [];
        }

        return array_map(fn (array $row) => self::normalize($row, $depth + 1),
            array_values(array_filter($rows, 'is_array')));
    }

    private static function assessedClaim(array $saved, string $fallback): string
    {
        foreach ($saved['claims'] ?? [] as $claim) {
            if (is_array($claim) && ($text = self::text($claim['text'] ?? null))) {
                return Str::limit($text, 600);
            }
        }

        return Str::limit($fallback, 600);
    }

    private static function publicStrength(mixed $strength): array
    {
        $strength = is_array($strength) ? $strength : [];

        return ['label' => self::publicDetail($strength['label'] ?? null) ?? 'Assessment',
            'reason' => self::publicDetail($strength['reason'] ?? null),
            'method' => self::publicDetail($strength['method'] ?? null) ?? 'No separate evidence strength method was recorded.'];
    }

    private static function publicMedia(mixed $media, int $depth = 0): ?array
    {
        if (! is_array($media)) {
            return null;
        }

        return ['status' => in_array($media['status'] ?? null, ['completed', 'unavailable', 'failed', 'unsupported', 'not_requested'], true)
            ? $media['status'] : 'unavailable',
            'media_type' => in_array($media['media_type'] ?? null, ['image', 'video'], true) ? $media['media_type'] : null,
            'label' => in_array($media['label'] ?? null, ['real', 'fake'], true) ? $media['label'] : null,
            'frames_used' => is_int($media['frames_used'] ?? null) ? $media['frames_used'] : null,
            'detector_disagreement' => ($media['detector_disagreement'] ?? false) === true,
            'origin_inconclusive' => ($media['origin_policy']['inconclusive'] ?? false) === true,
            'supporting_assessments' => $depth < 1 && is_array($media['supporting_assessments']['image_cnn'] ?? null)
                ? ['image_cnn' => self::publicMedia($media['supporting_assessments']['image_cnn'], $depth + 1)] : []];
    }

    private static function publicSource(array $source): array
    {
        $source['used_for'] = self::publicDetail($source['used_for'])
            ?? 'The saved report does not explain what this document establishes.';
        $source['explanation'] = self::publicDetail($source['explanation']) ?? $source['used_for'];
        $source['relationships'] = array_values(array_filter(array_map(function (array $row): ?array {
            $explanation = self::publicDetail($row['explanation']);
            if ($explanation === null) {
                return null;
            }
            $row['explanation'] = $explanation;

            return $row;
        }, $source['relationships'])));

        return $source;
    }

    private static function publicDetails(array $details): array
    {
        return collect($details)->map(fn ($detail) => self::publicDetail($detail))->filter()->unique()->values()->all();
    }

    private static function publicDetail(mixed $value): ?string
    {
        $text = self::text($value);
        if ($text === null) {
            return null;
        }
        $lower = Str::lower($text);
        if (str_starts_with($lower, 'analysis stage ')) {
            return 'Part of the evidence review could not be completed.';
        }
        if (str_starts_with($lower, 'discovery coverage was limited because ')) {
            return 'Some source searches could not complete, so coverage may be incomplete.';
        }
        if (str_starts_with($lower, 'the trained article classifier did not contribute a prediction')) {
            return 'The article classifier did not produce a usable finding for this submission.';
        }
        if (str_starts_with($lower, 'the running classifier did not report input coverage')) {
            return 'The article classifier could not confirm how much of the submitted text it assessed.';
        }
        foreach (['regression test', 'fixture', 'token usage', 'stack trace', 'api payload',
            'raw json', 'json schema', 'provider status', 'processing log', 'claim_id', 'source_id',
            'model_stages', 'search_requests', 'page_requests', 'source snapshot',
            'canonical cache', 'app/services/', '\\app\\services\\', 'storage\\app\\',
            '/tmp/', '/var/'] as $internal) {
            if (str_contains($lower, $internal)) {
                return null;
            }
        }
        if (preg_match('/\b[A-Za-z]:[\\\\\/]/', $text)) {
            return null;
        }

        return $text;
    }

    private static function date(mixed $date): ?string
    {
        if (! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}(?:T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2}))?$/D', $date)) {
            return null;
        }
        try {
            new \DateTimeImmutable($date);

            return $date;
        } catch (\Exception) {
            return null;
        }
    }
}
