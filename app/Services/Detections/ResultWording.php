<?php

namespace App\Services\Detections;

use App\Services\Detections\Evidence\StructuredAnalysis;
use App\Services\Detections\Evidence\SubmittedText;
use Illuminate\Support\Str;

/** Saved presentation copy; never determines a verdict, score, or citation. */
final class ResultWording
{
    public const VERSION = 'result-wording-v2';

    public static function instructions(): string
    {
        return 'Write a ready-to-share answer inside this same evidence-review response; never request a separate wording call. Preserve the validated finding and uncertainty. explanation: 45–75 words in one short paragraph. The FIRST sentence must directly answer the claim in plain language: Yes, No, Misleading, Not verified, or Not enough context. Then state the decisive reason and what the cited evidence establishes. For questions using latest, current, today, now, still, or recent, begin with the evaluation cutoff date and do not imply knowledge after it. result_summary: 15–25 words giving the direct answer and main reason. recommended_action: one short, specific sharing or checking step. Use simple words; no search narration, internal terminology, long quotations, repetition, invented facts, confidence percentages, greetings, or follow-up questions. source_ids lists only supplied sources used in this wording, empty for detector-only findings.';
    }

    public static function schema(array $verdicts): array
    {
        return StructuredAnalysis::object([
            'verdict' => ['type' => 'string', 'enum' => $verdicts],
            'explanation' => ['type' => 'string'],
            'result_summary' => ['type' => 'string'],
            'recommended_action' => ['type' => 'string'],
            'source_ids' => ['type' => 'array', 'items' => ['type' => 'string']],
        ]);
    }

    public static function accept(array $result, mixed $candidate): ?array
    {
        if (! StructuredAnalysis::valid($candidate, self::schema([$result['verdict'] ?? '']))) {
            return null;
        }
        $allowed = array_merge(array_column($result['sources'] ?? [], 'id'), array_column($result['contextual_sources'] ?? [], 'id'));
        if (array_diff($candidate['source_ids'], $allowed)
            || (AssessmentVerdict::requiresEvidence($result['verdict']) && $candidate['source_ids'] === [])) {
            return null;
        }
        foreach (['explanation' => 160, 'result_summary' => 60, 'recommended_action' => 35] as $key => $limit) {
            $text = ResultContract::text($candidate[$key]);
            if (! $text || Str::wordCount($text) > $limit || mb_strlen($text) > 2200
                || preg_match('~https?://|www\.|<[^>]+>|\b(?:I|We|TruthGuard)\s+(?:searched|browsed|queried|looked for)\b~iu', $text)) {
                return null;
            }
            $candidate[$key] = $text;
        }
        // Reject malformed presentation independently of evidence validation.
        if (count(SubmittedText::sentences($candidate['result_summary'])) > 2
            || count(SubmittedText::sentences($candidate['recommended_action'])) > 1) {
            return null;
        }

        return ['version' => self::VERSION] + $candidate;
    }

    public static function saved(array $result): ?array
    {
        $copy = $result['generated_wording'] ?? null;
        if (! is_array($copy) || ($copy['version'] ?? '') !== self::VERSION) {
            return null;
        }
        unset($copy['version']);

        return self::accept($result, $copy);
    }

    public static function attach(array $result, array $candidates): array
    {
        // Only the latest completed review sees the final evidence set. Do not
        // revive earlier prose after a later review rejected or omitted it.
        $copy = $candidates === [] ? null : self::accept($result, end($candidates));
        if ($copy) {
            $result['generated_wording'] = $copy;
        }

        return $result;
    }

    /**
     * Give a cached assessment fresh presentation copy without another model call.
     * The evidence-backed result remains untouched; only already-approved wording
     * is reframed, and the normal wording contract validates the result again.
     */
    public static function varyForReuse(array $result, string $seed): array
    {
        $saved = self::saved($result);
        if (! $saved) {
            return $result;
        }

        $hasSources = ($saved['source_ids'] ?? []) !== [];
        $introductions = $hasSources ? [
            'Taken together, the cited evidence supports this finding: ',
            'Based on the cited evidence, the assessment is clear: ',
            'The available evidence leads to this conclusion: ',
        ] : [
            'Taken together, the available analysis supports this finding: ',
            'Based on the completed analysis, the assessment is: ',
            'The available analysis leads to this conclusion: ',
        ];
        $index = abs((int) crc32($seed)) % count($introductions);
        $candidate = $saved;
        unset($candidate['version']);
        $candidate['explanation'] = $introductions[$index].lcfirst($candidate['explanation']);

        $accepted = self::accept($result, $candidate);
        if ($accepted) {
            $result['generated_wording'] = $accepted;
        }

        return $result;
    }

