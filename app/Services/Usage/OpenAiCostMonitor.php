<?php

namespace App\Services\Usage;

use App\Models\User;
use App\Services\Notifications\NotificationEventService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class OpenAiCostMonitor
{
    private function filters(): array
    {
        return array_filter([
            'project_ids' => filled(config('services.openai.usage_project_id')) ? [(string) config('services.openai.usage_project_id')] : null,
            'api_key_ids' => filled(config('services.openai.usage_api_key_id')) ? [(string) config('services.openai.usage_api_key_id')] : null,
        ]);
    }

    private function identity(): array
    {
        return ['scope' => hash('sha256', json_encode($this->filters()).hash('sha256', (string) config('services.openai.admin_key'))), 'month' => now('UTC')->format('Y-m')];
    }

    /** Read-only billing requests. Never calls a model or estimates dollar costs. */
    public function sync(): bool
    {
        if (! filled(config('services.openai.admin_key'))) {
            return false;
        }
        $lock = Cache::lock('openai-cost-sync:'.implode(':', $this->identity()), 360);
        if (! $lock->get()) {
            return false;
        }
        try {
            $end = now('UTC');
            $params = $this->filters() + ['start_time' => $end->copy()->startOfMonth()->timestamp, 'end_time' => $end->timestamp, 'bucket_width' => '1d', 'limit' => 31];
            $micros = 0;
            $seen = [];
            for ($page = 0; $page < 20; $page++) {
                // OpenAI SDKs encode array query parameters with [] rather than PHP's [0].
                $query = preg_replace('/%5B\d+%5D=/', '%5B%5D=', http_build_query($params, '', '&', PHP_QUERY_RFC3986));
                $response = Http::withToken((string) config('services.openai.admin_key'))->acceptJson()
                    ->connectTimeout(3)->timeout(15)->get('https://api.openai.com/v1/organization/costs?'.$query);
                if (! $response->successful()) {
                    throw new RuntimeException('http_'.$response->status());
                }
                $data = $response->json();
                if (! is_array($data) || ! is_array($data['data'] ?? null) || ! is_bool($data['has_more'] ?? null)) {
                    throw new RuntimeException('invalid_response');
                }
                foreach ($data['data'] as $bucket) {
                    if (! is_array($bucket['results'] ?? null)) {
                        throw new RuntimeException('invalid_response');
                    }
                    foreach ($bucket['results'] as $result) {
                        if (strtolower((string) data_get($result, 'amount.currency')) !== 'usd' || ! is_numeric(data_get($result, 'amount.value'))) {
                            throw new RuntimeException('invalid_currency_or_amount');
                        }
                        $micros += (int) round((float) data_get($result, 'amount.value') * 1000000);
                    }
                }
                if (! $data['has_more']) {
                    DB::table('openai_cost_snapshots')->updateOrInsert($this->identity(), [
                        'usd_micros' => $micros, 'synced_at' => $end, 'checked_at' => $end, 'sync_error' => null, 'updated_at' => $end,
                    ]);
                    $this->notifyBudget($micros);

                    return true;
                }
                $cursor = $data['next_page'] ?? null;
                if (! is_string($cursor) || $cursor === '' || isset($seen[$cursor])) {
                    throw new RuntimeException('invalid_pagination');
                }
                $seen[$cursor] = true;
                $params['page'] = $cursor;
            }
            throw new RuntimeException('pagination_limit');
        } catch (Throwable $error) {
            // Keep the last successful amount, visibly mark it stale. Never replace it with zero.
            $reason = $error instanceof RuntimeException && preg_match('/^(http_\d{3}|invalid_[a-z_]+|pagination_limit)$/D', $error->getMessage())
                ? $error->getMessage() : 'sync_failed';
            DB::table('openai_cost_snapshots')->updateOrInsert($this->identity(), ['checked_at' => now('UTC'), 'sync_error' => $reason, 'updated_at' => now('UTC')]);
            $this->notifyAdmins('sync-failed:'.implode(':', $this->identity()).':'.now('UTC')->toDateString(),
                'OpenAI billing monitor needs attention', 'The billing check failed ('.$reason.'). Budget alerts cannot reflect new spending until billing sync recovers. Review AI Usage settings.');

            return false;
        } finally {
            $lock->release();
        }
    }

    public function summary(): array
    {
        $row = DB::table('openai_cost_snapshots')->where($this->identity())->first();
        $budget = max(0, (float) config('services.openai.monthly_dollar_budget', 0));
        $used = $row?->usd_micros === null ? null : $row->usd_micros / 1000000;
        $configured = filled(config('services.openai.admin_key'));
        $stale = $row?->synced_at && ($row->sync_error || Carbon::parse($row->synced_at, 'UTC')->lt(now('UTC')->subMinutes(15)));
        $status = ! $configured ? 'not_configured' : ($used === null ? 'unavailable' : ($stale ? 'stale' : 'synced'));

        return [
            'budget' => $budget, 'budgetLabel' => '$'.number_format($budget, 2),
            'used' => $used, 'usedLabel' => $used === null ? 'Not synced' : '$'.number_format($used, 2),
            'remainingLabel' => $used === null || $budget <= 0 ? 'Unknown' : '$'.number_format(max(0, $budget - $used), 2),
            'usagePercent' => $used === null || $budget <= 0 ? null : round(max(0, $used / $budget * 100), 1),
            'warningAmountLabel' => '$'.number_format($budget * $this->threshold() / 100, 2),
            'warningThreshold' => $this->threshold(),
            'status' => $status, 'lastSyncedAt' => $row?->synced_at, 'lastSyncError' => $row?->sync_error,
            'scopeLabel' => $this->filters() === [] ? 'Entire OpenAI organization' : (isset($this->filters()['api_key_ids']) ? 'Configured API key' : 'Configured project'),
            'note' => 'OpenAI-reported costs for the UTC calendar month. Provider reporting can lag. Budget remaining is not your prepaid credit balance. Alerts do not stop API requests.',
            'setupRequired' => ! $configured,
        ];
    }

    private function threshold(): int
    {
        return max(1, min(99, (int) config('services.openai.token_warning_threshold', 80)));
    }

    private function notifyBudget(int $micros): void
    {
        $budget = (int) round(max(0, (float) config('services.openai.monthly_dollar_budget')) * 1000000);
        if ($budget <= 0 || $micros * 100 < $budget * $this->threshold()) {
            return;
        }
        $level = $micros >= $budget ? 'limit' : 'warning';
        $message = 'OpenAI reported $'.number_format($micros / 1000000, 2).' used of your $'.number_format($budget / 1000000, 2).' monthly budget. '
            .($level === 'limit' ? 'The budget limit has been reached or exceeded.' : 'Usage is approaching the budget limit.');
        $this->notifyAdmins('cost:'.implode(':', $this->identity()).":{$budget}:{$this->threshold()}:{$level}", $level === 'limit' ? 'OpenAI budget reached' : 'OpenAI budget running low', $message);
    }

    public function notifyQuotaExhausted(): void
    {
        $this->notifyAdmins('quota:'.now('UTC')->toDateString(), 'OpenAI credits or quota exhausted', 'OpenAI rejected a TruthGuard request because credits or billing quota were exhausted. Check OpenAI billing before retrying.');
    }

    private function notifyAdmins(string $key, string $title, string $message): void
    {
        User::query()->where('is_admin', true)->each(function (User $admin) use ($key, $title, $message) {
            app(NotificationEventService::class)->send($admin, 'openai:'.$key, 'system_notifications', $title, $message, '/admin/ai-usage');
        });
    }
}
