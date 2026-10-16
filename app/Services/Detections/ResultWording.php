<?php

namespace App\Services\Detections;

use App\Services\Detections\Evidence\StructuredAnalysis;
use App\Services\Detections\Evidence\SubmittedText;
use Illuminate\Support\Str;

/** Saved presentation copy; never determines a verdict, score, or citation. */
final class ResultWording
{
    public const VERSION = 'result-wording-v1';

    public static function instructions(): string
    {
        return 'Write the presentation wording together in this response, using only the supplied evidence and preserving the finding and its uncertainty. explanation: one natural paragraph, approximately 80–120 words, identifying the claim or media-origin question, the relevant evidence and why it supports, contradicts or fails to resolve it. Mention missing context only when material. result_summary: 25–40 words in one or two sentences summarizing that explanation, its main reason and any essential limitation; no new evidence. recommended_action: one short, specific next-step sentence based on the finding. Use simple language, no search narration, internal matching terminology, lengthy quotations, repetition, invented facts or confidence percentages. Use fewer words when evidence is limited; never pad. source_ids lists only supplied sources used in this wording, empty for a detector-only finding.';
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
}
