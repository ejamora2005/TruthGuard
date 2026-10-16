<?php

namespace App\Services\Detections;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class FilipinoNewsClassifier
{
    private string $availability = 'unavailable';

    private array $metadata = [];

    private ?string $failureReason = null;

    public function inspect(string $text, string $scope): array
    {
        $result = $this->classify($text);

        return ['status' => $this->availability, 'failure_reason' => $this->failureReason,
            'task' => 'article_style_classification', 'input_scope' => $scope,
            'prediction' => $result, 'metadata' => $this->metadata, 'contribution' => 'Supporting diagnostic only; never determines a factual verdict.',
            'limitation' => 'Class scores are uncalibrated model outputs, not probabilities that a claim is true. Current-event verification and claim-evidence inference are not validated tasks for this model.'];
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
    public function classify(?string ...$texts): ?array
    {
        $this->availability = 'unavailable';
        $this->metadata = [];
        $this->failureReason = null;
        if (! (bool) config('services.filipino_news_classifier.enabled', true)) {
            $this->availability = 'disabled';
            $this->failureReason = 'disabled_by_configuration';

            return null;
        }

        $article = trim(collect($texts)
            ->filter(fn (?string $text): bool => filled($text))
            ->map(fn (?string $text): string => trim((string) $text))
            ->implode(' '));

        $article = preg_replace('/\s+/u', ' ', $article) ?? $article;

        if ($article === '') {
            $this->availability = 'no_text';
            $this->failureReason = 'no_text';

            return null;
        }

        $minimumWords = max(1, (int) config('services.filipino_news_classifier.min_words', 40));

        $wordCount = $this->wordCount($article);
        if ($wordCount < $minimumWords) {
            $this->availability = 'unsuitable_short_input';
            $this->failureReason = 'below_article_word_minimum';
            $this->metadata = ['word_count' => $wordCount, 'min_words' => $minimumWords];

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
        } catch (ConnectionException $exception) {
            $this->failureReason = preg_match('/timed? out|timeout/i', $exception->getMessage()) ? 'timeout' : 'network_failure';
            Log::warning('TruthGuard Filipino NLP classifier request failed.', [
                'error_type' => $exception::class, 'failure_reason' => $this->failureReason,
            ]);

            return null;
        } catch (Throwable $exception) {
            $this->failureReason = 'request_exception';
            Log::warning('TruthGuard Filipino NLP classifier request failed.', [
                'error_type' => $exception::class, 'failure_reason' => $this->failureReason,
            ]);

            return null;
        }

        if (! $response->successful()) {
            $this->failureReason = match (true) {
                in_array($response->status(), [401, 403], true) => 'authentication_failed',
                $response->status() === 429 => 'rate_limited',
                $response->status() >= 500 => 'provider_unavailable',
                default => 'http_error',
            };
            $this->metadata = ['http_status' => $response->status()];
            Log::warning('TruthGuard Filipino NLP classifier returned an unsuccessful response.', [
                'status' => $response->status(), 'failure_reason' => $this->failureReason,
            ]);

            return null;
        }

        $payload = $response->json();
        $this->metadata = is_array($payload) ? Arr::only($payload, ['model_version', 'input_tokens', 'max_length', 'truncated', 'preprocessing', 'status', 'word_count', 'reason']) : [];
        $this->metadata['http_status'] = $response->status();
        if ($response->json('status') === 'unsuitable_long_input') {
            $this->availability = 'unsuitable_long_input';
            $this->failureReason = 'input_exceeds_model_window';

            return null;
        }
        $result = $this->normalizePayload($response->json());
        $this->availability = $result === null ? 'invalid_response' : 'completed';
        $this->failureReason = $result === null ? 'invalid_prediction_payload' : null;

        return $result;
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

        if ($rawVerdict === '' || ! in_array($predictedLabel, ['0', '1'], true)) {
            return null;
        }
        foreach (['confidence', 'real_probability', 'fake_probability'] as $field) {
            if (! is_numeric($payload[$field] ?? null) || ! is_finite((float) $payload[$field]) || $payload[$field] < 0 || $payload[$field] > 1) {
                return null;
            }
        }
        if (abs($payload['real_probability'] + $payload['fake_probability'] - 1) > 0.002) {
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
            'model_version' => $payload['model_version'] ?? null,
            'input_tokens' => $payload['input_tokens'] ?? null,
            'max_length' => $payload['max_length'] ?? null,
            'preprocessing' => $payload['preprocessing'] ?? null,
            'truncation_reported' => array_key_exists('truncated', $payload),
            'truncated' => $payload['truncated'] ?? null,
        ];
    }
}
