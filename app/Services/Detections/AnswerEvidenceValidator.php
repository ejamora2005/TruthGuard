<?php

namespace App\Services\Detections;

use App\Services\Detections\Evidence\StructuredAnalysis;
use App\Services\Detections\Evidence\SubmittedText;
use Illuminate\Support\Str;

/** Bounded statement checks, not an independent semantic truth classifier. */
final class AnswerEvidenceValidator
{
    public const VERSION = 'answer-evidence-v3';

    public static function schema(): array
    {
        return ['type' => 'array', 'maxItems' => 8, 'items' => StructuredAnalysis::object([
            'statement' => ['type' => 'string'], 'claim_id' => ['type' => 'string'],
            'source_id' => ['type' => 'string'], 'excerpt' => ['type' => 'string'],
        ])];
    }

    public static function applies(array $result): bool
    {
        return in_array($result['primary_task'] ?? 'claim', ['claim', 'context'], true)
            && (($result['sources'] ?? []) !== [] || ($result['contextual_sources'] ?? []) !== []);
    }

    /** Index only passages already admitted by evidence verification. */
    public static function passages(array $result): array
    {
        $units = [];
        foreach (array_merge($result['sources'] ?? [], $result['contextual_sources'] ?? []) as $source) {
            if (! is_array($source) || ! is_string($source['id'] ?? null)
                || ReportAnswer::safeUrl($source['url'] ?? null) === null) {
                continue;
            }
            $rows = $source['relationships'] ?? [];
            if (! empty($source['context_only'])) {
                $rows[] = $source;
            }
            foreach ($result['context_assessments'] ?? [] as $finding) {
                if (($finding['source_id'] ?? null) === $source['id']) {
                    $rows[] = $finding;
                }
            }
            foreach ($rows as $row) {
                $excerpt = $row['excerpt'] ?? '';
                if (! is_string($excerpt) || mb_strlen($excerpt) < 20 || mb_strlen($excerpt) > 1200
                    || ($row['superseded'] ?? false)
                    || (! isset($row['label']) && empty($source['context_only'])
                        && ! in_array($row['stance'] ?? '', ['supporting', 'contradicting'], true))) {
                    continue;
                }
                $units[] = ['source_id' => $source['id'], 'claim_id' => (string) ($row['claim_id'] ?? ''),
                    'excerpt' => $excerpt, 'publisher' => (string) ($source['name'] ?? ''),
                    'stance' => $row['stance'] ?? 'context'];
            }
        }

        return $units;
    }

    public static function review(array $result, array $candidate): array
    {
        $issues = [];
        $bindings = $candidate['statement_evidence'] ?? null;
        $units = self::passages($result);
        if (! StructuredAnalysis::valid($bindings, self::schema()) || $bindings === []) {
            return self::audit('rejected', ['missing_statement_evidence'], 0);
        }
        $sentences = SubmittedText::sentences((string) ($candidate['explanation'] ?? ''));
        if (array_map(self::normalize(...), $sentences)
            !== array_map(fn ($row) => self::normalize($row['statement']), $bindings)) {
            $issues[] = 'incomplete_statement_coverage';
        }
        $used = [];
        foreach ($bindings as $binding) {
            $excerpt = $binding['excerpt'];
            $unit = collect($units)->first(fn ($unit) => $unit['source_id'] === $binding['source_id']
                && $unit['claim_id'] === $binding['claim_id']
                && mb_strlen($excerpt) >= 20 && str_contains(Str::squish($unit['excerpt']), Str::squish($excerpt)));
            if (! $unit) {
                $issues[] = 'unvalidated_or_misattributed_passage';

                continue;
            }
            $used[] = $binding['source_id'];
            // A number printed in the claim or another source cannot license
            // adding it to this statement. Currency, magnitude and units matter.
            if (array_diff(self::numbers($binding['statement']), self::numbers($excerpt))) {
                $issues[] = 'unsupported_number_or_unit';
            }
            preg_match_all('/[“"]([^”"]{8,})[”"]/u', $binding['statement'], $quotes);
            foreach ($quotes[1] as $quote) {
                if (! str_contains(self::normalize($excerpt), self::normalize($quote))) {
                    $issues[] = 'unfaithful_quotation';
                }
            }
            $statementWords = self::words($binding['statement']);
            $evidenceWords = self::words($excerpt.' '.$unit['publisher']);
            $overlap = count(array_intersect($statementWords, $evidenceWords)) / max(1, count($statementWords));
            if (count($statementWords) >= 3 && $overlap < .5) {
                $issues[] = 'statement_not_anchored_to_passage';
            }
            if ($overlap >= .75 && self::negated($binding['statement']) !== self::negated($excerpt)) {
                $issues[] = 'possible_reversed_negation';
            }
            if (preg_match('/\b(?:estimate|estimated|approximately|roughly|might|could|maybe|baka|tantiya|tinataya)\b/iu', $excerpt)
                && self::numbers($binding['statement']) !== []
                && ! preg_match('/\b(?:estimate|estimated|approximately|roughly|might|could|maybe|baka|tantiya|tinataya)\b/iu', $binding['statement'])) {
                $issues[] = 'estimate_presented_as_established_value';
            }
        }
        if (array_diff($used, $candidate['source_ids'] ?? []) || array_diff($candidate['source_ids'] ?? [], $used)) {
            $issues[] = 'citation_binding_mismatch';
        }

        return self::audit($issues === [] ? 'passed_bounded_checks' : 'rejected', array_values(array_unique($issues)), count($bindings));
    }

