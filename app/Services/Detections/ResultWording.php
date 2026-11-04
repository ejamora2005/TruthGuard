<?php

namespace App\Services\Detections;

use App\Services\Detections\Evidence\StructuredAnalysis;
use App\Services\Detections\Evidence\SubmittedText;
use Illuminate\Support\Str;

/** Saved presentation copy; never determines a verdict, score, or citation. */
final class ResultWording
{
    public const VERSION = 'result-wording-v5';

    public static function instructions(): string
    {
        return 'Write a ready-to-share answer inside this same evidence-review response; never request a separate wording call. Preserve the validated finding and uncertainty. The verdict label is already displayed separately, so NEVER repeat the verdict label or begin with Yes, No, Misleading, True, False, or a restatement of the submitted claim. explanation: 55-95 words in one clear paragraph of three or four sentences. Directly explain what the evidence establishes, identify the origin of the confusing detail, explain why that detail does not support the claim, and give the strongest current correction when the supplied evidence supports one. For questions using latest, current, today, now, still, or recent, begin with the evaluation cutoff date and do not imply knowledge after it. result_summary: one sentence of 15-30 words explaining only why the evidence fits the assigned verdict; do not repeat the full answer. recommended_action: one short, specific sharing or checking step. Use simple words; no search narration, internal terminology, long quotations, repetition, invented facts, confidence percentages, greetings, or follow-up questions. source_ids lists only supplied sources used in this wording, empty for detector-only findings.';
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
        if (count(SubmittedText::sentences($candidate['result_summary'])) > 1
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
        if (($copy['explanation'] ?? '') === 'Review the displayed verdict and its supporting evidence before relying on this result.') return null;
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
        if (self::inaccessibleSocialLink($result)) {
            $result['generated_wording'] = ['version' => self::VERSION] + [
                'verdict' => $result['verdict'],
                'explanation' => 'TruthGuard could not access the linked social-media post. The post may be private, require sign-in, have been deleted, or be restricted in this location, so its caption and content could not be verified.',
                'result_summary' => 'The original post was not publicly accessible, so there was not enough content for a factual conclusion.',
                'recommended_action' => 'Paste the exact caption or claim, or upload a clear screenshot or copy of the post.',
                'source_ids' => [],
            ];

            return $result;
        }
        if (self::saved($result)) {
            return $result;
        }
        $noSearchEvidence = ($result['diagnostics']['retrieval_status'] ?? null) === 'completed_no_results'
            || (in_array($result['verdict'] ?? '', ['UNVERIFIED', 'UNVERIFIABLE'], true)
                && ($result['sources'] ?? []) === [] && ($result['contextual_sources'] ?? []) === []);
        if ($noSearchEvidence) {
            $result['generated_wording'] = ['version' => self::VERSION] + [
                'verdict' => $result['verdict'],
                'explanation' => 'The available searches did not find reliable evidence that directly confirms or disproves the claim. This does not mean the claim is false; it means TruthGuard cannot reach a supported conclusion with the sources and search access currently available.',
                'result_summary' => 'No reliable matching source was available to support a factual conclusion.',
                'recommended_action' => 'Check the original source, exact wording, date, and official records before sharing the claim.',
                'source_ids' => [],
            ];

            return $result;
        }
        $summary = ResultContract::text($result['summary'] ?? null) ?? '';
        if ($summary === '' || $summary === 'Review the displayed verdict and its supporting evidence before relying on this result.') {
            $summary = implode(' ', array_filter(array_column($result['answer_segments'] ?? [], 'text'),
                fn ($text) => is_string($text) && trim($text) !== ''
                    && trim($text) !== 'Review the displayed verdict and its supporting evidence before relying on this result.'));
            if ($summary === '') $summary = implode(' ', array_filter(array_column($result['sources'] ?? [], 'used_for')));
        }
        // A raw URL in an otherwise useful explanation used to reject the
        // entire paragraph. Links remain in the source cards; keep the facts.
        $summary = trim(preg_replace('~https?://[^\s<>]+|www\.[^\s<>]+~iu', '', strip_tags($summary)));
        $summary = preg_split('/\bA related source dated\b/u', $summary, 2)[0];
        $sentences = collect(SubmittedText::sentences($summary))
            ->reject(fn ($sentence) => preg_match('/^(?:I|We|TruthGuard)\s+(?:searched|browsed|queried|looked for)\b/iu', $sentence))
            ->reject(fn ($sentence) => preg_match('/^(?:Yes|No|True|False|Misleading|Partly true|Unverified|Outdated)\.?$/iu', trim($sentence)))
            ->map(fn ($sentence) => preg_replace('/^I checked the submitted claim:/u', 'The claim being assessed is:', $sentence))
            ->unique()->values();
        $selected = [];
        foreach ($sentences as $sentence) {
            if (count($selected) >= 4 || Str::wordCount(implode(' ', [...$selected, $sentence])) > 155) break;
            $selected[] = $sentence;
        }
        $explanation = implode(' ', $selected);
        if ($explanation === '' || Str::wordCount($explanation) > 160 || mb_strlen($explanation) > 2200) {
            $explanation = ReportAnswer::interpretationSummary($result, $explanation);
        }
        if (Str::wordCount($explanation) > 160 || mb_strlen($explanation) > 2200) {
            $explanation = ReportAnswer::interpretationSummary($result, '');
        }
        $short = self::verdictRationale($result);
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

    /** A short classification rationale for the separate "Why this result?" area. */
    private static function verdictRationale(array $result): string
    {
        return match ($result['verdict'] ?? '') {
            'TRUE' => 'The key details are directly supported by the reviewed evidence.',
            'FALSE' => 'The claim conflicts with the strongest available evidence.',
            'MISLEADING' => 'The claim presents the information without its proper context, creating an inaccurate overall impression.',
            'PARTLY_TRUE', 'MIXED' => 'Some details are supported, but important parts are inaccurate, unsupported, or incomplete.',
            'OUTDATED' => 'The claim relies on information that no longer reflects the latest reviewed evidence.',
            'OUT_OF_CONTEXT' => 'The content is presented outside its original setting, changing how it is understood.',
            'UNVERIFIED', 'UNVERIFIABLE' => 'The available reliable evidence is not sufficient to confirm or refute the claim.',
            'MISSING_CONTEXT' => 'Important details needed to understand the claim accurately are missing.',
            'CONFLICTING_EVIDENCE' => 'Reliable sources disagree, so the available evidence does not support a firm conclusion.',
            'SATIRE_PARODY' => 'The content is presented as satire or parody rather than as a literal factual report.',
            'OPINION_NOT_FACT_CHECKABLE' => 'The submission expresses an opinion or value judgment rather than a verifiable factual claim.',
            default => AssessmentVerdict::meaning((string) ($result['verdict'] ?? '')),
        };
    }

    /** Detect a social post that could not be publicly read; no model call. */
    private static function inaccessibleSocialLink(array $result): bool
    {
        // An unreadable supporting link must not replace an assessment of a
        // separately supplied factual statement with an access-error message.
        if (! in_array($result['verdict'] ?? '', ['MISSING_CONTEXT', 'UNVERIFIABLE', 'UNVERIFIED'], true)
            || ($result['sources'] ?? []) !== []) {
            return false;
        }
        $record = $result['extraction']['source_record'] ?? null;
        if (! is_array($record)) {
            return false;
        }
        $host = Str::lower((string) parse_url((string) ($record['url'] ?? ''), PHP_URL_HOST));
        $social = Str::contains($host, ['facebook.com', 'fb.com', 'instagram.com', 'tiktok.com', 'youtube.com', 'youtu.be']);
        if (! $social) {
            return false;
        }
        $supplied = trim(str_replace((string) ($record['url'] ?? ''), '', (string) ($result['extraction']['submitted_text'] ?? '')));
        if ($supplied !== '' || trim((string) ($result['extraction']['submitted_notes'] ?? '')) !== '') {
            return false;
        }
        if (($record['status'] ?? '') !== 'retrieved') {
            return true;
        }

        $text = Str::lower(Str::squish((string) ($record['text'] ?? $result['extraction']['article_text'] ?? '')));

        return $text === '' || Str::contains($text, [
            'log in to continue', 'login to continue', 'sign in to continue', 'you must log in',
            'this account is private', 'private account', 'content isn\'t available',
            'content is not available', 'post is unavailable', 'video unavailable',
        ]);
    }
}
