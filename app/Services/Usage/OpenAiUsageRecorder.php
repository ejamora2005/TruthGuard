<?php

namespace App\Services\Usage;

use App\Services\Detections\Evidence\ResponsesTransport;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class OpenAiUsageRecorder
{
    public function handle(ResponseReceived $event): void
    {
        $url = parse_url($event->request->url());
        if (($url['host'] ?? '') !== 'api.openai.com' || $event->request->method() !== 'POST'
            || ! in_array($url['path'] ?? '', ['/v1/responses', '/v1/chat/completions'], true)) {
            return;
        }

        try {
            if (! Schema::hasTable('openai_request_usages')) {
                Log::warning('OpenAI usage ledger is not installed. Run the usage monitoring migration.');

                return;
            }
            $data = $event->response->json();
            $data = is_array($data) ? $data : [];
            $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];
            $responseId = is_string($data['id'] ?? null) ? Str::limit($data['id'], 191, '') : null;
            $requestId = $event->response->header('x-request-id') ?: null;
            $input = $this->tokens($usage['input_tokens'] ?? $usage['prompt_tokens'] ?? null);
            $output = $this->tokens($usage['output_tokens'] ?? $usage['completion_tokens'] ?? null);
            $quota = ResponsesTransport::quotaExhausted($event->response);
            $now = now('UTC');
            // No prompts, images, API keys or provider error messages are persisted.
            DB::table('openai_request_usages')->insertOrIgnore([
                'event_key' => hash('sha256', $responseId ? 'response:'.$responseId : ($requestId ? 'request:'.$requestId : Str::uuid()->toString())),
                'response_id' => $responseId,
                'request_id' => $requestId ? Str::limit($requestId, 191, '') : null,
                'model' => is_string($data['model'] ?? null) ? Str::limit($data['model'], 191, '') : null,
                'http_status' => $event->response->status(),
                'input_tokens' => $input,
                'output_tokens' => $output,
                'total_tokens' => $this->tokens($usage['total_tokens'] ?? null) ?? ($input !== null && $output !== null ? $input + $output : null),
                'cached_input_tokens' => $this->tokens(data_get($usage, 'input_tokens_details.cached_tokens', data_get($usage, 'prompt_tokens_details.cached_tokens'))),
                'reasoning_tokens' => $this->tokens(data_get($usage, 'output_tokens_details.reasoning_tokens', data_get($usage, 'completion_tokens_details.reasoning_tokens'))),
                'web_search_calls' => collect($data['output'] ?? [])->where('type', 'web_search_call')->count(),
                'quota_exhausted' => $quota,
                'occurred_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if ($quota) {
                app(OpenAiCostMonitor::class)->notifyQuotaExhausted();
            }
        } catch (Throwable $error) {
            // Telemetry must not turn a completed provider request into an analysis retry.
            Log::error('OpenAI usage recording failed.', ['error_type' => $error::class]);
        }
    }

    private function tokens(mixed $value): ?int
    {
        return is_numeric($value) && $value >= 0 ? (int) $value : null;
    }

    public function monthlySummary(): array
    {
        $query = DB::table('openai_request_usages')->whereBetween('occurred_at', [now('UTC')->startOfMonth(), now('UTC')->endOfMonth()]);
        $totals = (clone $query)->selectRaw('COUNT(*) as requests, COUNT(total_tokens) as measured_requests, COALESCE(SUM(input_tokens), 0) as input_tokens, COALESCE(SUM(output_tokens), 0) as output_tokens, COALESCE(SUM(total_tokens), 0) as total_tokens, COALESCE(SUM(web_search_calls), 0) as web_search_calls')->first();

        return [
            'requests' => (int) $totals->requests,
            'measuredRequests' => (int) $totals->measured_requests,
            'unmeasuredRequests' => (int) $totals->requests - (int) $totals->measured_requests,
            'inputTokens' => (int) $totals->input_tokens,
            'outputTokens' => (int) $totals->output_tokens,
            'totalTokens' => (int) $totals->total_tokens,
            'averageTokensPerResponse' => $totals->measured_requests > 0
                ? (int) round($totals->total_tokens / $totals->measured_requests) : null,
            'webSearchCalls' => (int) $totals->web_search_calls,
            'trackedSince' => DB::table('openai_request_usages')->min('occurred_at'),
        ];
    }
}