    /** Extractive fallback cannot invent a new price, identity, date or calculation. */
    public static function fallback(array $result): ?array
    {
        $units = self::passages($result);
        if ($units === []) {
            return null;
        }
        // On a conflict retain both directions when present, within the same
        // display allowance; never silently quote only the supporting side.
        if (($result['verdict'] ?? '') === 'CONFLICTING_EVIDENCE') {
            $support = collect($units)->firstWhere('stance', 'supporting');
            $contradiction = collect($units)->firstWhere('stance', 'contradicting');
            $units = array_values(array_filter([$support, $contradiction]));
        }
        $details = [];
        $sourceIds = [];
        $selected = [];
        $seen = [];
        foreach ($units as $unit) {
            $quote = Str::squish($unit['excerpt']);
            $key = self::normalize($quote);
            if (isset($seen[$key])) {
                continue;
            }
            $name = Str::limit(Str::squish(strip_tags($unit['publisher'])), 80, '');
            $name = preg_replace('/^www\./iu', '', $name);
            $detail = self::reviewedExplanation($result, $unit)
                ?? ($name !== '' ? $name : 'The cited source').' states: “'.$quote.'”';
            if (preg_match('~https?://|www\.|<[^>]+>~iu', $detail)
                || Str::wordCount(implode(' ', [...$details, $detail])) > ResultWording::MAX_EXPLANATION_WORDS - 10
                || mb_strlen(implode(' ', [...$details, $detail])) > ResultWording::MAX_EXPLANATION_CHARACTERS) {
                continue;
            }
            $details[] = $detail;
            $sourceIds[] = $unit['source_id'];
            $selected[] = $unit;
            $seen[$key] = true;
            if (count($details) >= (($result['verdict'] ?? '') === 'CONFLICTING_EVIDENCE' ? 2 : 3)) {
                break;
            }
        }
        if ($details === [] || (($result['verdict'] ?? '') === 'CONFLICTING_EVIDENCE' && count($details) !== 2)) {
            // Fall back to the known classification only when a complete quote
            // cannot fit; do not silently truncate its material qualifications.
            return ['explanation' => AssessmentVerdict::meaning($result['verdict']),
                'source_ids' => array_values(array_unique(array_column($units, 'source_id')))];
        }

        return ['explanation' => self::withScope($result, implode(' ', $details), $selected),
            'source_ids' => array_values(array_unique($sourceIds))];
    }

