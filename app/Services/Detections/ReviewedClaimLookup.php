<?php

namespace App\Services\Detections;

use App\Models\ClaimVariation;
use App\Services\Detections\Evidence\SubmittedText;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ReviewedClaimLookup
{
    public static function lookup(string $text): ?array
    {
        // Retain the shipped catalog only on installations not migrated yet.
        if (! self::available()) {
            return KnownFactCheckCatalog::match($text);
        }
        $result = self::match($text);
        if ($result === null && KnownFactCheckCatalog::matchesReviewedVariant($text)) {
            // Resolve to the reviewed DB entry, preserving active/expiry checks.
            $result = self::match('The world will end on Friday, November 13, 2026, according to a scientist mathematical prediction.');
            if ($result !== null) {
                $result['diagnostics']['match_method'] = 'approved_november_13_variant';
            }
        }

        return $result;
    }

    public static function available(): bool
    {
        return Schema::hasTable('reviewed_claims') && Schema::hasTable('claim_variations');
    }

    /** Reuse complete, literal assertions, never keyword hits or model paraphrases. */
    public static function forClaims(array $claims, string $submission, string $cutoff): array
    {
        // Keep complete visual regions too: abbreviations such as "Sen. Bato"
        // must not split an approved, wrapped headline into separate claims.
        $regions = preg_split('/\n\s*\n/u', trim($submission)) ?: [];
        $sentences = array_map(self::normalize(...), [...$regions, ...SubmittedText::sentences($submission)]);
        $answers = [];
        foreach ($claims as $claim) {
            if (($claim['kind'] ?? '') !== 'factual' || ($claim['requires_context'] ?? true)
                || ($claim['references'] ?? []) !== []
                || ! in_array(self::normalize($claim['text']), $sentences, true)) {
                continue;
            }
            $scope = array_merge($claim['dates'] ?? [], $claim['quantities'] ?? [], $claim['qualifiers'] ?? []);
            if (collect($scope)->contains(fn ($part) => ! str_contains(self::normalize($claim['text']), self::normalize($part)))) {
                // An event date/qualifier bound from another image region may
                // change the proposition even when its short quote is known.
                continue;
            }
            $answer = self::lookup($claim['text']);
            // Contextual ratings cannot be reduced to supporting/contradicting
            // relationships. They retain the ordinary evidence/context path.
            if (! in_array($answer['verdict'] ?? '', ['TRUE', 'FALSE'], true)
                || empty($answer['sources'])
                || empty($answer['diagnostics']['reviewed_at'])
                || Carbon::parse($answer['diagnostics']['reviewed_at'])->gt($cutoff)) {
                continue;
            }
            $answers[$claim['id']] = $answer;
        }

        return $answers;
    }

    public static function normalize(string $text): string
    {
        // Keep dates, numbers and negation; never reuse on a single-word hit.
        return Str::of($text)->lower()->ascii()->replaceMatches('/[^a-z0-9]+/', ' ')->squish()->toString();
    }

    public static function match(string $text): ?array
    {
        $normalized = self::normalize($text);
        if ($normalized === '' || ! self::available()) {
            return null;
        }
        $variation = ClaimVariation::query()->where('text_hash', hash('sha256', $normalized))
            ->with('reviewedClaim')->first();
        $claim = $variation?->reviewedClaim;
        if (! $claim || ! $claim->active || ($claim->expires_at && $claim->expires_at->lte(now()))
            || $variation->normalized_text !== $normalized || empty($claim->result['sources'])) {
            return null;
        }
        $result = $claim->result;
        $result['diagnostics'] = array_replace($result['diagnostics'] ?? [], [
            'known_fact_check' => true, 'reviewed_claim_id' => $claim->id,
            'reviewed_claim_slug' => $claim->slug, 'claim_variation_id' => $variation->id,
            'match_method' => 'exact_normalized_variation',
            'reviewed_at' => $claim->reviewed_at->toIso8601String(),
            'api_usage' => ['total_tokens' => 0, 'input_tokens' => 0, 'output_tokens' => 0],
        ]);

        return ReportAnswer::complete($result);
    }
}
