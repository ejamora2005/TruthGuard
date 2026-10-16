<?php

namespace App\Services\Detections\Evidence;

use App\Services\Detections\SubmissionTask;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

class EvidenceJudge
{
    public array $reviewScopes = [];

    public array $contextFindings = [];

    /** Dated, source-backed context for a searchable headline without an asserted outcome. */
    public array $contextualSources = [];

    public function __construct(private readonly StructuredAnalysis $model, private readonly ArticleSearch $search) {}

    public function assess(array $claims, array $records, string $cutoff, int $round, array $request = []): array
    {
        $sources = collect($records)->filter(fn ($r) => ($r['retrieval_status'] ?? '') === 'retrieved' && ($r['source_type'] ?? '') !== 'unapproved'
            && ! $this->search->isGenericLandingPage($r['url']))->values()->all();
        $reviewable = array_values(array_filter($claims, fn ($c) => $c['kind'] === 'factual'
            || ($c['context_review_requested'] ?? false)
            || (in_array($c['kind'], ['rhetorical', 'unclear'], true) && ($c['searches'] ?? []) !== [])));
        if ($sources === [] || $reviewable === []) {
            return [];
        }
        // Share the input budget across sources; never hide a partial reading.
        $sources = array_map(fn ($s) => Arr::only($s, ['id', 'url', 'title', 'name', 'source_type', 'published_at', 'updated_at', 'possible_copy_of', 'text']), $sources);
        $string = ['type' => 'string'];
        $matches = StructuredAnalysis::object(array_fill_keys(['event', 'entities', 'action', 'date', 'location', 'quantities', 'qualifiers'], [
            'type' => 'boolean',
            'description' => 'True when this passage addresses the corresponding dimension of this exact claim, including an explicit correction of its asserted value. Unspecified dimensions that impose no constraint MUST be true, never false. False for unrelated events or unresolved identity, not merely because a refuting value differs.',
        ]));
        $schema = StructuredAnalysis::object(['context_findings' => ['type' => 'array', 'items' => StructuredAnalysis::object([
            'claim_id' => $string, 'source_id' => $string,
            'label' => ['type' => 'string', 'enum' => ['OUTDATED', 'OUT_OF_CONTEXT', 'MISLEADING', 'SATIRE_PARODY', 'RELATED_STATUS']],
            'excerpt' => $string, 'explanation' => $string, 'submitted_quote' => $string,
            'exact_subject_match' => ['type' => 'boolean'], 'presented_as_current' => ['type' => 'boolean'],
            'valid_until' => ['type' => ['string', 'null']],
        ])], 'relationships' => ['type' => 'array', 'items' => StructuredAnalysis::object([
            'claim_id' => $string, 'source_id' => $string,
            'stance' => ['type' => 'string', 'enum' => ['supporting', 'contradicting', 'insufficient']],
            'excerpt' => $string, 'explanation' => $string, 'matches' => $matches,
            'basis' => ['type' => 'string', 'enum' => ['primary_record', 'direct_reporting', 'fact_check', 'background']],
            'supersedes_source_ids' => ['type' => 'array', 'items' => $string], 'supersession_excerpt' => $string,
        ])]]);
        $instructions = 'Compare each exact claim with retrieved article text. Give a relationship for each candidate considered; insufficient for unrelated/background material. Shared keywords are not a match. All decisive relationships require a verbatim contiguous excerpt from text and matching event, people, location, date, quantities, qualifiers and asserted action. Mark unspecified dimensions true only when they impose no constraint. A fact-check of a different claim is insufficient regardless of its rating. A petition or constitutional challenge is not a final ruling; a bill is not an enacted law. An official statement only establishes what that body can directly establish. News describing a statement does not automatically prove the statement. Do not infer truth from image authenticity or model scores. Distinguish past accuracy from current accuracy at the supplied cutoff. Only mark supersession when newer text explicitly replaces/corrects the older record, and quote that passage. Explain unresolved conflicts; never select a preferred verdict. For rhetorical or unclear headings, or a factual heading marked requires_context, never mark a relationship supporting or contradicting: the missing material detail prevents a specific outcome. A directly relevant, dated passage about the named event or its proposal/enactment/decision status may instead be returned as a RELATED_STATUS context_finding, with a short explanation limited to what the passage establishes at its date. Unrelated claims or merely shared topic words cannot be RELATED_STATUS. Cite ONLY provided claim IDs and source IDs. Summaries and search snippets are not article text.'
            .' Match flags measure correspondence to the exact assertion, not agreement with its asserted values. For a contradicting relationship, a passage explicitly correcting the date, quantity, location or qualifier of the SAME event/media/statement addresses that dimension: mark its flag true. An unrelated event in another year remains a non-match. Do not reject a directly established correction merely because it contradicts the submitted value. No correction may be inferred from shared keywords alone.'
            .' Explain decisive findings in plain language that answers the requested question: state what happened, the applicable dates and the responsible authority when the quoted passage establishes them. Do not fill explanations with internal matching terminology; match flags already record that separately. A candidate event preserved from the user question is the assertion under review. The poster heading need not independently assert that outcome. Resolve its subject from validated references and the whole submission; do not treat a general factual question about poster text as a demand to authenticate a particular photograph.'
            .' Return every independently established contextual finding, not just one genre label: source-backed satire can also be out of context when the same clip is recaptioned as a different event/date. Each label still needs a matching passage. Prioritize findings that answer the requested context check.'
            .' If a retrieved passage explicitly states a dated status for the same named event and year, return RELATED_STATUS even when the corresponding relationship is insufficient because the heading asserts no outcome. Quote the exact supplied passage and describe only its dated status. If no passage qualifies, return no RELATED_STATUS finding.';
        $questionTarget = SubmissionTask::questionClaim($request['question'] ?? '');
        $questionFocused = $questionTarget !== '' && count($reviewable) === 1 && $reviewable[0]['text'] === $questionTarget;
        if ($questionFocused) {
            // Question answering has a different target from headline review.
            // Do not send competing instructions to judge the poster heading.
            $instructions = 'PRIMARY TASK: Research and answer requested_analysis.question using only the supplied source text. The single claim is its candidate proposition; the attached poster and submission_contexts resolve its subject and dates. The poster is context, not an additional claim that must independently state the answer. A factual event question does not become rhetorical because its accompanying headline is rhetorical. Use supporting, contradicting or insufficient relationships to assess that candidate. Do not infer missing scope merely from the question form. An opinion or a genuinely unresolved subject remains non-factual or unresolved.'
                .' Require a verbatim contiguous excerpt of 20 to 1200 characters for each decisive relationship, copied from the provided source text. Match the same event, entities, action, date, location, quantities and material qualifiers. Match flags mean the passage addresses the dimension, including explicitly correcting a claimed value, not agreement with the claimed value. Unspecified dimensions impose no constraint. Shared keywords and unrelated events are insufficient. Publisher names, agency logos, building labels, watermarks and verification words such as really/actually are not constraints unless the question makes them material. Resolve references using the whole submission and grounded references.'
                .' Never inherit the published rating of a fact-check about another claim. However, a directly relevant passage inside that article may independently establish the precise event asked about, even if the article headline checks a different assertion. Judge the quoted passage, not the article rating or headline. Search snippets and summaries are not article text. An official statement establishes only what that authority can establish. A petition is not a court ruling; a bill is not an enacted law; reporting that a law was enacted can establish enactment without establishing its constitutionality.'
                .' Explain decisive findings in two short informative sentences, beginning with the concrete outcome, its applicable date and responsible authority when established by the passage. Then state material qualifications. Avoid openings such as This confirms, This supports, or I searched. Do not fill explanations with internal matching terminology. Do not infer facts from AI-origin scores, assumed faces or image authenticity. Cite only provided claim IDs and source IDs. Preserve genuinely conflicting records. A newer publication alone does not resolve a conflict: quote explicit superseding evidence and identify the older source. RELATED_STATUS is only for material unresolved scope, not a substitute for answering a resolved factual question.';
        }
        $instructions .= ' For current_status_question, an older dated report establishes what was known then, not necessarily the status at the evaluation cutoff. A provisional statement that an event will proceed unless a new law or decision changes it cannot refute a later postponement. Check subsequent enactments, cancellations and corrections. When a later passage explicitly establishes a changed schedule for the SAME event, identify the older source it supersedes and quote the changed schedule. A court challenge alone does not overturn an enacted law.';
        $instructions .= ' Write explanations for the person who submitted this content: lead with the concrete evidence, then explain why it supports or contradicts the exact claim, or what remains unverified. Use two short sentences. Name the supplied publisher when attributing its reporting; distinguish what a source reports from what it independently establishes. No confidence percentages, invented reasons, unrelated publisher ratings, or follow-up questions.';
        // The planner stores the whole submission on every claim for validation.
        if (($request['media_attached'] ?? false) && preg_match('/\b(?:this|that|pictured|shown)\s+(?:old\s+|elderly\s+)?(?:woman|man|lady|person|people)\b/iu', $request['question'] ?? '')) {
            $instructions .= ' The user is asking about a particular person or action in an attached photograph. You have article text, not a verified visual match of that photograph. A report of a similar event involving the same public figure cannot establish that this particular image is authentic or shows that event. Do not identify people from assumed resemblance, invent reverse-image searches, or claim a visual comparison occurred. Without source provenance explicitly establishing the submitted photograph, keep its specific identity/event relationship insufficient and explain the distinction from any general event reporting.';
        }
        // Send that context once rather than multiplying it by the claim count.
        $input = ['claims' => array_map(fn ($c) => Arr::except($c, ['submission_context', 'relationships', 'outcome']), $reviewable),
            'submission_contexts' => array_values(array_unique(array_filter(array_column($reviewable, 'submission_context')))),
            'sources' => $sources, 'evaluation_cutoff' => $cutoff, 'requested_analysis' => $request,
            'context_rules' => ($questionFocused
                ? 'The target is the factual candidate in the user question, not a poster heading. Only consider RELATED_STATUS if the claim is marked requires_context for a genuinely missing material detail. '
                : 'For an unclear/rhetorical heading or a factual heading marked requires_context with no specific asserted outcome, RELATED_STATUS needs a passage directly naming the same event and its dated status; it supplies context only and never a verdict. ')
                .'Return other context_findings only when a specific reviewed passage establishes the exact submitted content is misleading, reused with a wrong caption/location/date, expired while presented as current, or explicitly identified as satire/parody. Do not infer reuse from similar images or keywords. Copy submitted_quote exactly from the submission. OUTDATED needs an explicit expiry/valid-until passage and its literal date text in valid_until, plus present-tense/current framing in submitted_quote. An old publication date alone is insufficient. Satirical factual assertions still need ordinary claim relationships. No media-origin conclusion can be inferred here. Never invent a visual comparison, issuance time or validity period.',
            'excerpt_rule' => 'Every decisive excerpt must be 20 to 1200 characters. A RELATED_STATUS excerpt must be 20 to 500 characters and explicitly state the named event or proposal status. review_text_truncated means only the supplied prefix was read; do not claim the rest was reviewed. possible_copy_of flags suspected shared reporting: do not count it as independent confirmation, but check material differences and conflicts.'];
        $sources = $this->fitSources($sources, $input, $instructions);
        $input['sources'] = $sources;
        $data = $this->model->ask('evidence_relationships_'.$round, $instructions, $input, $schema);

        if ($data === null) {
            return [];
        }
        $findings = $this->validateContext($data['context_findings'], $reviewable, $sources, $cutoff);
        $this->contextualSources = array_values(array_unique(array_merge($this->contextualSources,
            array_values(array_filter($findings, fn ($row) => $row['label'] === 'RELATED_STATUS'))), SORT_REGULAR));
        $this->contextFindings = array_values(array_unique(array_merge($this->contextFindings,
            array_values(array_filter($findings, fn ($row) => $row['label'] !== 'RELATED_STATUS'))), SORT_REGULAR));

        return $this->validate($data['relationships'], $reviewable, $sources, $cutoff);
    }

