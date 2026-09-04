<?php

namespace App\Services\Detections;

use Illuminate\Support\Str;

class TrustedFactCheckVerdictResolver
{
    public const POLICY_VERSION = '2026-07-28-source-v2';

    /**
     * @param  array<string, mixed>  $analysis
     * @param  array<int, array<string, mixed>>  $factChecks
     * @param  array<int, array<string, mixed>>  $verificationSources
     * @return array<string, mixed>
     */
    public function apply(array $analysis, array $factChecks, array $verificationSources): array
    {
        $decision = $this->decide($factChecks, $verificationSources);
        $signals = is_array($analysis['signals'] ?? null) ? $analysis['signals'] : [];
        $signals['verification_policy'] = [[
            'label' => 'Trusted source rating reconciliation applied.',
            'weight' => 1,
            'version' => self::POLICY_VERSION,
        ]];

        if ($decision === null) {
            $analysis['signals'] = $signals;

            return $analysis;
        }

        $publisher = $decision['publisher'];
        $rating = $decision['rating'];
        $verdict = $decision['verdict'];
        $riskScore = $decision['risk_score'];
        $isTrustedPublisher = $decision['trusted'];
        $trustLabel = $isTrustedPublisher ? 'trusted' : 'public';

        $signals['trusted_fact_check'] = [[
            'label' => "Matched fact-check source rated this claim {$rating}.",
            'weight' => $decision['signal_weight'],
            'publisher' => $publisher,
            'rating' => $rating,
            'url' => $decision['url'],
            'version' => self::POLICY_VERSION,
        ]];

        $analysis['signals'] = $signals;
        $analysis['verdict'] = $verdict;
        $analysis['fake_score'] = $riskScore;

        if ($verdict === 'fake') {
            $analysis['analysis_summary'] = "TruthGuard found a {$trustLabel} fact-check match and prioritized that source rating over visual-only signals.";
            $analysis['verification_summary'] = "Trusted source match: the matched fact-check source rated the related claim {$rating}. This explicit public fact-check rating is the strongest verification signal for this report.";
            $analysis['explanation_summary'] = "Based on the source, this claim should be treated as not true. The matched public fact-check rated the related claim {$rating}, so TruthGuard marks this report as Likely Fake with a {$riskScore}% risk score. Image appearance and AI-generation cues are kept as supporting context, but the source rating is the primary basis.";
            $analysis['recommendation'] = "Do not share this as true. Open the linked fact-check source, compare the title and details with the uploaded content, and cite that source if you need to explain the correction.";

            return $analysis;
        }

        if ($verdict === 'real') {
            $confidence = max(0, 100 - $riskScore);
            $analysis['analysis_summary'] = "TruthGuard found a {$trustLabel} fact-check match that supports the claim and prioritized that source rating over visual-only signals.";
            $analysis['verification_summary'] = "Trusted source match: the matched fact-check source rated the related claim {$rating}. This source-backed rating supports a low-risk result.";
            $analysis['explanation_summary'] = "Based on the source, the related claim is supported by a public fact-check rated {$rating}. TruthGuard marks this report as Real with {$confidence}% confidence while still recommending source review before resharing.";
            $analysis['recommendation'] = "You may treat this as low risk, but open the linked source and compare it with the uploaded content before presenting it as verified.";

            return $analysis;
        }

        $analysis['analysis_summary'] = "TruthGuard found a public fact-check match, but the rating requires context rather than a simple true-or-false result.";
        $analysis['verification_summary'] = "Source match: the matched fact-check source rated the related claim {$rating}. The result needs careful reading before it is shared.";
        $analysis['explanation_summary'] = "Based on the source, this claim needs more context. The matched public fact-check rated it {$rating}, so TruthGuard keeps the report in Review with a {$riskScore}% risk score instead of treating the image alone as proof.";
        $analysis['recommendation'] = "Read the linked source review and compare the exact claim, date, and context before sharing or correcting the post.";

        return $analysis;
    }

    /**
     * @param  array<int, array<string, mixed>>  $factChecks
     * @param  array<int, array<string, mixed>>  $verificationSources
     * @return array{publisher: string, rating: string, verdict: string, risk_score: int, signal_weight: int, trusted: bool, url: string}|null
     */
    public function decide(array $factChecks, array $verificationSources): ?array
    {
        $candidates = array_merge(
            $this->candidatesFromSources($verificationSources),
            $this->candidatesFromFactChecks($factChecks),
        );

        return collect($candidates)
            ->filter(fn (array $candidate): bool => $candidate['verdict'] !== null)
            ->sortByDesc(fn (array $candidate): int => $candidate['priority'])
            ->first();
    }

