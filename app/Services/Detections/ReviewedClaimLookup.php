<?php

namespace App\Services\Detections;

use App\Models\ClaimVariation;
use App\Services\Detections\Evidence\SubmittedText;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ReviewedClaimLookup
{
    public static function lookup(string $text, ?string $cutoff = null): ?array
    {
        // Retain the shipped catalog only on installations not migrated yet.
        if (! self::available()) {
            return self::reviewedByCutoff(KnownFactCheckCatalog::match($text), $cutoff);
        }
        $result = self::match($text);
        if ($result === null && KnownFactCheckCatalog::matchesReviewedVariant($text)) {
            // Resolve to the reviewed DB entry, preserving active/expiry checks.
            $result = self::match('The world will end on Friday, November 13, 2026, according to a scientist mathematical prediction.');
            if ($result !== null) {
                $result['diagnostics']['match_method'] = 'approved_november_13_variant';
            }
        }

        return self::reviewedByCutoff($result, $cutoff);
    }

    private static function reviewedByCutoff(?array $result, ?string $cutoff): ?array
    {
        if ($result === null || $cutoff === null) {
            return $result;
        }
        $reviewed = $result['diagnostics']['reviewed_at'] ?? null;
        if (($reviewed && Carbon::parse($reviewed)->gt($cutoff))
            || (! $reviewed && Carbon::parse($cutoff)->lt(now()->subMinute()))) {
            return null;
        }

        return $result;
    }

    public static function available(): bool
    {
        return Schema::hasTable('reviewed_claims') && Schema::hasTable('claim_variations');
    }

    /** One request's registry state, including expiry transitions without a DB write. */
    public static function dependencySnapshot(): array
    {
        if (! self::available()) {
            return ['available' => false, 'bindings' => []];
        }
        $bindings = [];
        $revisions = [];
        $instant = now();
        foreach (ClaimVariation::with('reviewedClaim')->orderBy('text_hash')->get() as $variation) {
            $claim = $variation->reviewedClaim;
            if (! $claim) {
                continue;
            }
            $revisions[$claim->id] ??= hash('sha256', json_encode([
                $claim->claim, $claim->result, $claim->active,
                $claim->reviewed_at?->toIso8601String(), $claim->expires_at?->toIso8601String(),
                $claim->active && $claim->reviewed_at?->lte($instant)
                    && (! $claim->expires_at || $claim->expires_at->gt($instant)),
            ], JSON_THROW_ON_ERROR));
            $bindings[$variation->text_hash] = hash('sha256', json_encode([
                $variation->id, $variation->normalized_text, $claim->id, $revisions[$claim->id],
            ], JSON_THROW_ON_ERROR));
        }

        return ['available' => true, 'bindings' => $bindings];
    }

    /** Changes to unrelated reviews must not invalidate this submission's cache. */
    public static function dependencySignature(array $texts, array $snapshot): string
    {
        $dependencies = [];
        foreach ($texts as $text) {
            if (! is_string($text) || trim($text) === '') {
                continue;
            }
            $regions = preg_split('/\n\s*\n/u', $text) ?: [];
            $candidates = [$text, ...$regions, ...SubmittedText::sentences($text)];
            if (KnownFactCheckCatalog::matchesReviewedVariant($text)) {
                // lookup() resolves these approved aliases through this DB entry.
                $candidates[] = 'The world will end on Friday, November 13, 2026, according to a scientist mathematical prediction.';
            }
            foreach ($candidates as $candidate) {
                $key = hash('sha256', self::normalize($candidate));
                $dependencies[$key] = $snapshot['bindings'][$key] ?? null;
            }
        }
        ksort($dependencies);

        return hash('sha256', json_encode(['version' => 'reviewed-answer-dependencies-v1',
            'available' => $snapshot['available'], 'catalog_version' => KnownFactCheckCatalog::VERSION,
            'dependencies' => $dependencies], JSON_THROW_ON_ERROR));
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
