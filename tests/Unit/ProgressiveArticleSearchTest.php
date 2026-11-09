<?php

namespace Tests\Unit;

use App\Services\Detections\Evidence\ArticleSearch;
use App\Services\Detections\Evidence\RetrievalBudget;
use App\Services\Detections\Evidence\SafeArticleFetcher;
use App\Services\Detections\Evidence\ClaimPlanner;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class ProgressiveArticleSearchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        config()->set(['fact_check.unrestricted_research' => true, 'services.live_verification.enabled' => true,
            'fact_check.web_search.enabled' => false, 'services.google_fact_check.key' => 'fixture',
            'services.gnews.key' => '', 'services.newsapi.key' => '']);
        $this->mock(SafeArticleFetcher::class)->shouldReceive('fetch')->andReturnUsing(function ($url, $budget) {
            $budget->take('pages');
            return ['url' => $url, 'status' => 'retrieved', 'http_status' => 200,
                'text' => 'The fictional library opened in 2024. This is a synthetic software test fixture. '.$url,
                'retrieved_at' => now()->toIso8601String(), 'published_at' => '2026-10-01T00:00:00Z', 'updated_at' => null,
                'content_hash' => hash('sha256', $url), 'text_truncated' => false, 'content_type' => 'text/html',
                'title' => 'Fictional library opening', 'links' => []];
        });
    }

    private function page(string $url, ?string $next = null): array
    {
        return ['claims' => [['text' => 'The fictional library opened in 2024.', 'claimReview' => [[
            'url' => $url, 'title' => 'Fictional library opening', 'textualRating' => 'True',
            'reviewDate' => '2026-10-01T00:00:00Z',
        ]]]], ...($next ? ['nextPageToken' => $next] : [])];
    }

    public function test_source_coverage_can_paginate_after_the_claim_is_already_resolved(): void
    {
        Http::fake(['factchecktools.googleapis.com/*' => function ($request) {
            $page = (int) ($request['pageToken'] ?? 1);
            return Http::response(['claims' => array_map(fn ($index) => [
                'text' => 'The fictional library opened in 2024.', 'claimReview' => [[
                    'url' => 'https://rappler.com/fact-check/fictional-library-'.$index,
                    'title' => 'Fictional library opening', 'textualRating' => 'True',
                    'reviewDate' => '2026-10-01T00:00:00Z',
                ]],
            ], range(($page - 1) * 3 + 1, $page * 3)), 'nextPageToken' => (string) ($page + 1)]);
        }]);
        $budget = new RetrievalBudget;
        $records = app(ArticleSearch::class)->retrieve(['fictional library opening'], $budget, now()->utc()->toIso8601String(),
            onBatch: function ($rows) use ($budget) {
                $budget->coverageSources = count(array_filter($rows, fn ($row) => ($row['retrieval_status'] ?? '') === 'retrieved'));
                $budget->noteEvidence([['id' => 'c1', 'outcome' => 'supported']]);
                return $budget->coverageSources >= 10 ? 'resolved' : null;
            });
        $this->assertGreaterThanOrEqual(10, $budget->coverageSources);
        $this->assertCount(12, $records);
        $this->assertSame(0, $budget->lowYieldPagesSkipped);
        $this->assertSame('evidence_resolved', $budget->discoveryStop);
        Http::assertSentCount(4);
    }

    public function test_a_resolved_review_stops_before_pagination_or_other_search_services(): void
    {
        config()->set('services.gnews.key', 'fixture');
        Http::fake(['factchecktools.googleapis.com/*' => Http::response($this->page('https://rappler.com/fact-check/fictional-library', 'more'))]);
        $budget = new RetrievalBudget;
        $records = app(ArticleSearch::class)->retrieve(['fictional library opening'], $budget, now()->utc()->toIso8601String(),
            onBatch: fn ($records) => collect($records)->contains(fn ($r) => ($r['retrieval_status'] ?? '') === 'retrieved') ? 'resolved' : null);
        $this->assertCount(1, $records);
        $this->assertSame('evidence_resolved', $budget->discoveryStop);
        $this->assertSame(1, $budget->reviewCheckpoints);
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'gnews.io') || isset($request['pageToken']));
    }

    public function test_unresolved_review_tries_another_provider_before_draining_one_provider(): void
    {
        config()->set('services.gnews.key', 'fixture');
        Http::fake([
            'factchecktools.googleapis.com/*' => Http::response($this->page('https://rappler.com/fact-check/fictional-proposal', 'more')),
            'gnews.io/*' => Http::response(['totalArticles' => 1000, 'articles' => [[
                'url' => 'https://example.gov/library/opening-record', 'title' => 'Fictional library opening',
                'publishedAt' => '2026-10-01T00:00:00Z',
            ]]]),
        ]);
        $budget = new RetrievalBudget;
        app(ArticleSearch::class)->retrieve(['fictional library opening'], $budget, now()->utc()->toIso8601String(),
            onBatch: fn ($records) => collect($records)->contains(fn ($r) => $r['url'] === 'https://example.gov/library/opening-record') ? 'resolved' : null);
        $this->assertSame(2, $budget->searches);
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => isset($request['pageToken']) || ($request['page'] ?? 1) > 1);
    }

    public function test_an_unavailable_review_stops_retrieval_without_claiming_success(): void
    {
        Http::fake(['factchecktools.googleapis.com/*' => Http::response($this->page('https://verafiles.org/articles/fictional-library', 'more'))]);
        $budget = new RetrievalBudget;
        app(ArticleSearch::class)->retrieve(['fictional library opening'], $budget, now()->utc()->toIso8601String(),
            onBatch: fn () => 'review_unavailable');
        $this->assertSame('review_unavailable', $budget->discoveryStop);
        Http::assertSentCount(1);
    }

    public function test_more_urls_without_evidence_progress_do_not_drain_provider_pagination(): void
    {
        Http::fake(['factchecktools.googleapis.com/*' => Http::response($this->page('https://rappler.com/fact-check/fictional-proposal', 'more'))]);
        $budget = new RetrievalBudget;
        $state = [['id' => 'c1', 'outcome' => 'insufficient']];
        $budget->noteEvidence($state);
        app(ArticleSearch::class)->retrieve(['fictional library opening'], $budget, now()->utc()->toIso8601String(),
            onBatch: function () use ($budget, $state) { $budget->noteEvidence($state); return null; });
        $this->assertSame(1, $budget->lowYieldPagesSkipped);
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => isset($request['pageToken']));
    }

    public function test_new_useful_context_can_continue_to_a_later_decisive_page(): void
    {
        Http::fake(['factchecktools.googleapis.com/*' => function ($request) {
            return Http::response($this->page('https://verafiles.org/articles/fictional-'.($request['pageToken'] ?? 'first'), isset($request['pageToken']) ? null : 'second'));
        }]);
        $budget = new RetrievalBudget;
        $budget->noteEvidence([['id' => 'c1', 'outcome' => 'insufficient']]);
        $reviews = 0;
        app(ArticleSearch::class)->retrieve(['fictional library opening'], $budget, now()->utc()->toIso8601String(),
            onBatch: function () use ($budget, &$reviews) {
                $reviews++;
                $budget->noteEvidence([['id' => 'c1', 'outcome' => $reviews === 1 ? 'needs_context' : 'supported']]);
                return $reviews > 1 ? 'resolved' : null;
            });
        $this->assertSame('evidence_resolved', $budget->discoveryStop);
        $this->assertSame(0, $budget->lowYieldPagesSkipped);
        Http::assertSentCount(2);
    }

    public function test_cited_records_are_reviewed_without_crawling_references_of_references(): void
    {
        Http::fake(['factchecktools.googleapis.com/*' => Http::response($this->page('https://rappler.com/fact-check/fictional-library'))]);
        $root = 'https://rappler.com/fact-check/fictional-library';
        $record = 'https://example.gov/library/original-record';
        $pdf = $record.'.pdf';
        $visited = [];
        $this->app->instance(SafeArticleFetcher::class, new class($root, $record, $pdf, $visited) extends SafeArticleFetcher
        {
            public function __construct(private string $root, private string $record, private string $pdf, private array &$visited) {}

            public function fetch(string $url, RetrievalBudget $budget, bool $checkRobots = true): array
            {
                $this->visited[] = $url;
                if (! in_array($url, [$this->root, $this->record, $this->pdf], true)) {
                    throw new \LogicException('The crawler followed a reference outside the cited record.');
                }
                $budget->take('pages');
                return ['url' => $url, 'status' => 'retrieved', 'http_status' => 200,
                    'text' => 'This fictional record discusses an undated library proposal. Its opening date has not been established. '.$url,
                    'retrieved_at' => now()->toIso8601String(), 'published_at' => null, 'updated_at' => null,
                    'content_hash' => hash('sha256', $url), 'text_truncated' => false, 'content_type' => 'text/html',
                    'links' => $url === $this->root ? [$this->record] : ($url === $this->record ? ['https://example.gov/unrelated-reference', $this->pdf] : [])];
            }
        });
        $budget = new RetrievalBudget;
        $budget->noteEvidence([['id' => 'c1', 'outcome' => 'insufficient']]);
        app(ArticleSearch::class)->retrieve(['fictional library opening'], $budget, now()->utc()->toIso8601String(), onBatch: fn () => null);
        $this->assertSame([$root, $record, $pdf], $visited);
        $this->assertSame(1, $budget->recursiveReferencesSkipped);
    }

    public static function decisiveSource(): array
    {
        return [['target'], ['reference']];
    }

    #[DataProvider('decisiveSource')]
    public function test_targeted_search_precedes_reference_expansion_and_cited_records_remain_available(string $decisive): void
    {
        config()->set(['fact_check.web_search.enabled' => true, 'services.openai.key' => 'fixture']);
        $root = 'https://rappler.com/fact-check/fictional-library';
        $target = 'https://verafiles.org/articles/fictional-opening-record';
        $reference = 'https://example.gov/library/original-record';
        Http::fake([
            'factchecktools.googleapis.com/*' => Http::response($this->page($root, 'many-more-pages')),
            'api.openai.com/*' => Http::response(['status' => 'completed', 'output' => [[
                'type' => 'web_search_call', 'status' => 'completed', 'action' => ['type' => 'search', 'sources' => [['url' => $target, 'title' => 'Fictional opening record']]],
            ]]]),
        ]);
        $visited = [];
        $this->app->instance(SafeArticleFetcher::class, new class($root, $reference, $visited) extends SafeArticleFetcher
        {
            public function __construct(private string $root, private string $reference, private array &$visited) {}

            public function fetch(string $url, RetrievalBudget $budget, bool $checkRobots = true): array
            {
                if ($url === $this->reference) {
                    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.openai.com'));
                }
                $this->visited[] = $url;
                $budget->take('pages');
                return ['url' => $url, 'status' => 'retrieved', 'http_status' => 200,
                    'text' => 'This is a synthetic article about a fictional library opening. It is used only for a scheduling test. '.$url,
                    'retrieved_at' => now()->toIso8601String(), 'published_at' => null, 'updated_at' => null,
                    'content_hash' => hash('sha256', $url), 'text_truncated' => false,
                    'links' => $url === $this->root ? [$this->reference] : []];
            }
        });
        $budget = new RetrievalBudget;
        $budget->noteEvidence([['id' => 'c1', 'outcome' => 'insufficient']]);
        $onBatch = fn ($rows) => collect($rows)->contains(fn ($row) => $row['url'] === ($decisive === 'target' ? $target : $reference)
            && ($row['retrieval_status'] ?? '') === 'retrieved') ? 'resolved' : null;
        $search = app(ArticleSearch::class);
        $cutoff = now()->utc()->toIso8601String();
        $rows = $search->retrieve(['fictional library opening'], $budget, $cutoff, discovery: 'providers', onBatch: $onBatch);
        $this->assertSame([$root], $visited);
        $queries = ClaimPlanner::targetedQueries([['text' => 'The fictional library opened in 2024.']]);
        $this->assertSame('The fictional library opened in 2024. fact check', $queries[0]);
        $rows = $search->retrieve($queries, $budget, $cutoff, $rows, discovery: 'web', onBatch: $onBatch);
        $this->assertSame([$root, $target], $visited);
        if ($decisive === 'reference') {
            $search->retrieve([], $budget, $cutoff, $rows, discovery: 'references', onBatch: $onBatch);
            $this->assertSame([$root, $target, $reference], $visited);
        }
        $this->assertSame('evidence_resolved', $budget->discoveryStop);
        Http::assertSentCount(2);
    }
}
