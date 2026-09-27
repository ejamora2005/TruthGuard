<?php

namespace App\Services\Detections;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class FilipinoNewsClassifier
{
    /**
     * @return array{
     *     raw_verdict: string,
     *     verdict: string,
     *     predicted_label: string,
     *     confidence: float,
     *     real_probability: float,
     *     fake_probability: float,
     *     word_count: int,
     *     reason: string
     * }|null
     */
    public function classify(?string ...$texts): ?array
    {
        if (! (bool) config('services.filipino_news_classifier.enabled', true)) {
            return null;
        }

        $article = trim(collect($texts)
            ->filter(fn (?string $text): bool => filled($text))
            ->map(fn (?string $text): string => trim((string) $text))
            ->implode(' '));

        $article = preg_replace('/\s+/u', ' ', $article) ?? $article;

        if ($article === '') {
            return null;
        }

        $minimumWords = max(1, (int) config('services.filipino_news_classifier.min_words', 40));

        if ($this->wordCount($article) < $minimumWords) {
            return null;
        }

        try {
            $response = Http::acceptJson()
                ->connectTimeout(max(1, (int) config('services.filipino_news_classifier.connect_timeout', 1)))
                ->timeout(max(1, (int) config('services.filipino_news_classifier.timeout', 5)))
                ->post($this->predictUrl(), [
                    'article' => $article,
                    'uncertain_threshold' => (float) config('services.filipino_news_classifier.uncertain_threshold', 0.70),
                    'min_words' => $minimumWords,
                    'max_length' => max(32, (int) config('services.filipino_news_classifier.max_length', 256)),
                ]);
        } catch (Throwable $exception) {
            Log::warning('TruthGuard Filipino NLP classifier request failed.', [
                'message' => $exception->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('TruthGuard Filipino NLP classifier returned an unsuccessful response.', [
                'status' => $response->status(),
                'body' => Str::limit($response->body(), 500),
            ]);

            return null;
        }

        return $this->normalizePayload($response->json());
    }

    private function predictUrl(): string
    {
        $baseUrl = rtrim((string) config('services.filipino_news_classifier.url', 'http://127.0.0.1:8765'), '/');

        return $baseUrl.'/predict';
    }

    private function wordCount(string $text): int
    {
        preg_match_all('/[\pL\pN]+/u', $text, $matches);

        return count($matches[0] ?? []);
    }

    /**
     * @return array{
     *     raw_verdict: string,
     *     verdict: string,
     *     predicted_label: string,
     *     confidence: float,
     *     real_probability: float,
     *     fake_probability: float,
     *     word_count: int,
     *     reason: string
     * }|null
     */
    private function normalizePayload(mixed $payload): ?array
    {
        if (! is_array($payload)) {
            return null;
        }

        $rawVerdict = trim((string) ($payload['verdict'] ?? ''));
        $predictedLabel = trim((string) ($payload['predicted_label'] ?? ''));

        if ($rawVerdict === '' || $predictedLabel === '') {
            return null;
        }

        $verdict = match (true) {
            Str::contains(Str::lower($rawVerdict), 'fake') => 'fake',
            Str::contains(Str::lower($rawVerdict), 'real') => 'real',
            default => 'review',
        };

        return [
            'raw_verdict' => $rawVerdict,
            'verdict' => $verdict,
            'predicted_label' => $predictedLabel,
            'confidence' => (float) ($payload['confidence'] ?? 0.0),
            'real_probability' => (float) ($payload['real_probability'] ?? 0.0),
            'fake_probability' => (float) ($payload['fake_probability'] ?? 0.0),
            'word_count' => (int) ($payload['word_count'] ?? 0),
            'reason' => trim((string) ($payload['reason'] ?? '')),
        ];
    }
}