    private function fitSources(array $sources, array $input, string $instructions): array
    {
        $limit = max(1024, (int) config('fact_check.max_model_input_bytes', 65536));
        $low = 0;
        $high = max(array_map(fn ($s) => strlen($s['text']), $sources));
        $cut = fn ($bytes) => array_map(fn ($s) => array_replace($s, [
            'text' => mb_strcut($s['text'], 0, $bytes, 'UTF-8'),
            'review_text_truncated' => $bytes < strlen($s['text']),
        ]), $sources);
        // Measure the actual JSON encoding used by StructuredAnalysis, including
        // escaped newlines/slashes, Unicode, request metadata and instructions.
        while ($low < $high) {
            $mid = (int) ceil(($low + $high) / 2);
            $input['sources'] = $cut($mid);
            $size = strlen(json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) + strlen($instructions);
            if ($size <= $limit) {
                $low = $mid;
            } else {
                $high = $mid - 1;
            }
        }
        $fitted = $cut($low);
        foreach ($fitted as $source) {
            $this->reviewScopes[$source['id']] = ['review_text_truncated' => $source['review_text_truncated'],
                'reviewed_text_bytes' => strlen($source['text'])];
        }

        return $fitted;
    }

    public function validateContext(array $rows, array $claims, array $sources, string $cutoff): array
    {
        $claims = collect($claims)->keyBy('id');
        $sources = collect($sources)->keyBy('id');

        return array_values(array_filter($rows, function ($row) use ($claims, $sources, $cutoff) {
            $claim = $claims->get($row['claim_id']);
            $source = $sources->get($row['source_id']);
            if (! $claim || ! $source || ! $row['exact_subject_match'] || trim($row['explanation']) === ''
                || mb_strlen($row['excerpt']) < 20 || mb_strlen($row['excerpt']) > 1200 || ! str_contains($source['text'], $row['excerpt'])
                || trim($row['submitted_quote']) === '' || ! str_contains($claim['submission_context'] ?? $claim['text'], $row['submitted_quote'])) {
                return false;
            }
            foreach (array_filter([$source['published_at'] ?? null, $source['updated_at'] ?? null]) as $date) {
                try {
                    if (Carbon::parse($date)->gt(Carbon::parse($cutoff))) {
                        return false;
                    }
                } catch (\Throwable) {
                    return false;
                }
            }
            if ($row['label'] === 'SATIRE_PARODY'
                && ! preg_match('/\b(?:satire|satirical|parody|fiction(?:al)?|satiro|parodiya|biro)\b/iu', $row['excerpt'])) {
                return false;
            }
            if ($row['label'] === 'RELATED_STATUS') {
                preg_match_all('/\b(?:19|20)\d{2}\b/u', $claim['text'], $claimYears);
                foreach (array_unique($claimYears[0] ?? []) as $year) {
                    if (! preg_match('/\b'.preg_quote($year, '/').'\b/u', $row['excerpt'])) {
                        return false;
                    }
                }
                $dates = array_values(array_filter([$source['published_at'] ?? null, $source['updated_at'] ?? null]));
                if ($dates === []) {
                    return false;
                }
                foreach ($dates as $date) {
                    try {
                        if (Carbon::parse($date)->gt(Carbon::parse($cutoff))) {
                            return false;
                        }
                    } catch (\Throwable) {
                        return false;
                    }
                }

                return (in_array($claim['kind'], ['rhetorical', 'unclear'], true)
                        || ($claim['kind'] === 'factual' && ($claim['requires_context'] ?? false)))
                    && ($claim['searches'] ?? []) !== []
                    && mb_strlen($row['excerpt']) <= 500
                    && $this->sameNamedEvent($claim['text'], $row['excerpt']);
            }
            if ($row['label'] === 'OUTDATED') {
                if (! $row['presented_as_current'] || ! preg_match('/\b(?:today|now|current|ngayon|kasalukuyan|is in effect|remains in effect)\b/iu', $row['submitted_quote'])
                    || empty($row['valid_until']) || ! str_contains($row['excerpt'], $row['valid_until'])
                    || ! preg_match('/valid (?:until|through)|expire|supersed|in effect until|hanggang/iu', $row['excerpt'])) {
                    return false;
                }
                try {
                    $zone = str_ends_with((string) parse_url($source['url'], PHP_URL_HOST), '.ph') ? 'Asia/Manila' : 'UTC';
                    $until = Carbon::parse($row['valid_until'], $zone);
                    if (! preg_match('/\d:\d|\b(?:AM|PM)\b/i', $row['valid_until'])) {
                        $until->endOfDay();
                    }

                    return $until->lt(Carbon::parse($cutoff));
                } catch (\Throwable) {
                    return false;
                }
            }

            return in_array($row['label'], ['OUT_OF_CONTEXT', 'MISLEADING', 'SATIRE_PARODY'], true);
        }));
    }