    /**
     * @param  array<int, array<string, mixed>>  $sources
     * @return array<int, array<string, mixed>>
     */
    private function candidatesFromSources(array $sources): array
    {
        return collect($sources)
            ->filter(fn ($source) => is_array($source) && ($source['source_type'] ?? null) === 'fact_check')
            ->map(function (array $source): array {
                $publisher = trim((string) ($source['name'] ?? 'Fact-check source'));
                $rating = $this->ratingFromText(collect([
                    $source['rating'] ?? null,
                    $source['summary'] ?? null,
                    $source['purpose'] ?? null,
                    $source['status'] ?? null,
                ])->filter()->implode(' '));

                return $this->candidate(
                    $publisher,
                    $rating,
                    (string) ($source['url'] ?? ''),
                    collect([$source['purpose'] ?? null, $source['summary'] ?? null])->filter()->implode(' '),
                );
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $factChecks
     * @return array<int, array<string, mixed>>
     */
    private function candidatesFromFactChecks(array $factChecks): array
    {
        return collect($factChecks)
            ->flatMap(function (array $claim): array {
                $reviews = $claim['claimReview'] ?? [];

                if (! is_array($reviews)) {
                    return [];
                }

                return collect($reviews)
                    ->filter(fn ($review) => is_array($review))
                    ->map(function (array $review) use ($claim): array {
                        $publisher = trim((string) ($review['publisher']['name'] ?? 'Fact-check source'));
                        $rating = $this->ratingFromText((string) ($review['textualRating'] ?? ''));

                        return $this->candidate(
                            $publisher,
                            $rating,
                            (string) ($review['url'] ?? ''),
                            collect([$review['title'] ?? null, $claim['text'] ?? null])->filter()->implode(' '),
                        );
                    })
                    ->all();
            })
            ->values()
            ->all();
    }

    /**
     * @return array{publisher: string, rating: string, verdict: string|null, risk_score: int, signal_weight: int, trusted: bool, url: string, priority: int}
     */
    private function candidate(string $publisher, string $rating, string $url, string $context): array
    {
        $publisher = $publisher !== '' ? $publisher : 'Fact-check source';
        $rating = FactCheckRatingNormalizer::normalize($rating !== '' ? $rating : 'Reviewed', $context);
        $trusted = $this->isTrustedPublisher($publisher, $url);
        $verdict = FactCheckRatingNormalizer::verdictFor($rating) ?? $this->classifyRating($rating.' '.$context);

        $riskScore = match ($verdict) {
            'fake' => $trusted ? 94 : 88,
            'real' => $trusted ? 8 : 15,
            'review' => $trusted ? 62 : 58,
            default => 0,
        };

        $signalWeight = match ($verdict) {
            'fake' => $trusted ? 98 : 90,
            'real' => $trusted ? 88 : 78,
            'review' => $trusted ? 74 : 66,
            default => 0,
        };

        $priority = ($trusted ? 1000 : 500) + $signalWeight;

        if ($verdict === 'fake') {
            $priority += 100;
        }

        return [
            'publisher' => $publisher,
            'rating' => $rating,
            'verdict' => $verdict,
            'risk_score' => $riskScore,
            'signal_weight' => $signalWeight,
            'trusted' => $trusted,
            'url' => $url,
            'priority' => $priority,
        ];
    }

    private function ratingFromText(string $text): string
    {
        $text = trim($text);

        if ($text === '') {
            return '';
        }

        $normalized = FactCheckRatingNormalizer::normalize($text);

        if ($normalized !== FactCheckRatingNormalizer::REVIEWED) {
            return $normalized;
        }

        if (preg_match('/rated\s+["“]?([^".,”]+)["”]?/i', $text, $matches) === 1) {
            return trim($matches[1]);
        }

        if (preg_match('/rating:\s*([^".,;]+)/i', $text, $matches) === 1) {
            return trim($matches[1]);
        }

        return Str::limit($text, 80, '');
    }

    private function classifyRating(string $rating): ?string
    {
        $rating = Str::lower($rating);

        if ($rating === '') {
            return null;
        }

        if (Str::contains($rating, [
            'false',
            'fake',
            'not true',
            'untrue',
            'no truth',
            'incorrect',
            'misleading',
            'fabricated',
            'altered',
            'manipulated',
            'hoax',
            'pants on fire',
            'scam',
            'ai-generated',
            'synthetic',
            'deepfake',
        ])) {
            return 'fake';
        }

        if (Str::contains($rating, [
            'missing context',
            'needs context',
            'partly true',
            'partly false',
            'mixture',
            'mixed',
            'unproven',
            'unsupported',
            'needs review',
        ])) {
            return 'review';
        }

        if (Str::contains($rating, [
            'true',
            'correct',
            'accurate',
            'authentic',
            'legitimate',
        ])) {
            return 'real';
        }

        return null;
    }

    private function isTrustedPublisher(string $publisher, string $url): bool
    {
        $haystack = Str::lower($publisher.' '.$url);

        return Str::contains($haystack, [
            'rappler',
            'vera files',
            'verafiles',
            'afp',
            'reuters',
            'associated press',
            'ap fact check',
            'snopes',
            'politifact',
            'factcheck.org',
            'full fact',
            'lead stories',
            'factly',
            'boom',
            'tsek.ph',
            'pressone',
            'abs-cbn',
        ]);
    }
}
