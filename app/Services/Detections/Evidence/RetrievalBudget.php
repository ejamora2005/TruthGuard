<?php

namespace App\Services\Detections\Evidence;

final class RetrievalBudget
{
    public int $searches = 0;

    public int $pages = 0;

    public int $found = 0;

    public array $providers = [];

    public array $attempts = [];

    public array $lastSearch = [];

    public array $pageOutcomes = [];

    public int $providerRequests = 0;

    public array $researchContext = [];

    public ?bool $historicalCutoff = null;

    public bool $freshSources = false;

    private float $started;

    public function __construct()
    {
        $this->started = microtime(true);
    }

    public function remaining(): float
    {
        return max(0, (int) config('fact_check.retrieval.seconds', 45) - (microtime(true) - $this->started));
    }

    public function excludeAnalysisTime(float $seconds): void
    {
        // This budget limits discovery/fetching; model calls have their own
        // timeout and call budget. Reviewing round one must not consume the
        // reserved time for checking later records in round two.
        $this->started += max(0, $seconds);
    }

    public function take(string $kind): bool
    {
        $limit = $kind === 'searches' ? 'max_search_requests' : 'max_pages';
        if ($this->remaining() < 1 || max(0, (int) config('fact_check.retrieval.'.$limit)) <= $this->$kind) {
            return false;
        }
        $this->$kind++;

        return true;
    }

    public function diagnostics(): array
    {
        return ['search_requests' => $this->searches, 'page_requests' => $this->pages,
            'provider_requests' => $this->providerRequests,
            'raw_results_found' => array_sum(array_column($this->attempts, 'raw_results')),
            'results_found' => $this->found, 'providers' => $this->providers, 'search_attempts' => $this->attempts,
            'page_outcomes' => $this->pageOutcomes,
            'elapsed_ms' => (int) round((microtime(true) - $this->started) * 1000),
            'time_budget_exhausted' => $this->remaining() < 1];
    }

    public function openAiUsage(): array
    {
        $usage = ['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0];
        foreach ($this->attempts as $attempt) {
            if (($attempt['provider'] ?? '') !== 'openai_web_search') {
                continue;
            }
            foreach (array_keys($usage) as $key) {
                $usage[$key] += max(0, (int) ($attempt['web_search']['usage'][$key] ?? 0));
            }
        }

        return $usage;
    }
}
