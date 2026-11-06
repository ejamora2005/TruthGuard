<?php

namespace App\Services\Detections;

use Illuminate\Support\Str;

/** Deterministic gate between a submitted claim and a publisher review. */
final class FactCheckClaimMatcher
{
    public const VERSION = 'structured-claim-match-v1';

    /** @return array<string, mixed> */
    public function assess(string $submitted, string $reviewed): array
    {
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
        $entityMatch = $entities === [] ? count($overlap) >= 2 : $entityOverlap !== [];
        $actionMatch = $actions === [] ? $ratio >= .5 : $actionOverlap !== [];
        $negationConflict = $this->hasNegation($submitted) xor $this->hasNegation($reviewed);

        $level = match (true) {
            $dateConflict => 'unrelated',
            $entityMatch && $actionMatch && count($overlap) >= 2 && $ratio >= .40 => 'exact',
            $entityMatch && ($actionMatch || $ratio >= .25) => 'related',
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
            'matched_terms' => $overlap,
            'canonical_key' => hash('sha256', implode('|', array_values(array_unique(array_merge($entities, $actions, $submittedYears))))),
        ];
    }

    /** @return array<int, string> */
    private function tokens(string $text): array
    {
        $text = Str::lower(Str::ascii($text));
        $aliases = [
            '/\b(?:sarado|close|closed|closure|shut|shutdown|off air)\b/' => ' shutdown ',
            '/\b(?:korte|court)\b/' => ' court ',
            '/\b(?:hatol|order|ordered|ruling|decision)\b/' => ' ruling ',
            '/\b(?:peke|fake|false|untrue)\b/' => ' false ',
            '/\b(?:resolusyon|resolution)\b/' => ' resolution ',
            '/\b(?:patayin|shoot to kill|shoot-to-kill)\b/' => ' kill ',
        ];
        $text = preg_replace(array_keys($aliases), array_values($aliases), $text) ?? $text;
        $parts = preg_split('/[^a-z0-9]+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $stop = array_flip(['the', 'a', 'an', 'and', 'or', 'of', 'to', 'for', 'in', 'on', 'at', 'by', 'from',
            'is', 'are', 'was', 'were', 'will', 'be', 'has', 'have', 'had', 'this', 'that', 'na', 'ang', 'sa',
            'ng', 'mga', 'kay', 'ni', 'si', 'dahil', 'pala', 'vs', 'fact', 'check', 'claim', 'post', 'posts']);

        return array_values(array_unique(array_filter($parts, fn (string $token): bool => strlen($token) >= 2 && ! isset($stop[$token]))));
    }

    /** @param array<int, string> $tokens @return array<int, string> */
    private function anchors(array $tokens): array
    {
        $generic = array_flip(array_merge($this->actionTerms(), ['false', 'true', 'missing', 'context', 'news', 'network']));

        return array_values(array_filter($tokens, fn (string $token): bool => strlen($token) >= 3
            && ! isset($generic[$token]) && ! preg_match('/^(?:19|20)\d{2}$/', $token)));
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
}
