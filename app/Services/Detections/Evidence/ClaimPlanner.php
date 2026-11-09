<?php

namespace App\Services\Detections\Evidence;

use App\Services\Detections\FactCheckEvidencePolicy;
use App\Services\Detections\SubmissionTask;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class ClaimPlanner
{
    public function __construct(private readonly StructuredAnalysis $model, private readonly PoorPromptInterpreter $interpreter) {}

    public function plan(string $text, array $submission = []): array
    {
        $text = SubmittedText::paragraphs($text);
        $visualContext = is_string($submission['visual_context'] ?? null)
            ? mb_substr(trim($submission['visual_context']), 0, 2000) : '';
        $interpretation = $this->interpreter->interpret($text);
        $claimLimit = ResearchLimits::unrestricted() ? 0 : max(0, (int) config('fact_check.max_claims', 0));
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
        $oneRequest = 'Complete the fact-check in one user request. Never ask a follow-up question or pause for clarification. Resolve reasonable ambiguity using the whole submission, aliases, faithful spelling variants, context, and research. Preserve submitted wording as evidence and use interpretations only for discovery. ';
        $oneRequest .= ResearchGuidance::CLAIM_TYPES.' ';
        $oneRequest .= 'OPEN FACTUAL QUESTIONS: preserve how much/how many/who/when/where/what and Filipino equivalents as the research target. A request for an actual price is not answered merely by refuting a different alleged price. Research the requested value, identity or date; never invent a candidate value. Treat a searchable information question as factual research, not opinion. Keep unknown figures out of quantities. A record giving a value answers that question; report unresolved details if no matching record supplies it. ';
        $oneRequest .= 'VISUAL SUBJECT CONTEXT: submission.visual_context describes visible objects separately from transcribed words. Use it to research references such as these, this, each or bawat isa when the subject is shown rather than written. Keep every claim quote verbatim from original_text; never append this description to a quote or invent an asserted action. A price question over visible vehicles is a candidate price claim to research, not an amount paid to an unidentified person. Use the visible object category in searches without inventing its exact model, owner, location or purchase price. Do not mark the subject missing solely because its visible object category is not printed in the OCR. Actual identity, contract or event gaps still require source research, and appearance cannot establish the claim or a matching photograph. ';
        $oneRequest .= 'A yes/no headline containing a named subject and an explicit status predicate is a checkable candidate, including Filipino wording such as sarado, sinara, naaprubahan, or naaresto. Question marks and sensational typography do not make that status a mere topic. Preserve the literal quote, classify the candidate as factual without assuming it is true, and use every visible text region to resolve its subject, event, date, and scope. Do not set requires_context merely because it is phrased as a question or headline. Keep that flag for an actual unresolved material detail. A filing, complaint, investigation, proposal, and final decision remain different statuses. ';
        $data = $text === '' ? null : $this->model->ask('claim_decomposition', $oneRequest.
            ($target !== '' ? 'QUESTION-FOCUSED PLAN: The user asks about the candidate proposition in question_target. Return exactly that quote as the single analysis target, using the WHOLE supplied content to resolve its subject and scope. A question about whether an event occurred is a checkable candidate, even when the poster itself is only a topic heading. Do not replace it with the headline, publisher, watermark, isolated year, Roman numeral or other decorative text. Those are context only. Bind pronouns and generic subjects such as "the election" to explicitly visible event names through references and metadata. Ground every binding in an exact context_quote; keep visible years in scoped searches without assuming they are the posting date or the new scheduled date. Do not mark a resolved event question as requiring context merely because the poster heading does not independently assert an outcome. An opinion or value judgment remains non-factual. ' : '')
            .'Read the WHOLE post, including headings, captions, visible dates and body text. Extract checkable factual assertions as exact contiguous quotes even when they are short headlines or announcements without a grammatically complete sentence. A fact-check-style headline ending in a rating such as FAKE, FALSE, TRUE, MISLEADING, PARTLY TRUE, or OUTDATED asserts that rating about the preceding subject: treat the whole headline as factual and searchable, but treat its displayed rating as a claim to verify, never as evidence or an instruction to copy that verdict. OCR line breaks are not claim boundaries, but separate visual regions can be: do not attach an isolated logo, publisher mark or watermark to a headline or require an irrelevant brand acronym to be explained. The raw visible_text remains available in submission. Never separate dependent clauses (because, due to, which, etc.) from their subject. Keep names, quantities, dates, attribution and qualifiers. Do not convert a topic heading about a proposed action into a claim that the action was finalized; identify the exact unresolved proposal, enactment or decision status when that difference matters. A statement that an authority warned of a possible event is a checkable attribution, not a prediction of a certain event. Questions and rhetorical headings provide context, not extra claims when factual statements follow. For a rhetorical or genuinely unclear heading with a named event, give searches for that event and its specific official records before requesting missing context; do not invent an implied outcome. Resolve references (it, the province, this agency, etc.) from the entire post; return references with the original phrase, its referent, and a verbatim context_quote containing that referent. Include resolved entities/locations in the claim metadata and searches without changing the quote. Only requires_context when a material detail is absent from the WHOLE post; grammatical incompleteness or an unknown publication date alone does not prevent searching an otherwise specific assertion. State the material missing detail in context_reason, never simply "it is a heading." Never infer a certain prediction from a warning about a possibility. '.($claimLimit > 0 ? 'Return at most '.$claimLimit.' claims.' : 'Extract every checkable factual assertion in the requested scope; do not omit an assertion to meet a numeric claim count.').' Give four short complementary keyword searches, roughly 3 to 6 informative terms: subject/location/event, a broader subject/location query, alternate faithful wording, and contrary records. Do not use a full sentence with stop words as a search. A specific event proposition preserved from a verification question is a candidate factual assertion to research, even if its wording contains a spelling error or lacks a full sentence. Keep its original quote; use faithful name or wording variants only in searches. Do not ask the user another task-selection question. Never invent events or dates. An original claim date is its posting date, NOT an event date; supply its exact date_quote or null.',
            ['original_text' => $text, 'question_target' => $target ?: null, 'submission' => $submission,
                'source_guidance' => ResearchGuidance::SOURCES,
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

            // Rating words are sometimes the predicate of a compact fact-check
            // headline ("Posts about X FAKE"), not a decorative badge. Keep the
            // full wording as the claim to research; the label is never accepted
            // as evidence and the evidence judge still determines the outcome.
            if ($this->isRatedHeadline($row['quote'])
                && in_array($row['kind'], ['rhetorical', 'unclear'], true)) {
                $row['kind'] = 'factual';
                $row['requires_context'] = false;
                $row['context_reason'] = '';
            }

            // An attributed forecast makes a checkable claim that the forecast
            // exists. Do not discard it merely because the forecast itself is
            // about the future.
            $attributedPrediction = preg_match('/\b(?:according to|predicted by|forecast by|study|scientist|researcher)\b/iu', $row['quote'])
                || ($target !== '' && preg_match('/\b(?:according to|predicted by|forecast by|study (?:published|predicts|says|suggests|reports)|scientists? (?:say|predict|warn|report))\b/iu', $text));
            if ($row['kind'] === 'prediction' && $attributedPrediction) {
                $row['kind'] = 'factual';
                $row['requires_context'] = false;
                $row['context_reason'] = '';
                $row['prediction_basis_check'] = true;
            }
            if ($target !== '') {
                $row['current_status_question'] = $this->isCurrentStatus($submission['question'] ?? '');
                if (SubmissionTask::isInformationQuestion($submission['question'] ?? '')
                    && in_array($row['kind'], ['question', 'rhetorical'], true)) {
                    $row['kind'] = 'factual';
                    $row['information_question'] = true;
                }
            }
            foreach (['entities', 'dates', 'locations', 'quantities', 'qualifiers'] as $field) {
                $row[$field] = array_values(array_filter($row[$field], fn ($part) => $part !== '' && Str::contains(Str::lower($text), Str::lower($part))));
            }
            $row['searches'] = array_values(array_unique(array_filter(array_map(fn ($q) => Str::limit(trim($q), 180, ''), $row['searches']))));
            $row['references'] = array_values(array_filter($row['references'] ?? [], fn ($r) => trim($r['phrase'] ?? '') !== '' && str_contains($row['quote'], $r['phrase'])
                && trim($r['referent'] ?? '') !== '' && Str::contains(Str::lower($r['context_quote'] ?? ''), Str::lower($r['referent']))
                && str_contains($text, $r['context_quote'])));
            $row = $this->normalizeStatusQuestion($row);
            // A farewell or greeting alone does not assert a firing, departure
            // or appointment. Preserve it as context alongside explicit facts.
            if ($target === '' && in_array($row['kind'], ['factual', 'unclear'], true)
                && preg_match('/^(?:bye(?:[- ]+bye)?|goodbye|farewell|hello|welcome|paalam|kumusta)\s+([^.!?]{1,100})\?+[!?.]*$/iu', trim($row['quote']), $farewell)
                && in_array(Str::lower(Str::squish($farewell[1])), array_map(fn ($entity) => Str::lower(Str::squish($entity)), $row['entities']), true)) {
                $row['kind'] = 'rhetorical';
            }
            if ($target === '' && $row['kind'] === 'unclear' && $row['requires_context']
                && str_contains($row['quote'], '?')
                && array_merge($row['entities'], $row['dates'], $row['locations'], $row['quantities']) === []
                && ! preg_match('/\d|\b(?:closed|shutdown|shut down|cancelled|canceled|postponed|approved|confirmed|decided|ruled|ordered|signed|enacted|arrested|suspended|reopened|sarado|sinara|ipinasara|nagsara|kinansela|ipinagpaliban|naaprubahan|napirmahan|isinabatas|naaresto|sinuspinde|binuksan|nagbukas|kinumpirma|nagpasya|inutos)\b/iu', $row['quote'])
                && preg_match('/\bno subject\b|does not (?:state|specify).{0,40}(?:assertion|outcome)|topic heading/iu', $row['context_reason'])) {
                // A subjectless rhetorical subtitle supplies context for the
                // concrete assertion. It cannot independently assert a ruling.
                $row['kind'] = 'rhetorical';
            }
            // Preserve a complete sentence for extracted fragments. A focused
            // question is already the complete target; adjacent OCR is context.
            if ($target === '' && $row['kind'] === 'factual') {
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
        // An explicit status question must not disappear merely because the
        // model extracted an easier secondary fact from another image region.
        foreach ($target === '' ? SubmittedText::sentences($text) : [] as $sentence) {
            if (! $this->isNamedStatusQuestion($sentence)
                || collect($claims)->contains(fn ($claim) => str_contains($claim['quote'], $sentence) || str_contains($sentence, $claim['quote']))) {
                continue;
            }
            $claims[] = ['id' => 'c'.(count($claims) + 1), 'text' => $sentence, 'quote' => $sentence,
                'kind' => 'factual', 'requires_context' => false, 'context_reason' => '',
                'entities' => [], 'dates' => [], 'locations' => [], 'quantities' => [], 'qualifiers' => [],
                'references' => [], 'searches' => array_values(array_unique(array_merge($interpretation['searches'], $this->interpreter->interpret($sentence)['searches']))),
                'submission_context' => $text, 'question_framed_assertion' => true];
        }
        $claims = $this->mergeSentences($claims);

        // A rhetorical headline is presentation, not a competing assessment
        // target when the same post contains a specific factual statement.
        if (collect($claims)->contains(fn ($claim) => ($claim['kind'] ?? null) === 'factual')) {
            $claims = array_values(array_filter($claims, fn ($claim) => ! in_array($claim['kind'] ?? null, ['question', 'rhetorical'], true)));
            foreach ($claims as $index => &$claim) {
                $claim['id'] = 'c'.($index + 1);
            }
            unset($claim);
        }
        foreach ($claims as &$claim) {
            // Satire provenance may need researching even when the literal
            // wording is an opinion. This never turns an opinion into a fact.
            $claim['context_review_requested'] = ($submission['context_requested'] ?? false) === true;
            // Sentence repair can expand a model fragment into the full topic
            // heading. Decide whether a stray acronym is material afterward.
            if ($claim['requires_context'] && ($claim['searches'] ?? []) !== []
                && ! ($claim['question_framed_assertion'] ?? false)
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
                'requires_context' => true, 'context_reason' => $text === '' ? 'No readable claim was available.' : 'The exact asserted action remains ambiguous in the submitted wording.', 'searches' => $interpretation['searches']];
        }
        foreach ($claims as &$claim) {
            if ($visualContext !== '') {
                $claim['visual_context'] = $visualContext;
            }
            if ($visualContext !== '' && SubmissionTask::isInformationQuestion($submission['question'] ?? '')
                && is_string($submission['visible_text'] ?? null)) {
                $claim['caption_context'] = mb_substr($submission['visible_text'], 0, 2000);
                // Discovery context is not another asserted answer. Retain the
                // caption's distinctive amount so generic object searches do
                // not crowd out published checks of the viral allegation.
                if (preg_match('/(?:[₱$€£]\s*\d+(?:[.,]\d+)*(?:\s*(?:million|billion|thousand|milyon|bilyon|milyong))?|\d+(?:[.,]\d+)*(?:\s*(?:million|billion|thousand|milyon|bilyon|milyong))?\s*(?:pesos?|dollars?|php|usd|%))(?=\s|[.!?,;:]|$)/iu', $submission['visible_text'], $amount)) {
                    $claim['caption_discovery_query'] = Str::squish($amount[0]).' fact check';
                } else {
                    foreach (SubmittedText::sentences($submission['visible_text']) as $sentence) {
                        if (count(preg_split('/\s+/u', trim($sentence), -1, PREG_SPLIT_NO_EMPTY)) >= 4) {
                            $claim['caption_discovery_query'] = Str::limit(Str::squish($sentence), 140, '').' fact check';
                            break;
                        }
                    }
                }
            }
            $claim['interpreted_text'] = $interpretation['interpreted_claim'];
            $claim['interpretation_assumption'] = $interpretation['assumption_used'];
            $claim['ambiguities'] = $interpretation['ambiguities'];
            $claim['searches'] = array_values(array_unique(array_merge($claim['searches'] ?? [], $interpretation['searches'])));
        }
        unset($claim);
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
        if ($claimLimit > 0 && count($claims) > $claimLimit) {
            $claims = array_slice($claims, 0, $claimLimit);
            $claims[array_key_last($claims)]['requires_context'] = true;
            $claims[array_key_last($claims)]['context_reason'] = 'The claim limit was reached. Submit the specific statement to avoid treating a partial review as complete.';
        }
        $date = ($data['date_quote'] ?? '') !== '' && str_contains($text, $data['date_quote'])
            ? app(FactCheckEvidencePolicy::class)->date($data['original_claim_date']) : null;

        return ['claims' => $claims, 'interpretation' => $interpretation, 'original_claim_date' => $date, 'status' => $text === '' ? 'no_readable_claim' : ($data === null ? 'unavailable' : 'completed')];
    }

    private function normalizeStatusQuestion(array $row): array
    {
        if (! in_array($row['kind'], ['factual', 'question', 'rhetorical', 'unclear'], true)
            || ! $this->isNamedStatusQuestion($row['quote'])) {
            return $row;
        }
        // This creates a proposition to test, never a factual conclusion.
        $row['kind'] = 'factual';
        $row['question_framed_assertion'] = true;
        $reason = (string) ($row['context_reason'] ?? '');
        $materialGap = preg_match('/\b(?:which|identity|identify|unidentified|ambiguous|refers to|stands for|acronym|abbreviation|jurisdiction|location|specific date|which date|what date|scope|proposed|proposal|enacted|final decision)\b/iu', $reason);
        $formattingGap = preg_match('/\b(?:heading|headline|rhetorical|question|topic|punctuation|grammar|sentence|asserted action|factual outcome)\b/iu', $reason);
        if ($row['requires_context'] && $formattingGap && ! $materialGap) {
            $row['requires_context'] = false;
            $row['context_reason'] = '';
        }

        return $row;
    }

    private function isNamedStatusQuestion(string $quote): bool
    {
        $quote = Str::squish($quote);
        if (! preg_match('/[?？]/u', $quote)
            || preg_match('/^(?:what|which|who|where|when|why|how|ano|alin|sino|saan|kailan|bakit|paano)\b/iu', $quote)
            || preg_match('/\b(?:if|unless|would|might|could|should|will|possible|proposed|proposal|planned|dapat|sana|kung|sakali|ipapasara|isasara)\b/iu', $quote)) {
            return false;
        }
        if (! preg_match('/^(?:(?:is|are|was|were|has|have)\s+)?(.{2,100}?)\s+(?:(?:is|are|was|were|already|now|really|has been|have been)\s+)*(?:closed|shut down|cancelled|canceled|postponed|approved|signed|enacted|arrested|suspended|reopened|sarado|sinara|ipinasara|nagsara|kinansela|ipinagpaliban|naaprubahan|napirmahan|isinabatas|naaresto|sinuspinde|binuksan|nagbukas)\b/iu', $quote, $matches)) {
            return false;
        }
        // Require a literal named subject. Generic pronouns or objects still
        // need their referent; this guard does not guess who "it" identifies.
        $subject = preg_replace('/\b(?:the|this|that|these|those|it|they|he|she|we|our|a|an|ang|mga|ito|iyan|iyon|si|sina|network|station|school|bridge|library|election|law|order|bill)\b/iu', '', $matches[1]);

        return (bool) preg_match('/\b\p{Lu}[\pL\pN-]{1,}\b/u', $subject);
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
            $officialQuery = $domain && ($officialTerms !== '' || $short !== '')
                ? Str::limit(Str::squish($officialTerms ?: $short), 120, '').' site:'.$domain
                : null;
            $choices = array_values(array_unique(array_filter([
                $claim['searches'][0] ?? $specific, $officialQuery, $short, $claim['searches'][1] ?? null,
                $claim['searches'][2] ?? null,
                ...array_slice($claim['searches'] ?? [], 2), $specific, $terms, Str::limit($claim['text'], 180, ''),
            ])));
            if (($claim['current_status_question'] ?? false) === true) {
                // Preserve the resolved subject and year in both first-pass
                // searches; a generic country/topic search can fill the pool
                // before the actual question is researched.
                $choices = array_values(array_unique(array_filter([
                    ...($claim['searches'] ?? []), $specific, $short, $terms, Str::limit($claim['text'], 180, ''),
                ])));
            }
            if (! empty($claim['caption_discovery_query'])) {
                $choices = array_values(array_unique([$claim['caption_discovery_query'], ...$choices]));
            }
            $queries = array_merge($queries, array_slice($choices, ResearchLimits::unrestricted() ? $round * 2 : ($round === 0 ? 0 : 2), 2));
        }

        return array_values(array_unique($queries));
    }

    public static function targetedQueries(array $claims): array
    {
        $queries = [];
        foreach ($claims as $claim) {
            $text = Str::squish((string) ($claim['text'] ?? ''));
            if ($text !== '') {
                $queries[] = $text.' fact check';
                $queries[] = $text.' original record';
            }
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

    public static function requiresFreshEvidence(string $text): bool
    {
        return self::isCurrentStatus($text)
            || (bool) preg_match('/\b(?:today|tonight|tomorrow|yesterday|now|latest|currently|weather|forecast|live|ongoing|recent|this (?:week|month|year))\b|ngayon|bukas|kasalukuyan|pinakabago|ngayong|sarado|ipinagpaliban|kinansela/iu', $text);
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
            'visual_contexts' => array_values(array_unique(array_filter(array_column($claims, 'visual_context')))),
            'caption_contexts' => array_values(array_unique(array_filter(array_column($claims, 'caption_context')))),
            'upload_context' => mb_substr($text, 0, 12000), 'upload_context_truncated' => mb_strlen($text) > 12000,
            'claims' => array_map(fn ($claim) => array_intersect_key($claim, array_flip([
                'id', 'text', 'interpreted_text', 'interpretation_assumption', 'ambiguities', 'kind', 'entities', 'locations', 'dates', 'quantities', 'qualifiers', 'references', 'requires_context', 'current_status_question',
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

    private function isRatedHeadline(string $text): bool
    {
        $text = trim($text);

        return Str::wordCount($text) >= 4
            && (bool) preg_match('/\b(?:fake|false|true|misleading|partly[\s_-]+true|outdated|altered|manipulated)\s*[.!?]*$/iu', $text)
            && (bool) preg_match('/[\p{L}\p{N}].{5,}\s/u', $text);
    }

    private function officialDomain(array $claim): ?string
    {
        $context = Str::lower(implode(' ', array_merge(
            [$claim['text'] ?? ''], $claim['entities'] ?? [], $claim['locations'] ?? [],
        )));

        $namedAgency = match (true) {
            (bool) preg_match('/\bphivolcs\b/u', $context) => 'phivolcs.dost.gov.ph',
            (bool) preg_match('/\bpagasa\b/u', $context) => 'pagasa.dost.gov.ph',
            (bool) preg_match('/\bcomelec\b/u', $context) => 'comelec.gov.ph',
            (bool) preg_match('/\bndrrmc\b/u', $context) => 'ndrrmc.gov.ph',
            (bool) preg_match('/\bdeped\b/u', $context) => 'deped.gov.ph',
            default => null,
        };
        if ($namedAgency !== null) {
            return $namedAgency;
        }
        // A global topic or a foreign event must not acquire a Philippine
        // authority merely because its wording contains health, school or law.
        // The planner can still supply the correct local authority in searches.
        if (! preg_match('/\b(?:philippines|philippine|filipino|pilipinas|pilipino)\b/u', $context)) {
            return null;
        }

        return match (true) {
            (bool) preg_match('/\bphivolcs\b/u', $context) => 'phivolcs.dost.gov.ph',
            (bool) preg_match('/\bpagasa\b/u', $context) => 'pagasa.dost.gov.ph',
            (bool) preg_match('/\bcomelec\b/u', $context) => 'comelec.gov.ph',
            (bool) preg_match('/\b(?:earthquake|lindol|fault(?:line)?|volcano|eruption|tsunami)\b/u', $context) => 'phivolcs.dost.gov.ph',
            (bool) preg_match('/\b(?:weather|climate|typhoon|rainfall|forecast|storm|bagyo|panahon)\b/u', $context) => 'pagasa.dost.gov.ph',
            (bool) preg_match('/\b(?:disaster|ndrrmc|evacuation|emergency response)\b/u', $context) => 'ndrrmc.gov.ph',
            (bool) preg_match('/\b(?:health|doh|vaccine|disease|outbreak|medicine|hospital|kalusugan|gamot)\b/u', $context) => 'doh.gov.ph',
            (bool) preg_match('/\b(?:election|halalan|ballot|vote|voting|botante)\b/u', $context) => 'comelec.gov.ph',
            (bool) preg_match('/\b(?:education|school|class suspension|deped|teacher|student)\b/u', $context) => 'deped.gov.ph',
            (bool) preg_match('/\b(?:population|census|national statistics|psa|inflation rate|employment rate)\b/u', $context) => 'psa.gov.ph',
            (bool) preg_match('/\b(?:central bank|monetary policy|interest rate|bsp|bangko sentral)\b/u', $context) => 'bsp.gov.ph',
            (bool) preg_match('/\b(?:law|act|proclamation|executive order|official gazette)\b/u', $context) => 'officialgazette.gov.ph',
            (bool) preg_match('/\b(?:supreme court|court decision|court ruling)\b/u', $context) => 'judiciary.gov.ph',
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
