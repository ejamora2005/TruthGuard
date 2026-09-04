<?php

namespace App\Services\Detections;

use App\Models\Detection;
use Illuminate\Support\Str;

class DetectionVerdictPresenter
{
    /**
     * @return array<string, string|null>
     */
    public static function forDetection(Detection $detection): array
    {
        $rating = self::specificRating($detection);
        $verdict = (string) ($detection->verdict ?: 'review');

        return self::profileFor($rating, $verdict) + [
            'rating' => $rating,
            'verdict' => $verdict,
        ];
    }

    public static function confidencePercent(Detection $detection): int
    {
        $fakeScore = (int) $detection->fake_score;

        return match ((string) $detection->verdict) {
            'real' => max(0, min(100, 100 - $fakeScore)),
            'fake' => max(0, min(100, $fakeScore)),
            'review' => max(40, min(64, $fakeScore)),
            default => max(0, min(100, abs(50 - $fakeScore) * 2)),
        };
    }

    private static function specificRating(Detection $detection): ?string
    {
        $signals = is_array($detection->signals ?? null) ? $detection->signals : [];

        foreach (['ai_media_assessment', 'trusted_fact_check'] as $group) {
            foreach ((array) ($signals[$group] ?? []) as $signal) {
                if (! is_array($signal)) {
                    continue;
                }

                $rating = trim((string) ($signal['rating'] ?? ''));

                if ($rating !== '') {
                    return FactCheckRatingNormalizer::normalize(
                        $rating,
                        (string) ($signal['label'] ?? ''),
                    );
                }
            }
        }

        foreach ((array) ($detection->verification_sources ?? []) as $source) {
            if (! is_array($source) || ($source['source_type'] ?? null) !== 'fact_check') {
                continue;
            }

            $rating = FactCheckRatingNormalizer::normalize(
                (string) ($source['rating'] ?? ''),
                (string) ($source['summary'] ?? ''),
                (string) ($source['purpose'] ?? ''),
                (string) ($source['status'] ?? ''),
            );

            if ($rating !== '' && $rating !== FactCheckRatingNormalizer::REVIEWED) {
                return $rating;
            }
        }

        $labels = collect($signals)
            ->filter(fn ($items): bool => is_array($items))
            ->flatten(1)
            ->filter(fn ($signal): bool => is_array($signal) && filled($signal['label'] ?? null))
            ->pluck('label');

        $context = Str::lower(collect([
            $detection->analysis_summary,
            $detection->verification_summary,
            $detection->explanation_summary,
            ...$labels->all(),
        ])->filter()->implode(' '));

        if (Str::contains($context, ['ai-generated', 'ai generated', 'synthetic media', 'not a real photograph', 'not a real photo'])) {
            return FactCheckRatingNormalizer::AI_GENERATED_MISINFORMATION;
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private static function profileFor(?string $rating, string $verdict): array
    {
        return match ($rating) {
            FactCheckRatingNormalizer::AI_GENERATED_MISINFORMATION => [
                'category_key' => 'ai_generated',
                'short_label' => 'AI-GENERATED MEDIA',
                'history_label' => 'AI-generated media',
                'notification_label' => 'AI-generated media detected',
                'risk_label' => 'SYNTHETIC MEDIA',
                'summary_lead' => 'AI-generated or synthetic media detected. Do not treat this as authentic evidence without source proof.',
            ],
            FactCheckRatingNormalizer::MANIPULATED_MEDIA => [
                'category_key' => 'manipulated',
                'short_label' => 'MANIPULATED MEDIA',
                'history_label' => 'Manipulated media',
                'notification_label' => 'Manipulated media detected',
                'risk_label' => 'ALTERED CONTENT',
                'summary_lead' => 'Manipulated or altered media signals were found. Verify the original source before sharing.',
            ],
            FactCheckRatingNormalizer::MISCAPTIONED => [
                'category_key' => 'miscaptioned',
                'short_label' => 'MISCAPTIONED CONTENT',
                'history_label' => 'Miscaptioned content',
                'notification_label' => 'Miscaptioned content found',
                'risk_label' => 'CONTEXT MISMATCH',
                'summary_lead' => 'The source context does not match how the content is presented. Check the original date, place, and caption.',
            ],
            FactCheckRatingNormalizer::MISSING_CONTEXT => [
                'category_key' => 'missing_context',
                'short_label' => 'MISSING CONTEXT',
                'history_label' => 'Missing context',
                'notification_label' => 'More context needed',
                'risk_label' => 'NEEDS CONTEXT',
                'summary_lead' => 'The claim needs additional context before it can be shared responsibly.',
            ],
            FactCheckRatingNormalizer::MISLEADING => [
                'category_key' => 'misleading',
                'short_label' => 'MISLEADING CLAIM',
                'history_label' => 'Misleading claim',
                'notification_label' => 'Misleading claim detected',
                'risk_label' => 'MISLEADING',
                'summary_lead' => 'The available evidence suggests the claim may mislead readers without correction or context.',
            ],
            FactCheckRatingNormalizer::PARTLY_FALSE => [
                'category_key' => 'partly_false',
                'short_label' => 'PARTLY FALSE',
                'history_label' => 'Partly false',
                'notification_label' => 'Partly false claim',
                'risk_label' => 'MIXED ACCURACY',
                'summary_lead' => 'Some parts may be accurate, but important details are false or unsupported.',
            ],
            FactCheckRatingNormalizer::NO_OFFICIAL_CONFIRMATION => [
                'category_key' => 'no_confirmation',
                'short_label' => 'NO OFFICIAL CONFIRMATION',
                'history_label' => 'No official confirmation',
                'notification_label' => 'No official confirmation found',
                'risk_label' => 'UNCONFIRMED',
                'summary_lead' => 'No trusted or official source has confirmed the claim yet.',
            ],
            FactCheckRatingNormalizer::FALSE => [
                'category_key' => 'false_claim',
                'short_label' => 'FALSE CLAIM',
                'history_label' => 'False claim',
                'notification_label' => 'False claim found',
                'risk_label' => 'HIGH RISK',
                'summary_lead' => 'A source-backed or high-risk signal indicates the claim should not be treated as true.',
            ],
            FactCheckRatingNormalizer::CONFIRMED => [
                'category_key' => 'confirmed',
                'short_label' => 'SOURCE CONFIRMED',
                'history_label' => 'Source confirmed',
                'notification_label' => 'Source-confirmed result',
                'risk_label' => 'LOW RISK',
                'summary_lead' => 'A reliable source supports the claim, but important details should still be verified before sharing.',
            ],
            default => self::fallbackProfile($verdict),
        };
    }

    /**
     * @return array<string, string>
     */
    private static function fallbackProfile(string $verdict): array
    {
        return match ($verdict) {
            'fake' => [
                'category_key' => 'likely_misleading',
                'short_label' => 'LIKELY MISLEADING',
                'history_label' => 'Likely misleading',
                'notification_label' => 'Likely misleading result',
                'risk_label' => 'HIGH RISK',
                'summary_lead' => 'High-risk result based on source, media, or context signals.',
            ],
            'real' => [
                'category_key' => 'low_risk',
                'short_label' => 'LOW-RISK CLAIM',
                'history_label' => 'Low-risk claim',
                'notification_label' => 'Low-risk result',
                'risk_label' => 'LOW RISK',
                'summary_lead' => 'Lower-risk result based on available verification signals, not absolute proof.',
            ],
            default => [
                'category_key' => 'needs_review',
                'short_label' => 'NEEDS SOURCE REVIEW',
                'history_label' => 'Needs source review',
                'notification_label' => 'Needs source review',
                'risk_label' => 'NEEDS REVIEW',
                'summary_lead' => 'Review needed before this content is shared or treated as verified.',
            ],
        };
    }
}
