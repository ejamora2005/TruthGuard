<?php

namespace App\Services\Detections\Evidence;

use App\Services\Detections\FactCheckEvidencePolicy;
use App\Services\Detections\SubmissionTask;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class ClaimPlanner
{
    public function __construct(private readonly StructuredAnalysis $model) {}

    public function plan(string $text, array $submission = []): array
    {
        $text = SubmittedText::paragraphs($text);
        $target = SubmissionTask::questionClaim($submission['question'] ?? '');
        $target = $target !== '' && str_contains($text, $target) ? $target : '';
        $strings = ['type' => 'array', 'items' => ['type' => 'string']];
        $schema = StructuredAnalysis::object([
            'claims' => ['type' => 'array', 'items' => StructuredAnalysis::object([
                'quote' => ['type' => 'string'],
                'kind' => ['type' => 'string', 'enum' => ['factual', 'opinion', 'question', 'prediction', 'rhetorical', 'unclear']],
                'entities' => $strings, 'dates' => $strings, 'locations' => $strings, 'quantities' => $strings, 'qualifiers' => $strings,
                'requires_context' => ['type' => 'boolean'], 'context_reason' => ['type' => 'string'],
                'searches' => $strings,
                'references' => ['type' => 'array', 'items' => StructuredAnalysis::object([
                    'phrase' => ['type' => 'string'], 'referent' => ['type' => 'string'], 'context_quote' => ['type' => 'string'],
                ])],
            ])],
            'original_claim_date' => ['type' => ['string', 'null']],
            'date_quote' => ['type' => 'string'],
        ]);
        if ($target !== '') {
            // Bind the plan to the user's actual question. Poster dates, logos
            // and headings supply context, not competing factual assertions.
            $schema['properties']['claims']['maxItems'] = 1;
            $schema['properties']['claims']['items']['properties']['quote']['enum'] = [$target];
            $schema['properties']['claims']['items']['properties']['entities']['items']['description'] = 'Only entities required by the question or its resolved subject. Use literal visible forms. Do not include a publisher, logo, building label or institution merely because it appears on the poster.';
            $schema['properties']['claims']['items']['properties']['qualifiers']['items']['description'] = 'Material factual constraints only. Words such as really or actually in a verification question do not constrain the event.';
            $schema['properties']['claims']['items']['properties']['references']['items']['properties']['referent']['description'] = 'An exact literal substring of original_text identifying the referenced subject. Copy the visible acronym or name; never expand or paraphrase it.';
            $schema['properties']['claims']['items']['properties']['references']['items']['properties']['context_quote']['description'] = 'An exact contiguous substring of original_text containing the literal referent. Preserve original whitespace.';
        }
        $data = $text === '' ? null : $this->model->ask('claim_decomposition',
            ($target !== '' ? 'QUESTION-FOCUSED PLAN: The user asks about the candidate proposition in question_target. Return exactly that quote as the single analysis target, using the WHOLE supplied content to resolve its subject and scope. A question about whether an event occurred is a checkable candidate, even when the poster itself is only a topic heading. Do not replace it with the headline, publisher, watermark, isolated year, Roman numeral or other decorative text. Those are context only. Bind pronouns and generic subjects such as "the election" to explicitly visible event names through references and metadata. Ground every binding in an exact context_quote; keep visible years in scoped searches without assuming they are the posting date or the new scheduled date. Do not mark a resolved event question as requiring context merely because the poster heading does not independently assert an outcome. An opinion or value judgment remains non-factual. ' : '')
            .'Read the WHOLE post, including headings, captions, visible dates and body text. Extract checkable factual assertions as exact contiguous quotes even when they are short headlines or announcements without a grammatically complete sentence. OCR line breaks are not claim boundaries, but separate visual regions can be: do not attach an isolated logo, publisher mark or watermark to a headline or require an irrelevant brand acronym to be explained. The raw visible_text remains available in submission. Never separate dependent clauses (because, due to, which, etc.) from their subject. Keep names, quantities, dates, attribution and qualifiers. Do not convert a topic heading about a proposed action into a claim that the action was finalized; identify the exact unresolved proposal, enactment or decision status when that difference matters. A statement that an authority warned of a possible event is a checkable attribution, not a prediction of a certain event. Questions and rhetorical headings provide context, not extra claims when factual statements follow. For a rhetorical or genuinely unclear heading with a named event, give searches for that event and its specific official records before requesting missing context; do not invent an implied outcome. Resolve references (it, the province, this agency, etc.) from the entire post; return references with the original phrase, its referent, and a verbatim context_quote containing that referent. Include resolved entities/locations in the claim metadata and searches without changing the quote. Only requires_context when a material detail is absent from the WHOLE post; grammatical incompleteness or an unknown publication date alone does not prevent searching an otherwise specific assertion. State the material missing detail in context_reason, never simply "it is a heading." Never infer a certain prediction from a warning about a possibility. Return at most '.config('fact_check.max_claims').' claims. Give four short complementary keyword searches, roughly 3 to 6 informative terms: subject/location/event, a broader subject/location query, alternate faithful wording, and contrary records. Do not use a full sentence with stop words as a search. A specific event proposition preserved from a verification question is a candidate factual assertion to research, even if its wording contains a spelling error or lacks a full sentence. Keep its original quote; use faithful name or wording variants only in searches. Do not ask the user another task-selection question. Never invent events or dates. An original claim date is its posting date, NOT an event date; supply its exact date_quote or null.',
            ['original_text' => $text, 'question_target' => $target ?: null, 'submission' => $submission,
                'source_guidance' => 'Choose topic-appropriate authorities, not merely official domains: Philippine weather PAGASA, earthquakes/volcanoes/tsunamis PHIVOLCS, elections COMELEC, court rulings the deciding court, laws enacted legal records. For other countries use the responsible local authority. Search specific records and contrary evidence, never treat a generic homepage as proof. A date/location/caption question is an instruction; check relevant assertions from the submitted content, not the question itself.',
                'scope_note' => 'Assess factual wording only. Media-origin analysis is a separate component. When media_attached is true, do not claim no image or video was supplied; describe any unavailable textual context precisely.'], $schema);
        $claims = [];
        foreach ($data['claims'] ?? [] as $row) {
            if ($target !== '' && $row['quote'] !== $target) {
                continue;
            }
            if (trim($row['quote']) === '' || ! str_contains($text, $row['quote'])) {
                continue;
            }
            $row['id'] = 'c'.(count($claims) + 1);
            $row['text'] = $row['quote'];
            if ($target !== '') {
                $row['current_status_question'] = $this->isCurrentStatus($submission['question'] ?? '');
            }
            foreach (['entities', 'dates', 'locations', 'quantities', 'qualifiers'] as $field) {
                $row[$field] = array_values(array_filter($row[$field], fn ($part) => $part !== '' && Str::contains(Str::lower($text), Str::lower($part))));
            }
            $row['searches'] = array_values(array_unique(array_filter(array_map(fn ($q) => Str::limit(trim($q), 180, ''), $row['searches']))));
            $row['references'] = array_values(array_filter($row['references'] ?? [], fn ($r) => trim($r['phrase'] ?? '') !== '' && str_contains($row['quote'], $r['phrase'])
                && trim($r['referent'] ?? '') !== '' && Str::contains(Str::lower($r['context_quote'] ?? ''), Str::lower($r['referent']))
                && str_contains($text, $r['context_quote'])));
            // Preserve a complete sentence even if a model returns its subordinate clauses.
            if ($row['kind'] === 'factual') {
                foreach (SubmittedText::sentences($text) as $sentence) {
                    if (str_contains($sentence, $row['quote'])) {
                        if ($sentence !== $row['quote']) {
                            $row['extracted_fragment'] = $row['quote'];
                            $row['text'] = $row['quote'] = $sentence;
                            if (preg_match('/fragment|incomplete (?:sentence|clause)|standalone|subject.*missing/iu', $row['context_reason'])) {
                                $row['requires_context'] = false;
                                $row['context_reason'] = '';
                            }
                        }
                        break;
                    }
                }
            }
            $row['submission_context'] = $text;
            $claims[] = $row;
        }
        $claims = $this->mergeSentences($claims);
        foreach ($claims as &$claim) {
            // Satire provenance may need researching even when the literal
            // wording is an opinion. This never turns an opinion into a fact.
            $claim['context_review_requested'] = ($submission['context_requested'] ?? false) === true;
            // Sentence repair can expand a model fragment into the full topic
            // heading. Decide whether a stray acronym is material afterward.
            if ($claim['requires_context'] && ($claim['searches'] ?? []) !== []
                && preg_match('/\b(?:stands for|expands to|spell out|acronym|abbreviation|logo|watermark|branding?|refers to)\b/iu', $claim['context_reason'])
                && (in_array($claim['kind'], ['rhetorical', 'unclear'], true)
                    || preg_match('/\b(?:status|outcome|proceeding|specific (?:action|claim|assertion))\b/iu', $claim['context_reason'])
                    || (str_contains($claim['quote'], ':') && mb_strtoupper($claim['quote']) === $claim['quote']))) {
                $claim['context_reason'] = 'The heading names a topic but does not state a specific factual outcome to verify. Supply the exact assertion, including its outcome or date if those matter.';
            }
        }
        unset($claim);
        if ($claims === []) {
            $claims[] = ['id' => 'c1', 'text' => $target ?: $text, 'quote' => $target ?: $text, 'submission_context' => $text, 'kind' => 'unclear', 'entities' => [], 'dates' => [], 'locations' => [], 'quantities' => [], 'qualifiers' => [],
                'requires_context' => true, 'context_reason' => $text === '' ? 'No readable claim was available. Supply the claim or original source.' : 'The exact factual assertion could not be established. Supply the full statement and its context.', 'searches' => []];
        }
        foreach ($claims as &$claim) {
            if (($submission['media_attached'] ?? false) && $text !== ''
                && SubmissionTask::questionClaim($submission['question'] ?? '') === $text
                && $claim['quote'] === $text && in_array($claim['kind'], ['question', 'unclear', 'rhetorical'], true)) {
                // A candidate event in a verification question is checkable.
                // Missing photo provenance remains a limitation, not a reason
                // to send the user back to choosing an analysis task.
                $claim['kind'] = 'factual';
            }
        }
        unset($claim);
        if (count($claims) > (int) config('fact_check.max_claims', 6)) {
            $claims = array_slice($claims, 0, max(1, (int) config('fact_check.max_claims', 6)));
            $claims[array_key_last($claims)]['requires_context'] = true;
            $claims[array_key_last($claims)]['context_reason'] = 'The claim limit was reached. Submit the specific statement to avoid treating a partial review as complete.';
        }
        $date = ($data['date_quote'] ?? '') !== '' && str_contains($text, $data['date_quote'])
            ? app(FactCheckEvidencePolicy::class)->date($data['original_claim_date']) : null;

        return ['claims' => $claims, 'original_claim_date' => $date, 'status' => $text === '' ? 'no_readable_claim' : ($data === null ? 'unavailable' : 'completed')];
    }

    public function queries(array $claims, int $round): array
    {
        $queries = [];
        foreach ($claims as $claim) {
            if ($claim['kind'] !== 'factual' && ! ($claim['context_review_requested'] ?? false) && (! in_array($claim['kind'], ['rhetorical', 'unclear'], true)
                || ($claim['searches'] ?? []) === [])) {
                continue;
            }
            $terms = implode(' ', array_unique(array_merge($claim['entities'] ?? [], $claim['locations'] ?? [])));
            $specific = trim($terms.' '.implode(' ', array_merge($claim['quantities'] ?? [], $claim['dates'] ?? [])));
            $short = $this->shortQuery($claim, $terms);
            $domain = $this->officialDomain($claim);
            $officialTerms = implode(' ', array_unique(preg_split('/\s+/u', trim($terms.' '.$short), -1, PREG_SPLIT_NO_EMPTY) ?: []));
            // A full OCR sentence is often an AND search that matches no article.
            // Search the complete assertion through its grounded, distinctive terms,
            // then broaden the wording and, where possible, ask for an official record.
            $choices = array_values(array_unique(array_filter([
                $claim['searches'][0] ?? $specific, $short, $claim['searches'][1] ?? null,
                $domain && $short ? Str::limit(Str::squish($officialTerms), 120, '').' site:'.$domain : ($claim['searches'][2] ?? null),
                ...array_slice($claim['searches'] ?? [], 2), $specific, $terms, Str::limit($claim['text'], 180, ''),
            ])));
            if (array_key_exists('current_status_question', $claim)) {
                // Preserve the resolved subject and year in both first-pass
                // searches; a generic country/topic search can fill the pool
                // before the actual question is researched.
                $choices = array_values(array_unique(array_filter([
                    ...($claim['searches'] ?? []), $specific, $short, $terms, Str::limit($claim['text'], 180, ''),
                ])));
            }
            $queries = array_merge($queries, array_slice($choices, $round === 0 ? 0 : 2, 2));
        }

        return array_values(array_unique($queries));
    }

    public static function isCurrentStatus(string $question): bool
    {
        preg_match_all('/\b(?:19|20)\d{2}\b/u', $question, $years);

        return ! preg_match('/\bas of\b/iu', $question)
            && ! collect($years[0])->contains(fn ($year) => (int) $year < (int) now('Asia/Manila')->format('Y'))
            && (bool) preg_match('/postpon|cancel|reschedul|suspend|in effect|still|current|reopen|closed|approv|signed|enacted|\bban\b/iu', $question);
    }

    /** Resolve a requested historical cutoff, never a decorative OCR/event year. */
    public static function cutoff(string $question, string $runtime): string
    {
        if (! preg_match('/\bas of\s+(\d{4}-\d{2}-\d{2}|(?:January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{1,2},?\s+\d{4})\b/iu', $question, $match)) {
            return $runtime;
        }
        try {
            $parsed = date_parse($match[1]);
            if (($parsed['error_count'] ?? 1) || ($parsed['warning_count'] ?? 1)) {
                return $runtime;
            }
            $requested = Carbon::parse($match[1], 'Asia/Manila')->endOfDay()->utc();

            return $requested->lt(Carbon::parse($runtime)) ? $requested->toIso8601String() : $runtime;
        } catch (\Throwable) {
            return $runtime;
        }
    }

    public static function researchContext(array $claims, string $question, string $text, string $cutoff): array
    {
        $subject = $question;
        foreach ($claims as $claim) {
            foreach ($claim['references'] ?? [] as $reference) {
                $subject = str_replace($reference['phrase'], $reference['referent'], $subject);
            }
        }

        return ['question' => $question, 'resolved_question' => $subject,
            'upload_context' => mb_substr($text, 0, 12000), 'upload_context_truncated' => mb_strlen($text) > 12000,
            'claims' => array_map(fn ($claim) => array_intersect_key($claim, array_flip([
                'id', 'text', 'kind', 'entities', 'locations', 'dates', 'quantities', 'qualifiers', 'references', 'requires_context', 'current_status_question',
            ])), $claims),
            'evaluation_cutoff_utc' => $cutoff, 'display_timezone' => 'Asia/Manila (UTC+8)',
            'temporal_scope' => preg_match('/\bas of\b/iu', $question) ? 'requested historical status' : 'Use the requested event date; for current status check through the runtime cutoff.',
        ];
    }

    private function shortQuery(array $claim, string $terms): string
    {
        $text = $claim['text'] ?? '';
        preg_match_all('/\b(earthquake|lindol|fault(?:line)?|volcano|eruption|tsunami|typhoon|bagyo|storm|flood|rainfall|weather|election|halalan|ballot|vote|postponement|court|law|health|vaccine)\b/iu', $text, $events);
        // Drop the attributed agency from the broad query when the location is
        // known: original reporting may name its parent agency instead.
        $locations = implode(' ', array_unique($claim['locations'] ?? []));
        $anchor = $locations !== '' ? $locations : $terms;
        $eventTerms = array_map(fn ($event) => Str::lower($event) === 'faultline' ? 'fault' : $event, $events[0]);
        $parts = array_filter([$anchor, ...array_slice(array_unique($eventTerms), 0, 2)]);

        return Str::limit(Str::squish(implode(' ', $parts)), 120, '');
    }

    private function officialDomain(array $claim): ?string
    {
        $context = Str::lower(implode(' ', array_merge(
            [$claim['text'] ?? ''], $claim['entities'] ?? [],
        )));

        return match (true) {
            (bool) preg_match('/\bphivolcs\b/u', $context) => 'phivolcs.dost.gov.ph',
            (bool) preg_match('/\bpagasa\b/u', $context) => 'pagasa.dost.gov.ph',
            (bool) preg_match('/\bcomelec\b/u', $context) => 'comelec.gov.ph',
            default => null,
        };
    }

    private function mergeSentences(array $rows): array
    {
        $hasFacts = collect($rows)->contains(fn ($r) => $r['kind'] === 'factual');
        $merged = [];
        foreach ($rows as $row) {
            if ($hasFacts && (in_array($row['kind'], ['question', 'rhetorical'], true)
                || ($row['kind'] === 'unclear' && preg_match('/\b(?:heading|headline|title)\b/iu', $row['context_reason'] ?? '')))) {
                continue; // The full post is retained as submission_context for reference resolution.
            }
            $key = $row['kind'].'|'.$row['quote'];
            if (isset($merged[$key])) {
                foreach (['entities', 'dates', 'locations', 'quantities', 'qualifiers', 'searches'] as $field) {
                    $merged[$key][$field] = array_values(array_unique(array_merge($merged[$key][$field], $row[$field])));
                }
                $merged[$key]['references'] = array_values(array_unique(array_merge($merged[$key]['references'], $row['references']), SORT_REGULAR));
            } else {
                $merged[$key] = $row;
            }
        }
        $rows = array_values($merged);
        foreach ($rows as $index => &$row) {
            $row['id'] = 'c'.($index + 1);
        }

        return $rows;
    }
}
