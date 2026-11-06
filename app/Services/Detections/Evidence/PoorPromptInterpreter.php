<?php

namespace App\Services\Detections\Evidence;

use Illuminate\Support\Str;

/** Best-effort interpretation used for discovery only; never replaces submitted evidence. */
final class PoorPromptInterpreter
{
    public const VERSION = 'poor-prompt-v2';

    public function interpret(string $text): array
    {
        $original = Str::squish($text);
        $expanded = $this->expandAliases($original);
        $short = Str::wordCount($original) <= 14;
        $rated = (bool) preg_match('/\b(?:fake|false|true|misleading|outdated|peke)\s*[.!?]*$/iu', $original);
        $interpretation = $expanded;
        if ($short && $rated) {
            $interpretation = 'The circulating post or claim described by “'.$expanded.'” has the displayed fact-check rating.';
        } elseif ($short && ! preg_match('/\b(?:is|was|are|were|did|does|has|have|said|says|filed|proposed|approved|killed|shot)\b/iu', $original)) {
            $interpretation = 'A circulating claim connects the named people, event, or action in “'.$expanded.'”.';
        }
        $queries = array_values(array_unique(array_filter([
            Str::limit($original, 180, ''),
            $expanded !== $original ? Str::limit($expanded, 180, '') : null,
            $rated ? Str::limit(preg_replace('/\b(?:fake|false|true|misleading|outdated|peke)\s*[.!?]*$/iu', 'fact check', $expanded), 180, '') : null,
            $this->keywordQuery($expanded),
        ])));

        return ['original_text' => $original, 'interpreted_claim' => $interpretation,
            'assumption_used' => $interpretation !== $original, 'ambiguities' => $this->ambiguities($original),
            'searches' => $queries];
    }

    private function expandAliases(string $text): string
    {
        $aliases = preg_match('/\b(?:suan|shoot|kill|resolution)\b/iu', $text)
            ? ['Bato dela Rosa' => 'Ronald “Bato” dela Rosa', 'Bato' => 'Ronald “Bato” dela Rosa', 'Suan' => 'Lordan Suan'] : [];
        $aliases += ['BBM' => 'Ferdinand Marcos Jr.', 'PRRD' => 'Rodrigo Duterte',
            'COMELEC' => 'Commission on Elections', 'DOH' => 'Department of Health',
            'DepEd' => 'Department of Education', 'PAGASA' => 'PAGASA weather agency',
            'PHIVOLCS' => 'PHIVOLCS earthquake and volcano agency'];
        // Match complete names before nicknames, in one pass. Sequential
        // replacements duplicated surnames and re-expanded names already present.
        $lookup = array_change_key_case($aliases, CASE_LOWER);
        $phrases = array_unique([...array_keys($aliases), ...array_values($aliases)]);
        usort($phrases, fn ($left, $right) => mb_strlen($right) <=> mb_strlen($left));
        $fullBatoName = "Ronald\\s+[\"“‘']?Bato[\"”’']?\\s+dela\\s+Rosa";
        $pattern = '/\b(?:'.$fullBatoName.'|'.implode('|', array_map(fn ($phrase) => preg_quote($phrase, '/'), $phrases)).')\b/iu';
        $text = preg_replace_callback($pattern, fn ($match) => $lookup[Str::lower($match[0])] ?? $match[0], $text);

        return Str::squish($text);
    }

    private function keywordQuery(string $text): string
    {
        $words = preg_split('/\s+/u', preg_replace('/[^\pL\pN“”_-]+/u', ' ', $text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $stop = ['the', 'a', 'an', 'is', 'was', 'are', 'were', 'this', 'that', 'posts', 'post', 'claim', 'about', 'vs', 'and', 'or'];
        $words = array_values(array_filter($words, fn ($word) => mb_strlen($word) > 2 && ! in_array(Str::lower($word), $stop, true)));

        return Str::limit(implode(' ', array_slice($words, 0, 10)), 140, '');
    }

    private function ambiguities(string $text): array
    {
        $items = [];
        if (! preg_match('/\b(?:19|20)\d{2}\b|\b(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\b/iu', $text)) {
            $items[] = 'The submission does not state when the claim or post appeared.';
        }
        if (Str::wordCount($text) <= 5) {
            $items[] = 'The wording is very short, so the exact asserted action may be ambiguous.';
        }

        return $items;
    }
}
