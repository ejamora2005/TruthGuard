<?php

namespace App\Services\Detections;

use App\Models\Detection;
use App\Services\Detections\Evidence\SubmittedText;
use Illuminate\Support\Str;

/** Assemble the public answer from immutable verdicts, scores and reviewed source IDs. */
class ReportAnswer
{
    public static function safeUrl(mixed $url): ?string
    {
        if (! is_string($url) || $url === '' || ! filter_var($url, FILTER_VALIDATE_URL) || preg_match('/[\x00-\x20\\\\]/', $url)) {
            return null;
        }
        $parts = parse_url($url);
        $host = strtolower(trim($parts['host'] ?? '', '[]'));
        if (! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass'])
            || $host === 'localhost' || str_ends_with($host, '.localhost') || ! str_contains($host, '.')
            || (filter_var($host, FILTER_VALIDATE_IP) && ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))) {
            return null;
        }

        return $url;
    }

    public static function complete(array $result): array
    {
        $result = ResultContract::normalize($result);
        $result = EvidenceVerdictCalibration::apply($result);
        $result['schema_version'] = 4;
        $result['verdict_version'] = AssessmentVerdict::VERSION;
        $result['assessment_label'] = AssessmentVerdict::label($result['verdict']);
        $result['ui']['verdict_color'] = AssessmentVerdict::color($result['verdict']);
        $result['confidence'] = null; // Reserved for externally validated calibration, never an LLM estimate.
        $result['score_display'] = AssessmentScore::display($result['score']);
        if ($result['answer_segments'] !== []) {
            return self::answerQuestion($result);
        }
        $result['answer_segments'] = [['text' => $result['summary'], 'source_ids' => []]];
        $media = in_array($result['primary_task'] ?? '', ['media', 'manipulation', 'clarification'], true);
        if (! $media && $result['sources'] !== []
            && in_array($result['verdict'], ['TRUE', 'FALSE', 'PARTLY_TRUE', 'MIXED', 'CONFLICTING_EVIDENCE'], true)) {
            $claim = collect($result['claims'] ?? [])->first(fn ($row) => ($row['kind'] ?? '') === 'factual');
            $claimText = rtrim(Str::limit(Str::squish((string) ($claim['text'] ?? $result['claim'] ?? '')), 260, '…'), ' .!?');
            $lead = match ($result['verdict']) {
                'TRUE' => 'The reviewed evidence supports the claim that '.$claimText.'.',
                'FALSE' => 'The reviewed evidence contradicts the claim that '.$claimText.'.',
                'PARTLY_TRUE' => 'The submission contains both supported and refuted factual claims.',
                'MIXED' => 'The submission contains both supported and refuted factual claims.',
                default => 'Reviewed sources conflict about the submitted claim.',
            };
            $leadIds = in_array($result['verdict'], ['PARTLY_TRUE', 'MIXED', 'CONFLICTING_EVIDENCE'], true)
                ? array_slice(array_values(array_filter(array_column($result['sources'], 'id'))), 0, 2)
                : array_values(array_filter([($result['sources'][0]['id'] ?? null)]));
            $segments = [['text' => $lead, 'source_ids' => $leadIds]];
            $seen = [];
            $questionTarget = SubmissionTask::questionClaim($result['request']['question'] ?? '');
            $questionFocused = $questionTarget !== '' && count($result['claims'] ?? []) === 1
                && ($claim['text'] ?? '') === $questionTarget;
            foreach ($result['sources'] as $source) {
                $sentences = SubmittedText::sentences(preg_replace('/\[\d+\]/', '', trim((string) ($source['used_for'] ?? ''))));
                $detail = collect($sentences)->first(fn ($sentence) => mb_strlen($sentence) >= 25
                    && ! preg_match('/^(?:this|it) (?:directly )?(?:supports|contradicts|refutes) the claim\b/i', $sentence));
                $detail ??= collect($sentences)->first(fn ($sentence) => mb_strlen($sentence) >= 25);
                if (! $detail) {
                    continue;
                }
                if ($questionFocused) {
                    // A second reviewed sentence can contain the actual outcome
                    // or new date that the first sentence only introduces.
                    $detail = implode(' ', array_slice($sentences, 0, 2));
                }
                $key = Str::lower(Str::squish($detail));
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $detail = self::attributeSource($detail, $source['name'] ?? '');
                $segments[] = ['text' => $detail, 'source_ids' => [$source['id']]];
                if (count($segments) >= 3) {
                    break;
                }
            }
            if (count($segments) === 1) {
                $segments[] = ['text' => implode(' ', array_slice(SubmittedText::sentences($result['summary']), 0, 2)),
                    'source_ids' => array_values(array_filter(array_column($result['sources'], 'id')))];
            }
            $result['answer_segments'] = $segments;
            $result['summary'] = implode(' ', array_column($segments, 'text'));
        }
        $result['score_display'] = AssessmentScore::display($result['score']);

        return self::answerQuestion($result);
    }

    private static function answerQuestion(array $result): array
    {
        $target = SubmissionTask::questionClaim($result['request']['question'] ?? '');
        $facts = array_values(array_filter($result['claims'] ?? [], fn ($claim) => ($claim['kind'] ?? '') === 'factual'));
        if ($result['primary_task'] !== 'claim' || ! in_array($result['verdict'], ['TRUE', 'FALSE'], true)
            || $target === '' || count($facts) !== 1 || ($facts[0]['text'] ?? '') !== $target || $result['sources'] === []) {
            return $result;
        }
        $prefix = $result['verdict'] === 'TRUE' ? 'Yes.' : 'No.';
        if (str_starts_with($result['answer_segments'][0]['text'] ?? '', $prefix)) {
            return $result;
        }
        // Reuse the evidence judge's cited explanation. No new facts, dates,
        // source IDs or certainty estimates are generated at presentation time.
        $segments = $result['answer_segments'];
        if (isset($segments[1]) && $segments[1]['source_ids'] !== []) {
            $segments = array_slice($segments, 1);
            $segments[0]['text'] = $prefix.' '.$segments[0]['text'];
        } else {
            $segments[0]['text'] = $prefix.' '.($result['verdict'] === 'TRUE'
                ? 'The reviewed sources support the event described in your question.'
                : 'The reviewed sources contradict the event described in your question.');
        }
        $result['answer_segments'] = $segments;
        $result['summary'] = implode(' ', array_column($segments, 'text'));

        return $result;
    }

    /** Attribute an already reviewed explanation without another model request. */
    public static function attributeSource(string $explanation, string $publisher): string
    {
        $publisher = Str::limit(Str::squish(strip_tags($publisher)), 80, '');
        if ($publisher === '' || $publisher === 'Publisher not recorded'
            || Str::contains(Str::lower($explanation), Str::lower($publisher))) {
            return $explanation;
        }

        return $publisher.' reports: '.$explanation;
    }

    /** Adapt a saved result for HTML or JSON without updating its historical record. */
    public static function forPresentation(array $saved): array
    {
        return ResultContract::forPresentation($saved);
    }

    /** Older rows may predate canonical JSON; do not infer a factual verdict from a risk label. */
    public static function forLegacyDetection(Detection $detection): array
    {
        $historical = match ($detection->verdict) {
            'real' => 'Likely Real', 'fake' => 'Likely Fake', default => 'Needs Review',
        };
        $summary = 'This saved report recorded a historical '.$historical.' screening label, but it does not contain a validated source-based assessment of the exact claim. Recheck the submission to obtain a current analysis.';
        $saved = [
            'schema_version' => 4, 'processing_status' => 'completed', 'primary_task' => 'claim',
            'verdict' => 'UNVERIFIED', 'claim' => $detection->caption_text ?? $detection->notes ?? '',
            'summary' => $summary, 'key_findings' => [], 'limitations' => [
                'The older report did not save the reviewed passages and citation relationships needed to validate its historical label.',
            ],
            'sources' => [], 'score' => AssessmentScore::unavailable('No validated percentage was saved for this historical report.'),
            'ui' => ['show_evidence_warning' => true, 'evidence_warning' => 'The historical screening label has not been rechecked against current sources.'],
            'request' => ['question' => '', 'tasks' => ['claim'], 'selected_mode' => 'auto', 'resolved_task' => 'claim'],
            'assessed_at' => $detection->analyzed_at?->toIso8601String(),
            'evidence_cutoff' => $detection->analyzed_at?->toIso8601String(),
        ];

        $public = self::forPresentation($saved);
        $public['assessment_label'] = 'Historical: '.$historical;
        $public['historical_assessment'] = ['verdict' => (string) $detection->verdict, 'label' => $historical];

        return $public;
    }
}