    /** Explain the scope of literal evidence without inventing additional facts. */
    private static function withScope(array $result, string $detail, array $units): string
    {
        if (Str::wordCount($detail) >= 100) {
            return $detail;
        }
        $additions = [];
        $claim = Str::squish(strip_tags((string) ($result['claim'] ?? '')));
        if ($claim !== '' && mb_strlen($claim) <= 300 && ! preg_match('~https?://|www\.~iu', $claim)
            && ! str_contains(self::normalize($detail), self::normalize($claim))) {
            $additions[] = 'The specific statement being checked is “'.rtrim($claim, ' .!?').'”.';
        }
        $absence = collect($units)->contains(fn ($unit) => (bool) preg_match(
            '/\b(?:no (?:official )?(?:record|evidence|confirmation)|(?:record|evidence) (?:was |were )?not found)\b/iu', $unit['excerpt']));
        if ($absence) {
            $additions[] = 'A statement that no record or confirmation was found describes the available documentation; by itself, it does not prove that an event never occurred.';
            $additions[] = 'The dated statement should be read within the period and subject it covers, without extending it to later events or details it does not establish.';
        } else {
            $stances = array_values(array_unique(array_column($units, 'stance')));
            $additions[] = match (true) {
                $stances === ['supporting'] => 'The cited evidence supports this specific statement, rather than merely mentioning the same topic.',
                $stances === ['contradicting'] => 'The material difference between the statement and the cited evidence is the basis for the contradiction described here.',
                in_array('supporting', $stances, true) && in_array('contradicting', $stances, true) => 'The cited accounts point in different directions, so the disagreement must remain visible rather than treating either account as settled.',
                default => 'The cited material provides context for this statement, but context alone does not confirm all of its details.',
            };
            $additions[] = 'This finding applies to the subject and details addressed by the cited material; it does not establish other claims, dates, or quantities that the source does not cover.';
        }
        if (collect(data_get($result, 'interpretation.ambiguities', []))->contains(
            fn ($text) => is_string($text) && (bool) preg_match('/does not (?:state|specify).*(?:when|date)/iu', $text))) {
            $additions[] = 'The submission does not give the original post date, which limits comparison with a dated statement.';
        }
        if (preg_match('/\b(?:image|video|frames)\b/iu', (string) data_get($result, 'extraction.input_scope', ''))) {
            $additions[] = 'This factual assessment concerns the accompanying claim; it does not independently determine whether the media was generated or edited with AI.';
        }
        $text = $detail;
        foreach ($additions as $sentence) {
            $expanded = $text.' '.$sentence;
            if (Str::wordCount($expanded) <= ResultWording::MAX_EXPLANATION_WORDS
                && mb_strlen($expanded) <= ResultWording::MAX_EXPLANATION_CHARACTERS) {
                $text = $expanded;
            }
        }

        return $text;
    }

    /** Reuse already reviewed reasoning, never a new model rewrite or an unchecked summary. */
    private static function reviewedExplanation(array $result, array $unit): ?string
    {
        $source = collect($result['sources'] ?? [])->firstWhere('id', $unit['source_id']);
        $claim = collect($result['claims'] ?? [])->firstWhere('id', $unit['claim_id']);
        if (! $source || ! $claim || ! in_array($claim['outcome'] ?? '', ['supported', 'refuted', 'conflicting'], true)) {
            return null;
        }
        $row = collect($claim['relationships'] ?? [])->first(fn ($row) => ($row['source_id'] ?? '') === $unit['source_id']
            && ($row['excerpt'] ?? '') === $unit['excerpt'] && ($row['stance'] ?? '') === $unit['stance']
            && ! ($row['superseded'] ?? false));
        if (! $row || ! in_array($row['basis'] ?? '', ['primary_record', 'direct_reporting', 'fact_check'], true)
            || collect(['event', 'entities', 'action', 'date', 'location', 'quantities', 'qualifiers'])
                ->contains(fn ($dimension) => data_get($row, 'matches.'.$dimension) !== true)) {
            return null;
        }
        if (($claim['outcome'] === 'supported' && $unit['stance'] !== 'supporting')
            || ($claim['outcome'] === 'refuted' && $unit['stance'] !== 'contradicting')) {
            return null;
        }
        $text = ResultContract::publicDetail($row['explanation'] ?? null);
        if (! $text || count(SubmittedText::sentences($text)) < 2
            || Str::wordCount($text) > 190 || mb_strlen($text) > 2800
            || preg_match('~https?://|www\.|<[^>]+>~iu', $text)
            || array_diff(self::numbers($text), self::numbers($unit['excerpt']))) {
            return null;
        }
        preg_match_all('/[“"]([^”"]{8,})[”"]/u', $text, $quotes);
        foreach ($quotes[1] as $quote) {
            if (! str_contains(self::normalize($unit['excerpt']), self::normalize($quote))) {
                return null;
            }
        }
        if ($unit['stance'] === 'supporting' && self::negated($text) !== self::negated($unit['excerpt'])) {
            return null;
        }
        // Legacy HTML text can join a name to row numbers/coordinates. Separate
        // those token boundaries for anchoring, without changing the stored quote.
        $anchors = preg_replace('/(?<=\pL)(?=\pN)|(?<=\pN)(?=\pL)/u', ' ',
            $unit['excerpt'].' '.$unit['publisher'].' '.($source['title'] ?? '').' '.($claim['text'] ?? ''));
        $anchorWords = self::words($anchors);
        preg_match_all('/\b[A-Z][A-Za-z-]{2,}\b/u', $text, $names);
        $connectors = ['the', 'this', 'that', 'these', 'those', 'according', 'because', 'however', 'therefore', 'instead'];
        foreach ($names[0] as $name) {
            $name = Str::lower($name);
            if (! in_array($name, $connectors, true) && ! collect($anchorWords)->contains(
                fn ($anchor) => $anchor === $name || $anchor === $name.'s' || $anchor === $name.'es')) {
                return null; // Do not introduce an unbound person, place or institution.
            }
        }
        $terms = array_diff(self::words($text), ['does', 'do', 'has', 'have', 'its', 'same', 'source', 'record', 'records',
            'official', 'list', 'lists', 'listing', 'place', 'places', 'located', 'location', 'reported', 'reports',
            'shows', 'identifies', 'states', 'according', 'reviewed', 'instead', 'rather', 'contradicts', 'contradict',
            'supports', 'support', 'confirms', 'directly', 'claim', 'statement', 'post', 'image', 'about', 's', 'not']);
        if (count(array_intersect($terms, $anchorWords)) / max(1, count($terms)) < .6) {
            return null;
        }
        // Existing wording carries the reasoning and scope. Keep it intact and
        // attach the same authenticated source ID rather than displaying a raw row.
        $host = (string) parse_url((string) ($source['url'] ?? ''), PHP_URL_HOST);
        $label = preg_replace('/^www\./i', '', trim((string) $unit['publisher']));
        $institution = explode('.', preg_replace('/^www\./i', '', $host))[0];
        if ($institution !== '' && str_contains(Str::lower($text), Str::lower($institution))) {
            return $text;
        }

        return ($label !== '' ? 'According to '.$label.', ' : '').lcfirst($text);
    }

