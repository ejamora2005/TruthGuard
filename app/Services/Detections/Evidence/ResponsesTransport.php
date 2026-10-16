<?php

namespace App\Services\Detections\Evidence;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** Bounded retries of the same request; never switches model or weakens its tools. */
class ResponsesTransport
{
    public array $attempts = [];

    public function post(array $payload, int $timeout, ?callable $remaining = null): Response
    {
        $this->attempts = [];
        $deadline = microtime(true) + max(1, $timeout);
        $retries = max(0, min(2, (int) config('fact_check.openai_retry.max_retries', 1)));
        for ($attempt = 0; ; $attempt++) {
            $available = min($deadline - microtime(true), $remaining ? $remaining() : $timeout);
            $response = Http::withToken((string) config('services.openai.key'))->acceptJson()
                ->connectTimeout(min(3, max(1, $available)))->timeout(max(1, $available))
                ->post('https://api.openai.com/v1/responses', $payload);
            $this->attempts[] = ['http_status' => $response->status()];
            if ($attempt >= $retries || self::quotaExhausted($response) || ! in_array($response->status(), [429, 502, 503, 504], true)) {
                return $response;
            }
            $header = $response->header('Retry-After');
            $delay = $header === '' ? .25 : (is_numeric($header) ? max(0, (float) $header) : max(0, (strtotime($header) ?: time()) - time()));
            $available = min($deadline - microtime(true), $remaining ? $remaining() : $timeout);
            if ($delay > max(0, min(5, (float) config('fact_check.openai_retry.max_delay_seconds', 3))) || $available < $delay + 1) {
                return $response;
            }
            $this->attempts[array_key_last($this->attempts)]['retry_delay_seconds'] = $delay;
            usleep((int) ($delay * 1000000));
        }
    }

    public static function quotaExhausted(Response $response): bool
    {
        return $response->status() === 429 && (in_array($response->json('error.code'), ['insufficient_quota', 'billing_hard_limit_reached', 'credit_balance_exhausted'], true)
            || $response->json('error.type') === 'insufficient_quota'
            || (bool) preg_match('/no credits remaining|exceeded your current quota|billing (?:hard )?limit/i', (string) $response->json('error.message', '')));
    }
}
