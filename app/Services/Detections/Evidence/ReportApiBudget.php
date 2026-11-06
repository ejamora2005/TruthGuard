<?php

namespace App\Services\Detections\Evidence;

use Illuminate\Http\Client\Events\ResponseReceived;

/** Request-scoped circuit breaker, not an estimate of a provider's final bill. */
final class ReportApiBudget
{
    private int $requests = 0;

    private int $reportedTokens = 0;

    private ?string $blocked = null;

    public function reset(): void
    {
        $this->requests = $this->reportedTokens = 0;
        $this->blocked = null;
    }

    public function beforeRequest(): void
    {
        $maxRequests = max(0, (int) config('fact_check.report_budget.max_requests', 0));
        $tokenStop = max(0, (int) config('fact_check.report_budget.stop_after_tokens', 0));
        $this->blocked = match (true) {
            $maxRequests > 0 && $this->requests >= $maxRequests => 'request_limit',
            $tokenStop > 0 && $this->reportedTokens >= $tokenStop => 'reported_token_limit',
            default => null,
        };
        if ($this->blocked !== null) {
            throw new ReportBudgetExceeded('The report processing budget was reached.');
        }
        $this->requests++;
    }

    public function recordResponse(ResponseReceived $event): void
    {
        $url = parse_url($event->request->url());
        if (($url['host'] ?? '') !== 'api.openai.com' || $event->request->method() !== 'POST'
            || ! in_array($url['path'] ?? '', ['/v1/responses', '/v1/chat/completions'], true)) {
            return;
        }
        $this->reportedTokens += TokenUsage::measure((array) $event->response->json('usage', []))['total_tokens'];
    }

    public function snapshot(): array
    {
        return ['requests' => $this->requests, 'reported_tokens' => $this->reportedTokens,
            'max_requests' => max(0, (int) config('fact_check.report_budget.max_requests', 0)),
            'stop_after_tokens' => max(0, (int) config('fact_check.report_budget.stop_after_tokens', 0)),
            'blocked_reason' => $this->blocked, 'in_flight_search_can_exceed_token_threshold' => true];
    }
}