    public static function numbers(string $text): array
    {
        $text = strtr(mb_strtolower($text), ['₱' => 'php ', '$' => 'dollar ', '€' => 'eur ', '£' => 'gbp ', '¥' => 'yen ']);
        $text = preg_replace('/(?<=\d),(?=\d{3}(?:\D|$))/', '', $text);
        $text = preg_replace('/(?<=\d)[-–](?=(?:million|billion|thousand|percent)\b)/u', ' ', $text);
        preg_match_all('/(?:(?:php|usd|eur|gbp|yen|dollar|p)\s*)?\d+(?:\.\d+)?(?:\s*(?:trillion|billion|million|thousand|percent|pesos?|dollars?|gallons?|liters?|litres?|kilometers?|kilometres?|km|kg|%))?/u', $text, $matches);

        return array_values(array_unique(array_map(fn ($number) => preg_replace('/\s+/u', '', $number), $matches[0])));
    }

    private static function normalize(string $text): string
    {
        return Str::lower(Str::squish($text));
    }

    private static function words(string $text): array
    {
        $words = preg_split('/[^\pL\pN]+/u', self::normalize($text), -1, PREG_SPLIT_NO_EMPTY);
        $stop = ['the', 'a', 'an', 'of', 'to', 'for', 'by', 'in', 'on', 'at', 'is', 'was', 'were', 'are', 'it',
            'this', 'that', 'these', 'those', 'and', 'but', 'with', 'from', 'as', 'each', 'per', 'ang', 'ng', 'sa', 'mga', 'ay'];

        return array_values(array_unique(array_diff($words, $stop)));
    }

    private static function negated(string $text): bool
    {
        return (bool) preg_match('/\b(?:not|no|never|neither|without|hindi|wala|walang)\b/iu', $text);
    }

    private static function audit(string $status, array $issues, int $count): array
    {
        return ['version' => self::VERSION, 'status' => $status, 'issues' => $issues,
            'statement_count' => $count,
            'scope' => 'Passage bindings, numerical units, quotation fidelity, lexical anchors and selected qualifier checks; not full semantic entailment or measured accuracy.'];
    }
}
