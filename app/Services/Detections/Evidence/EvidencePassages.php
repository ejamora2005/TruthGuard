<?php

namespace App\Services\Detections\Evidence;

use Illuminate\Support\Str;

/** Select literal source windows throughout an article, within the existing input budget. */
final class EvidencePassages
{
    public static function select(string $text, array $claims, int $bytes): array
    {
        if ($bytes >= strlen($text)) {
            return [$text];
        }
        if ($bytes < 1) {
            return [];
        }
        $terms = array_map(fn ($claim) => self::terms((string) ($claim['text'] ?? '')), $claims);
        $windows = [];
        // Overlap keeps a relevant sentence and its neighboring qualifications together.
        $windowSize = max(80, min(1800, intdiv($bytes, 2)));
        for ($offset = 0; $offset < mb_strlen($text); $offset += max(40, (int) ($windowSize * 2 / 3))) {
            $passage = mb_substr($text, $offset, $windowSize);
            $tokens = self::terms($passage);
            $scores = array_map(fn ($row) => count(array_intersect($row, $tokens)), $terms);
            $windows[] = ['offset' => $offset, 'text' => $passage, 'scores' => $scores,
                'score' => array_sum($scores)];
        }
        $ranked = [];
        // Reserve the best window for each assertion before filling remaining space.
        foreach (array_keys($terms) as $index) {
            $best = collect($windows)->sortByDesc(fn ($window) => $window['scores'][$index])->first();
            if (($best['scores'][$index] ?? 0) > 0) {
                $ranked[$best['offset']] = $best;
            }
        }
        foreach (collect($windows)->sortByDesc('score') as $window) {
            $ranked[$window['offset']] ??= $window;
        }
        $selected = [];
        $remaining = $bytes;
        foreach ($ranked as $window) {
            $separator = $selected === [] ? 0 : 2;
            if ($remaining <= $separator) {
                break;
            }
            $passage = mb_strcut($window['text'], 0, $remaining - $separator, 'UTF-8');
            if ($passage !== '') {
                $selected[] = $passage;
                $remaining -= strlen($passage) + $separator;
            }
        }

        return $selected;
    }

    private static function terms(string $text): array
    {
        $stop = ['the', 'and', 'that', 'this', 'with', 'from', 'was', 'were', 'will', 'for', 'are',
            'has', 'have', 'had', 'ang', 'mga', 'isang', 'claim', 'claims', 'post', 'posts'];

        return array_values(array_filter(array_diff(array_unique(preg_split('/[^\pL\pN]+/u', Str::lower($text), -1, PREG_SPLIT_NO_EMPTY) ?: []), $stop),
            fn ($term) => mb_strlen($term) > 2 || is_numeric($term)));
    }
}