    /** Generation-time fallback when no usable final AI copy exists. No requests. */
    public static function ensure(array $result, string $recommendation = ''): array
    {
        if (self::saved($result)) {
            return $result;
        }
        $summary = ResultContract::text($result['summary'] ?? null) ?? '';
        $summary = preg_split('/\bA related source dated\b/u', $summary, 2)[0];
        $sentences = collect(SubmittedText::sentences($summary))
            ->reject(fn ($sentence) => preg_match('/^(?:I|We|TruthGuard)\s+(?:searched|browsed|queried|looked for)\b/iu', $sentence))
            ->map(fn ($sentence) => preg_replace('/^I checked the submitted claim:/u', 'The claim being assessed is:', $sentence))
            ->unique()->values();
        $explanation = $sentences->implode(' ');
        $claim = ResultContract::text($result['claim'] ?? null);
        if (($result['primary_task'] ?? '') !== 'media' && $claim && Str::wordCount($claim) <= 40
            && ! str_contains(Str::lower($explanation), Str::lower($claim))) {
            $explanation = 'The submitted claim is: '.$claim.(preg_match('/[.!?]$/u', $claim) ? ' ' : '. ').$explanation;
        }
        $lead = self::readyLead($result);
        if ($lead !== '' && ! Str::startsWith(Str::lower($explanation), Str::lower($lead))) {
            $explanation = $lead.' '.$explanation;
        }
        if ($explanation === '' || Str::wordCount($explanation) > 160 || mb_strlen($explanation) > 2200) {
            $explanation = ReportAnswer::interpretationSummary($result, $explanation);
        }
        if (Str::wordCount($explanation) > 160 || mb_strlen($explanation) > 2200) {
            $explanation = ReportAnswer::interpretationSummary($result, '');
        }
        $short = ReportAnswer::interpretationSummary($result, $explanation);
        // Retain a complete short sentence; never pay for a fallback rewrite.
        $action = SubmittedText::sentences(ReportAnswer::interpretationAction($short, $recommendation))[0] ?? '';
        $fallbackAction = ($result['primary_task'] ?? '') === 'media'
            ? 'Check the original publication or creation record before relying on this image.'
            : 'Review the original statement and the available source records before sharing.';
        if ($action === '' || Str::wordCount($action) > 35) {
            $action = $fallbackAction;
        }
        $copy = [
            'verdict' => $result['verdict'], 'explanation' => $explanation,
            'result_summary' => $short, 'recommended_action' => $action,
            'source_ids' => array_values(array_unique(array_merge(array_column($result['sources'] ?? [], 'id'),
                array_column($result['contextual_sources'] ?? [], 'id')))),
        ];
        if (! self::accept($result, $copy)) {
            $copy['explanation'] = $copy['result_summary'] = ReportAnswer::interpretationSummary($result, '');
            $copy['recommended_action'] = $fallbackAction;
        }
        $result['generated_wording'] = ['version' => self::VERSION] + $copy;

        return $result;
    }

    /** A concise local lead sentence; this never makes a model request. */
    private static function readyLead(array $result): string
    {
        $question = (string) data_get($result, 'request.question', '');
        $current = preg_match('/\b(?:latest|current|currently|today|now|still|recent)\b/iu', $question) === 1;
        $date = null;
        if ($current && filled($result['evidence_cutoff'] ?? null)) {
            try {
                $date = \Illuminate\Support\Carbon::parse($result['evidence_cutoff'])->format('F j, Y');
            } catch (\Throwable) {
                $date = null;
            }
        }

        $lead = match ($result['verdict'] ?? '') {
            'TRUE' => 'Yes. The reviewed evidence supports the claim.',
            'FALSE' => 'No. The reviewed evidence contradicts the claim.',
            'MISLEADING' => 'Misleading. The claim uses real information but gives the wrong overall impression.',
            'PARTLY_TRUE', 'MIXED' => 'Partly true. Some parts are supported, but other important parts are not.',
            'OUTDATED' => 'Outdated. The claim no longer matches the latest reviewed information.',
            'OUT_OF_CONTEXT' => 'Out of context. The content is presented with the wrong setting, date, or meaning.',
            'UNVERIFIED' => 'Not verified. The reviewed evidence does not establish that the claim is true.',
            'MISSING_CONTEXT' => 'Not enough context. The available information does not support a reliable answer.',
            'CONFLICTING_EVIDENCE' => 'Unresolved. Reliable sources disagree, so no firm conclusion is supported.',
            default => '',
        };

        return $date && $lead !== '' ? 'As of '.$date.', '.lcfirst($lead) : $lead;
    }
}
