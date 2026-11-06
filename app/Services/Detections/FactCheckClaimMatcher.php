<?php

namespace App\Services\Detections;

use Illuminate\Support\Str;

/** Deterministic gate between a submitted claim and a publisher review. */
final class FactCheckClaimMatcher
{
    public const VERSION = 'structured-claim-match-v2';

    /** @return array<string, mixed> */
    public function assess(string $submitted, string $reviewed): array
    {
        $submitted = $this->proposition($submitted);
        $reviewed = $this->proposition($reviewed);
        $submittedTokens = $this->tokens($submitted);
        $reviewedTokens = $this->tokens($reviewed);
        $overlap = array_values(array_intersect($submittedTokens, $reviewedTokens));
        $ratio = $submittedTokens === [] ? 0.0 : count($overlap) / count($submittedTokens);
        $submittedYears = $this->years($submitted);
        $reviewedYears = $this->years($reviewed);
        $dateConflict = $submittedYears !== [] && $reviewedYears !== []
            && array_intersect($submittedYears, $reviewedYears) === [];
        $entities = $this->anchors($submittedTokens);
        $entityOverlap = array_values(array_intersect($entities, $reviewedTokens));
        $actions = array_values(array_intersect($submittedTokens, $this->actionTerms()));
        $actionOverlap = array_values(array_intersect($actions, $reviewedTokens));
        // Noun phrases such as "hatol sa korte" and "court ruling" reverse
        // word order without reversing who performed an action.
        $orderedActions = array_values(array_diff($actions, ['shutdown', 'court', 'ruling', 'resolution', 'kill']));
        $actionOrderMatch = array_values(array_intersect($reviewedTokens, $orderedActions)) === $orderedActions;
        $entityMatch = $entities !== [] && count($entityOverlap) === count($entities);
        $actionMatch = $actions === [] ? $ratio >= .8 : count($actionOverlap) === count($actions);
        $negationConflict = $this->hasNegation($submitted) !== $this->hasNegation($reviewed);
        $numbersMatch = $this->numbers($submitted) === $this->numbers($reviewed);
        $qualifiersMatch = $this->qualifiers($submitted) === $this->qualifiers($reviewed);
        // Shared names in a different order can reverse who acted against whom.
        $orderedEntities = array_values(array_intersect($reviewedTokens, $entities));
        $rolesMatch = $orderedEntities === $entities;
        $reviewedEntitiesMatch = array_diff($this->anchors($reviewedTokens), $entities) === [];

        $level = match (true) {
            $dateConflict => 'unrelated',
            $entityMatch && $reviewedEntitiesMatch && $actionMatch && $actionOrderMatch && $rolesMatch && ! $negationConflict
                && $numbersMatch && $qualifiersMatch && count($overlap) >= 2 && $ratio >= .65 => 'exact',
            $entityOverlap !== [] && ($actionOverlap !== [] || $ratio >= .25) => 'related',
            default => 'unrelated',
        };

        return [
            'version' => self::VERSION,
            'level' => $level,
            'score' => (int) round($ratio * 100),
            'entity_match' => $entityMatch,
            'action_match' => $actionMatch,
            'date_match' => ! $dateConflict,
            'negation_conflict' => $negationConflict,
            'numbers_match' => $numbersMatch,
            'qualifiers_match' => $qualifiersMatch,
            'roles_match' => $rolesMatch,
            'action_order_match' => $actionOrderMatch,
            'matched_terms' => $overlap,
            'canonical_key' => hash('sha256', implode('|', array_values(array_unique(array_merge($entities, $actions, $submittedYears))))),
        ];
    }

