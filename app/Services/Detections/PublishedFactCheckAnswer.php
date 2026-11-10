<?php

namespace App\Services\Detections;

use Illuminate\Support\Str;

/** Present the collected publisher's answer without making a new assessment. */
final class PublishedFactCheckAnswer
{
    public static function fromFeedItem(array $item): array
    {
        $publisher = self::text($item['publisher'] ?? null) ?? 'the publisher';
        $url = ReportAnswer::safeUrl($item['url'] ?? null);
        $rating = self::text($item['source_rating'] ?? null);
        // The feed's normalized rating may be inferred from its headline. Only
        // an explicitly collected source rating can be called a publisher rating.
        if ($rating && in_array(Str::lower($rating), ['reviewed', 'unrated', 'rating unavailable', 'unknown'], true)) {
            $rating = null;
        }
        $headline = self::text($item['full_headline'] ?? null) ?? self::text($item['headline'] ?? null);
        if (in_array(Str::lower($headline ?? ''), ['fact-check result', 'fact check result'], true)) {
            $headline = null;
        }

        $paragraphs = [];
        if ($url && $rating) {
            $paragraphs[] = 'According to '.$publisher.', the claim reviewed in this fact-check was rated “'.$rating.'”.';
        }
        if ($url && $headline) {
            // A headline is attributed as a headline, never expanded into an
            // invented correction, quotation from the article, or proof.
            $paragraphs[] = ($rating ? 'The published finding is titled' : $publisher.'’s published fact-check is titled').' “'.$headline.'”.';
        }
        if ($paragraphs === []) {
            $paragraphs[] = 'The collected record does not contain enough publisher information for a ready factual answer.';
        }

        return [
            'basis' => 'Collected publisher record',
            'status' => $url && $rating ? 'published_rating' : 'limited_record',
            'paragraphs' => $paragraphs,
            'limitation' => ! $url ? 'A valid fact-check source link was not collected.'
                : (! $rating ? 'A separate publisher rating was not collected; no rating has been inferred.' : null),
            'notice' => 'This is the publisher’s collected finding, not a new TruthGuard assessment. Open the source for its full evidence and explanation.',
            'recommended_action' => 'Compare the exact claim, date and context with the original fact-check before sharing.',
            'source_url' => $url,
            'source_label' => 'Read the fact-check from '.$publisher,
        ];
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = Str::squish(strip_tags(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        return $value !== '' ? $value : null;
    }
}
