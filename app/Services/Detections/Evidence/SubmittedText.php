<?php

namespace App\Services\Detections\Evidence;

/** Whitespace repair only: never supplies missing OCR words or paraphrases claims. */
final class SubmittedText
{
    public static function paragraphs(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim($text));
        $blocks = preg_split('/\n\s*\n/u', $text) ?: [];
        $paragraphs = [];
        foreach ($blocks as $block) {
            $lines = array_values(array_filter(array_map('trim', explode("\n", $block)), fn ($s) => $s !== ''));
            $paragraph = '';
            foreach ($lines as $line) {
                // OCR often places a short isolated publisher mark after a long
                // display headline. Keep the token verbatim, but as its own
                // region so it cannot become the final word of the headline.
                if ($paragraph !== '' && mb_strlen($paragraph) > 35
                    && mb_strlen($line) <= 4 && preg_match('/^[\p{Lu}]{2,4}$/u', $line)) {
                    $paragraphs[] = $paragraph;
                    $paragraph = $line;

                    continue;
                }
                // OCR may wrap a single uppercase headline across several lines.
                if ($paragraph !== '' && mb_strtoupper($paragraph) === $paragraph
                    && mb_strtoupper($line) === $line
                    && preg_match('/\pL/u', $paragraph) && preg_match('/\pL/u', $line)) {
                    $paragraph .= ' '.$line;

                    continue;
                }
                // Keep headings/sentence boundaries; join the wrapped parts of a sentence.
                if ($paragraph !== '' && (preg_match('/[.!?]["”\']?$/u', $paragraph)
                    || (mb_strtoupper($paragraph) === $paragraph && preg_match('/\pL/u', $paragraph)))) {
                    $paragraphs[] = $paragraph;
                    $paragraph = '';
                }
                $paragraph .= ($paragraph === '' ? '' : ' ').$line;
            }
            if ($paragraph !== '') {
                $paragraphs[] = $paragraph;
            }
        }

        return implode("\n\n", $paragraphs);
    }

    public static function sentences(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\n\s*\n|(?<=[.!?])\s+(?=[\p{Lu}“"])/u', $text) ?: [])));
    }
}