    /** Strip explicit publisher rating wrappers, never a denial inside a proposition. */
    private function proposition(string $text): string
    {
        $text = Str::ascii($text);
        $text = preg_replace('/\b(\w+)\'s\b/i', '$1', $text) ?? $text;
        $text = preg_replace('/^\s*(?:fact[ -]?check\s*:\s*)?(?:false|fake|true|misleading)\s*:\s*/i', '', $text) ?? $text;
        if (preg_match('/^\s*(?:fact[ -]?check\s*:\s*)?posts?\s+(?:on|about)\s+(.+?)\s+(?:fake|false|true|misleading)[.!?]*\s*$/i', $text, $match)) {
            return $match[1];
        }

        return $text;
    }

    /** @return array<int, string> */
    private function tokens(string $text): array
    {
        $text = Str::lower(Str::ascii($text));
        $aliases = [
            '/\b(?:sarado|close|closed|closure|shut down|shut|shutdown|off air)\b/' => ' shutdown ',
            '/\b(?:korte|court)\b/' => ' court ',
            '/\b(?:hatol|order|ordered|ruling|decision)\b/' => ' ruling ',
            '/\b(?:peke|fake|false|untrue)\b/' => ' false ',
            '/\b(?:resolusyon|resolution)\b/' => ' resolution ',
            '/\b(?:patayin|shoot to kill|shoot-to-kill)\b/' => ' kill ',
            '/\b(?:file|files|filed|filing)\b/' => ' filed ',
        ];
        $text = preg_replace(array_keys($aliases), array_values($aliases), $text) ?? $text;
        $parts = preg_split('/[^a-z0-9]+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $stop = array_flip(['the', 'a', 'an', 'and', 'or', 'of', 'to', 'for', 'in', 'on', 'at', 'by', 'from',
            'is', 'are', 'was', 'were', 'will', 'be', 'has', 'have', 'had', 'this', 'that', 'due', 'na', 'ang', 'sa',
            'ng', 'mga', 'kay', 'ni', 'si', 'dahil', 'pala', 'vs', 'against', 'rep', 'representative',
            'fact', 'check', 'claim', 'post', 'posts']);

        return array_values(array_unique(array_filter($parts, fn (string $token): bool => strlen($token) >= 2 && ! isset($stop[$token]))));
    }

    /** @param array<int, string> $tokens @return array<int, string> */
    private function anchors(array $tokens): array
    {
        $generic = array_flip(array_merge($this->actionTerms(), ['false', 'true', 'missing', 'context', 'news', 'network',
            'not', 'never', 'no', 'hindi', 'wala', 'walang', 'did', 'does']));

        return array_values(array_filter($tokens, fn (string $token): bool => strlen($token) >= 3
            && ! isset($generic[$token]) && ! is_numeric($token)));
    }

    /** @return array<int, string> */
    private function actionTerms(): array
    {
        return ['shutdown', 'court', 'ruling', 'resolution', 'kill', 'arrest', 'visit', 'filed', 'signed',
            'approved', 'cancelled', 'suspended', 'impeached', 'resigned', 'elected', 'appointed'];
    }

    /** @return array<int, string> */
    private function years(string $text): array
    {
        preg_match_all('/\b(?:19|20)\d{2}\b/', $text, $matches);

        return array_values(array_unique($matches[0] ?? []));
    }

    private function hasNegation(string $text): bool
    {
        return (bool) preg_match('/\b(?:no|not|never|false|fake|hindi|wala|walang|di|didn.t|doesn.t)\b/iu', $text);
    }

    private function numbers(string $text): array
    {
        preg_match_all('/(?<![\pL\pN])[-+]?\d+(?:[.,:\/-]\d+)*%?/u', $text, $matches);

        return $matches[0];
    }

    private function qualifiers(string $text): array
    {
        preg_match_all('/\b(?:only|all|some|every|allegedly|reportedly|possible|proposed|at least|at most|more than|less than|before|after|pending|final|national|local|lamang|lahat|umano)\b/iu', $text, $matches);
        $qualifiers = array_values(array_unique(array_map('strtolower', $matches[0])));
        sort($qualifiers);

        return $qualifiers;
    }
}
