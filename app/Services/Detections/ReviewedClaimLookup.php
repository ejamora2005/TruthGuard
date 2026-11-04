<?php

namespace App\Services\Detections;

use App\Models\ClaimVariation;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ReviewedClaimLookup
{
    public static function lookup(string $text): ?array
    {
        // Retain the shipped catalog only on installations not migrated yet.
        return self::available() ? self::match($text) : KnownFactCheckCatalog::match($text);
    }

    public static function available(): bool
    {
        return Schema::hasTable('reviewed_claims') && Schema::hasTable('claim_variations');
    }

    public static function normalize(string $text): string
    {
        // Keep dates, numbers and negation; never reuse on a single-word hit.
        return Str::of($text)->lower()->ascii()->replaceMatches('/[^a-z0-9]+/', ' ')->squish()->toString();
    }

    public static function match(string $text): ?array
    {
        $normalized = self::normalize($text);
        if ($normalized === '' || ! self::available()) return null;
        $variation = ClaimVariation::query()->where('text_hash', hash('sha256', $normalized))
            ->with('reviewedClaim')->first();
        $claim = $variation?->reviewedClaim;
        if (! $claim || ! $claim->active || ($claim->expires_at && $claim->expires_at->lte(now()))
            || $variation->normalized_text !== $normalized || empty($claim->result['sources'])) return null;
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
