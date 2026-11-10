<?php

namespace App\Services\Detections\Evidence;

use App\Models\Detection;
use App\Services\Detections\AnalysisStopped;
use Illuminate\Http\Client\Events\ResponseReceived;

/** Request-scoped circuit breaker, not an estimate of a provider's final bill. */
final class ReportApiBudget
{
    private int $requests = 0;

    private int $reportedTokens = 0;

    private ?string $blocked = null;

    private ?int $detectionId = null;

    private ?string $retryId = null;

    private float $lastHeartbeat = 0;

    private float $startedAt;

    public function __construct()
    {
        $this->startedAt = microtime(true);
    }

    /** A wall-clock deadline is independent of source counts or research mode. */
    public function remainingSeconds(): float
    {
        $limit = max(0, (int) config('fact_check.report_budget.max_seconds', 120));

        return $limit === 0 ? INF : max(0, $limit - (microtime(true) - $this->startedAt));
    }

    public function trackDetection(?Detection $detection): void
    {
        $this->detectionId = $detection?->id;
        $this->retryId = data_get($detection?->signals, 'analysis_retry.id');
        $this->lastHeartbeat = 0;
    }

    public function assertActive(?Detection $detection = null): void
    {
        if ($this->detectionId === null) {
            return;
        }
        $detection ??= Detection::select('id', 'processing_status', 'signals')->find($this->detectionId);
        if (! $detection || $detection->processing_status !== 'processing'
            || data_get($detection->signals, 'analysis_retry.id') !== $this->retryId) {
            throw new AnalysisStopped('This assessment is no longer running.');
        }
        if (ResearchLimits::unrestricted() && microtime(true) - $this->lastHeartbeat >= 30) {
            // Long research remains active while requests are being made. Only
            // update the timestamp; never replace cancellation/retry signals.
            Detection::whereKey($this->detectionId)->where('processing_status', 'processing')
                ->where('signals->analysis_retry->id', $this->retryId)->update(['updated_at' => now()]);
            $this->lastHeartbeat = microtime(true);
        }
    }

    public function reset(): void
    {
        $this->startedAt = microtime(true);
        $this->requests = $this->reportedTokens = 0;
        $this->blocked = null;
    }

    public function beforeRequest(): void
    {
        $this->assertActive();
        $maxRequests = ResearchLimits::unrestricted() ? 0 : max(0, (int) config('fact_check.report_budget.max_requests', 0));
        $tokenStop = ResearchLimits::unrestricted() ? 0 : max(0, (int) config('fact_check.report_budget.stop_after_tokens', 0));
        $this->blocked = match (true) {
            $this->remainingSeconds() < 1 => 'elapsed_time_limit',
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
            'max_seconds' => max(0, (int) config('fact_check.report_budget.max_seconds', 120)),
            'elapsed_ms' => (int) round((microtime(true) - $this->startedAt) * 1000),
            'time_budget_exhausted' => $this->remainingSeconds() < 1,
            'max_requests' => ResearchLimits::unrestricted() ? 0 : max(0, (int) config('fact_check.report_budget.max_requests', 0)),
            'stop_after_tokens' => ResearchLimits::unrestricted() ? 0 : max(0, (int) config('fact_check.report_budget.stop_after_tokens', 0)),
            'blocked_reason' => $this->blocked, 'in_flight_search_can_exceed_token_threshold' => true];
    }
}
