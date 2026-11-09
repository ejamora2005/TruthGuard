<?php

namespace App\Services\Detections\Evidence;

final class RetrievalBudget
{
    public ?string $analysisId = null;

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

    public array $fetchedPages = [];

    public ?string $reviewSnapshot = null;

    public int $reviewBatchSize = 6;

    public int $reviewCheckpoints = 0;

    public ?string $discoveryStop = null;

    public ?string $evidenceSignature = null;

    public int $lowYieldPagesSkipped = 0;

    public int $recursiveReferencesSkipped = 0;

    public int $coverageSources = 0;

    public function noteEvidence(array $outcomes, array $contexts = [], array $resolutions = []): void
    {
        $state = array_map(fn ($claim) => [$claim['id'] ?? '', $claim['outcome'] ?? 'insufficient'], $outcomes);
        $labels = array_values(array_unique(array_map(fn ($row) => ($row['claim_id'] ?? '').'|'.($row['label'] ?? ''), $contexts)));
        $subjects = array_values(array_unique(array_map(fn ($row) => ($row['claim_id'] ?? '').'|'.($row['resolved_subject'] ?? ''), $resolutions)));
        sort($state);
        sort($labels);
        sort($subjects);
        $this->evidenceSignature = hash('sha256', json_encode([$state, $labels, $subjects, $this->coverageSources], JSON_THROW_ON_ERROR));
    }

    private float $started;

    public function __construct()
    {
        $this->started = microtime(true);
    }

    public function remaining(): float
    {
        return ResearchLimits::unrestricted() ? INF
            : max(0, (int) config('fact_check.retrieval.seconds', 45) - (microtime(true) - $this->started));
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
        app(ReportApiBudget::class)->assertActive();
        $limit = $kind === 'searches' ? 'max_search_requests' : 'max_pages';
        if ($this->remaining() < 1 || ResearchLimits::ceiling('fact_check.retrieval.'.$limit, 0) <= $this->$kind) {
            return false;
        }
        $this->$kind++;

        return true;
    }

    public function failedSearches(): array
    {
        // A completed discovery tool can return usable URLs even when the
        // accompanying response text was truncated. Its URLs still require
        // independent fetching and evidence review.
        return array_values(array_filter($this->attempts, fn ($attempt) =>
            ! in_array($attempt['status'] ?? '', ['ok', 'cached', 'archive_limited', 'partial_results'], true)));
    }

    public function diagnostics(): array
    {
        return ['search_requests' => $this->searches, 'page_requests' => $this->pages,
            'unrestricted_research' => ResearchLimits::unrestricted(),
            'review_checkpoints' => $this->reviewCheckpoints, 'discovery_stop' => $this->discoveryStop,
            'low_yield_pages_skipped' => $this->lowYieldPagesSkipped,
            'recursive_references_skipped' => $this->recursiveReferencesSkipped,
            'provider_requests' => $this->providerRequests,
            'raw_results_found' => array_sum(array_column($this->attempts, 'raw_results')),
            'results_found' => $this->found, 'providers' => $this->providers, 'search_attempts' => $this->attempts,
            'page_outcomes' => $this->pageOutcomes,
            'search_completion_limits' => array_values(array_map(fn ($attempt) => [
                'provider' => $attempt['provider'],
                'response_status' => $attempt['web_search']['response_status'] ?? null,
                'incomplete_reason' => $attempt['web_search']['incomplete_reason'] ?? null,
            ], array_filter($this->attempts, fn ($attempt) => ($attempt['status'] ?? '') === 'partial_results'))),
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