    private function sameNamedEvent(string $claim, string $excerpt): bool
    {
        preg_match_all('/[\p{L}][\p{L}\p{N}]{2,}/u', mb_strtolower($claim), $matches);
        $common = ['the', 'and', 'for', 'not', 'are', 'was', 'were', 'has', 'had', 'may', 'can', 'our', 'its',
            'this', 'that', 'with', 'from', 'about', 'against', 'time', 'what', 'when', 'where',
            'your', 'their', 'could', 'would', 'should', 'have', 'been', 'into', 'some', 'more'];
        preg_match_all('/\b[A-Z]{2,8}\b/u', $claim, $abbreviations);
        $acronyms = [];
        foreach ($abbreviations[0] ?? [] as $abbreviation) {
            if (! in_array(mb_strtolower($abbreviation), $common, true)) {
                $acronyms[mb_substr(mb_strtolower($abbreviation), 0, 5)][] = $abbreviation;
            }
        }
        $anchors = array_values(array_unique(array_map(
            fn ($term) => mb_substr($term, 0, 5),
            array_values(array_diff($matches[0] ?? [], $common))
        )));
        if (count($anchors) < 2) {
            return false;
        }

        $direct = array_values(array_filter($anchors, fn ($anchor) => mb_stripos($excerpt, $anchor) !== false));
        if (count($direct) >= 2) {
            return true;
        }
        if (! collect($direct)->contains(fn ($anchor) => ! isset($acronyms[$anchor]))) {
            return false;
        }

        foreach (array_diff($anchors, $direct) as $anchor) {
            foreach ($acronyms[$anchor] ?? [] as $acronym) {
                if ($this->spellsOutAcronym($acronym, $excerpt)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function spellsOutAcronym(string $acronym, string $excerpt): bool
    {
        $initials = str_split(mb_strtolower($acronym));
        $connectors = ['and', 'of', 'the', 'for', 'in', 'on', 'at', 'to', 'sa', 'ng', 'mga', 'na', 'ni', 'de', 'del', 'la', 'le', 'y', 'et'];
        foreach (preg_split('/[.!?;\r\n]+/u', $excerpt) ?: [] as $sentence) {
            preg_match_all('/[\p{L}][\p{L}\p{N}]*/u', mb_strtolower($sentence), $matches);
            $words = $matches[0] ?? [];
            foreach ($words as $start => $word) {
                if (mb_substr($word, 0, 1) !== $initials[0]) {
                    continue;
                }
                $matched = 0;
                $skipped = 0;
                for ($position = $start; $position < count($words) && $matched < count($initials); $position++) {
                    $part = $words[$position];
                    if ($matched > 0 && in_array($part, $connectors, true) && $skipped < 2) {
                        $skipped++;

                        continue;
                    }
                    if (mb_substr($part, 0, 1) !== $initials[$matched]) {
                        break;
                    }
                    $matched++;
                    $skipped = 0;
                }
                if ($matched === count($initials)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Later review may resolve a limitation or add explicit supersession, never erase contrary sources. */
    public function mergeReviews(array $earlier, array $later): array
    {
        $merged = [];
        foreach (array_merge($earlier, $later) as $row) {
            $key = $row['claim_id'].'|'.$row['source_id'];
            $previous = $merged[$key] ?? null;
            if ($previous && $previous['stance'] !== 'insufficient') {
                if ($row['stance'] === 'insufficient') {
                    continue;
                }
                if ($previous['stance'] !== $row['stance']) {
                    $row['stance'] = 'insufficient';
                    $row['validation_limitation'] = 'Evidence reviews disagreed about the same source; no decisive relationship was retained.';
                    $row['supersedes_source_ids'] = [];
                } else {
                    $row['supersedes_source_ids'] = array_values(array_unique(array_merge($previous['supersedes_source_ids'], $row['supersedes_source_ids'])));
                    if ($row['supersedes_source_ids'] !== [] && $row['supersession_excerpt'] === '') {
                        $row['supersession_excerpt'] = $previous['supersession_excerpt'];
                    }
                }
            }
            $merged[$key] = $row;
        }

        return array_values($merged);
    }

    public function validate(array $rows, array $claims, array $sources, ?string $cutoff = null): array
    {
        $claims = collect($claims)->keyBy('id');
        $sources = collect($sources)->keyBy('id');
        $valid = [];
        foreach ($rows as $row) {
            $claim = $claims->get($row['claim_id']);
            $source = $sources->get($row['source_id']);
            if (! $claim || ! $source) {
                continue;
            }
            if ($claim['kind'] !== 'factual' || ($claim['requires_context'] ?? false)) {
                $row['stance'] = 'insufficient';
                $row['supersedes_source_ids'] = [];
            }
            $excerpt = trim($row['excerpt']);
            $decisive = in_array($row['stance'], ['supporting', 'contradicting'], true);
            $reason = null;
            if (mb_strlen($excerpt) < 20 || mb_strlen($excerpt) > 1200 || ! str_contains($source['text'], $excerpt)) {
                $reason = 'The cited excerpt could not be matched to a sufficiently specific, bounded passage in the retrieved article.';
            }
            if ($decisive && (in_array(false, $row['matches'], true) || $row['basis'] === 'background')) {
                $reason = 'The article does not address all material parts of the same claim.';
            }
            if ($decisive && preg_match('/\b(?:ruled|ruling|struck down|declared unconstitutional|nagpasya|pinawalang-bisa)\b/iu', $claim['text'])
                && preg_match('/\b(?:petition|challenge|challenged|asked the court|pending|hinamon)\b/iu', $excerpt)
                && ! preg_match('/\b(?:court (?:ruled|held|ordered)|decision (?:states|holds)|hukuman.*nagpasya)\b/iu', $excerpt)) {
                $reason = 'A legal challenge is not evidence of a final court decision.';
            }
            if ($decisive && preg_match('/\b(?:enacted|signed into law|became law|isinabatas)\b/iu', $claim['text'])
                && preg_match('/\b(?:proposed|proposal|filed a bill|panukala)\b/iu', $excerpt)
                && ! preg_match('/\b(?:signed into law|enacted|became law|isinabatas)\b/iu', $excerpt)) {
                $reason = 'A proposal does not establish that a law was enacted.';
            }
            if ($row['stance'] === 'supporting' && $cutoff !== null
                && $this->expiredBulletinForCurrentClaim($claim['text'], $excerpt, $source['url'] ?? '', $cutoff)) {
                $reason = 'An expired bulletin cannot establish that a warning remains in effect.';
            }
            if ($decisive && ($claim['current_status_question'] ?? false) && $cutoff !== null
                && ! empty($source['published_at']) && strtotime($source['published_at']) < strtotime($cutoff)
                && preg_match('/\bunless\b.{0,160}\b(?:law|decision|order|enact|postpon|cancel)\b/iu', $excerpt)) {
                $reason = 'A dated conditional status does not establish whether a later law or decision changed the event before the assessment cutoff.';
            }
            if ($reason) {
                $row['stance'] = 'insufficient';
                $row['validation_limitation'] = $reason;
                if (! str_contains($source['text'], $excerpt)) {
                    $row['excerpt'] = '';
                }
            }
            $superseded = [];
            foreach ($row['supersedes_source_ids'] as $id) {
                $older = $sources->get($id);
                if ($id !== $source['id'] && $older && $source['published_at'] && $older['published_at']
                    && strtotime($source['published_at']) > strtotime($older['published_at'])
                    && trim($row['supersession_excerpt']) !== '' && str_contains($source['text'], $row['supersession_excerpt'])
                    && preg_match('/\b(?:supersed|replac|repeal|correct|revers|amend|rescind|reset|reschedul|postpon|cancel|binawi|pinalitan|itinama)/iu', $row['supersession_excerpt'])) {
                    $superseded[] = $id;
                }
            }
            $row['supersedes_source_ids'] = $row['stance'] === 'insufficient' ? [] : $superseded;
            $key = $row['claim_id'].'|'.$row['source_id'];
            if (isset($valid[$key]) && $valid[$key]['stance'] !== $row['stance']) {
                $row['stance'] = 'insufficient';
                $row['validation_limitation'] = 'The analysis returned inconsistent relationships for this source.';
            }
            $valid[$key] = $row;
        }

        return array_values($valid);
    }

    private function expiredBulletinForCurrentClaim(string $claim, string $excerpt, string $url, string $cutoff): bool
    {
        if (! preg_match('/\b(?:today|now|currently|current|in effect|remains in effect|ngayon|kasalukuyan)\b/iu', $claim)
            || ! preg_match('/\b(?:valid (?:until|through)|in effect until)\s+((?:[A-Z][a-z]+\s+\d{1,2},?\s+\d{4}|\d{4}-\d{2}-\d{2})(?:\s+(?:at\s+)?\d{1,2}:\d{2}\s*(?:AM|PM)?)?)/iu', $excerpt, $match)) {
            return false;
        }
        try {
            $zone = str_ends_with((string) parse_url($url, PHP_URL_HOST), '.ph') ? 'Asia/Manila' : 'UTC';
            $until = Carbon::parse($match[1], $zone);
            if (! preg_match('/\d{1,2}:\d{2}/', $match[1])) {
                $until->endOfDay();
            }

            return $until->lt(Carbon::parse($cutoff));
        } catch (\Throwable) {
            return false;
        }
    }

    public function outcomes(array $claims, array $relationships): array
    {
        foreach ($claims as &$claim) {
            $rows = array_values(array_filter($relationships, fn ($r) => $r['claim_id'] === $claim['id']));
            $decisive = array_filter($rows, fn ($r) => $r['stance'] !== 'insufficient');
            $superseded = array_merge([], ...array_column($decisive, 'supersedes_source_ids'));
            foreach ($rows as &$row) {
                $row['superseded'] = in_array($row['source_id'], $superseded, true);
            }
            unset($row);
            $current = array_filter($rows, fn ($r) => ! in_array($r['source_id'], $superseded, true));
            $stances = array_unique(array_column($current, 'stance'));
            $support = in_array('supporting', $stances, true);
            $contradiction = in_array('contradicting', $stances, true);
            $claim['outcome'] = match (true) {
                $claim['requires_context'] || in_array($claim['kind'], ['rhetorical', 'unclear'], true) => 'needs_context',
                $claim['kind'] !== 'factual' => 'not_fact_checkable',
                $support && $contradiction => 'conflicting',
                $support => 'supported', $contradiction => 'refuted', default => 'insufficient',
            };
            $claim['relationships'] = $rows;
        }
        unset($claim);

        return $claims;
    }
}
