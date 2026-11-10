<?php

namespace Tests\Unit;

use App\Services\Detections\Evidence\ArticleSearch;
use App\Services\Detections\Evidence\RetrievalBudget;
use App\Services\Detections\Evidence\SafeArticleFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ParallelArticleFetcherTest extends TestCase
{
    use RefreshDatabase;

    private const TEXT = 'The fictional municipal library opened on May 1, 2024. The original signed programme records the opening and its location.';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        config()->set(['fact_check.retrieval.fetch_concurrency' => 4, 'fact_check.retrieval.respect_robots' => false,
            'fact_check.retrieval.max_pages' => 30, 'fact_check.retrieval.seconds' => 45]);
        $this->partialMock(SafeArticleFetcher::class)->shouldReceive('publicAddress')->andReturnUsing(fn ($url) => str_contains($url, '127.0.0.1') || str_contains($url, 'blocked.test') ? null : '93.184.216.34');
    }

    public function test_parallel_transport_preserves_articles_and_counts_each_request_once(): void
    {
        config()->set('fact_check.retrieval.respect_robots', true);
        $urls = ['https://one.example.test/record', 'https://two.example.test/record', 'https://three.example.test/record'];
        Http::fake(fn ($request) => str_ends_with($request->url(), '/robots.txt')
            ? Http::response("User-agent: *\nAllow: /", 200, ['Content-Type' => 'text/plain'])
            : Http::response('<article>'.self::TEXT.' '.$request->url().'</article>', 200, ['Content-Type' => 'text/html']));
        $budget = new RetrievalBudget;
        $results = app(SafeArticleFetcher::class)->fetchMany($urls, $budget);
        foreach ($urls as $url) {
            $this->assertSame('retrieved', $results[$url]['status']);
            $this->assertStringContainsString(self::TEXT, $results[$url]['text']);
        }
        $this->assertSame(6, $budget->pages);
        $this->assertSame(3, $budget->peakFetchConcurrency);
        Http::assertSentCount(6);
    }

    public function test_robots_denial_is_checked_before_any_article_body_is_requested(): void
    {
        config()->set('fact_check.retrieval.respect_robots', true);
        Http::fake(fn ($request) => str_ends_with($request->url(), '/robots.txt')
            ? Http::response("User-agent: *\nDisallow: /hidden", 200, ['Content-Type' => 'text/plain'])
            : Http::response('<article>'.self::TEXT.'</article>', 200, ['Content-Type' => 'text/html']));
        $results = app(SafeArticleFetcher::class)->fetchMany(['https://example.test/hidden', 'https://example.test/public'], new RetrievalBudget);
        $this->assertSame('robots_restricted_or_unavailable', $results['https://example.test/hidden']['status']);
        $this->assertSame('retrieved', $results['https://example.test/public']['status']);
        Http::assertNotSent(fn ($request) => $request->url() === 'https://example.test/hidden');
        Http::assertSentCount(2);
    }

    public function test_private_addresses_and_private_redirect_targets_never_reach_transport(): void
    {
        Http::fake(fn ($request) => str_ends_with($request->url(), '/redirect')
            ? Http::response('', 302, ['Location' => 'http://127.0.0.1/private'])
            : Http::response('<article>'.self::TEXT.'</article>', 200, ['Content-Type' => 'text/html']));
        $results = app(SafeArticleFetcher::class)->fetchMany(['https://example.test/redirect', 'https://blocked.test/private', 'https://example.test/good'], new RetrievalBudget);
        $this->assertSame('blocked_url', $results['https://example.test/redirect']['status']);
        $this->assertSame('blocked_url', $results['https://blocked.test/private']['status']);
        $this->assertSame('retrieved', $results['https://example.test/good']['status']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '127.0.0.1') || str_contains($request->url(), 'blocked.test'));
        Http::assertSentCount(2);
    }

    public function test_parallel_requests_respect_the_page_budget(): void
    {
        config()->set('fact_check.retrieval.max_pages', 2);
        Http::fake(fn () => Http::response('<article>'.self::TEXT.'</article>', 200, ['Content-Type' => 'text/html']));
        $budget = new RetrievalBudget;
        $results = app(SafeArticleFetcher::class)->fetchMany(['https://example.test/one', 'https://example.test/two', 'https://example.test/three'], $budget);
        $this->assertSame(2, $budget->pages);
        $this->assertSame('budget_exhausted', $results['https://example.test/three']['status']);
        Http::assertSentCount(2);
    }

    public function test_robots_and_articles_share_the_same_bounded_page_budget(): void
    {
        config()->set(['fact_check.retrieval.max_pages' => 2, 'fact_check.retrieval.respect_robots' => true]);
        Http::fake(fn ($request) => str_ends_with($request->url(), '/robots.txt')
            ? Http::response("User-agent: *\nAllow: /", 200, ['Content-Type' => 'text/plain'])
            : Http::response('<article>'.self::TEXT.'</article>', 200, ['Content-Type' => 'text/html']));
        $budget = new RetrievalBudget;
        $results = app(SafeArticleFetcher::class)->fetchMany(['https://one.example.test/record', 'https://two.example.test/record'], $budget);
        $this->assertSame('retrieved', $results['https://one.example.test/record']['status']);
        $this->assertSame('budget_exhausted', $results['https://two.example.test/record']['status']);
        $this->assertSame(2, $budget->pages);
        Http::assertSentCount(2);
    }

    public function test_duplicate_urls_and_repeated_calls_reuse_the_same_download(): void
    {
        Http::fake(fn () => Http::response('<article>'.self::TEXT.'</article>', 200, ['Content-Type' => 'text/html']));
        $fetcher = app(SafeArticleFetcher::class);
        $urls = ['https://example.test/one', 'https://example.test/two', 'https://example.test/one'];
        $budget = new RetrievalBudget;
        $fetcher->fetchMany($urls, $budget);
        $results = $fetcher->fetchMany($urls, $budget);
        $this->assertCount(2, $results);
        $this->assertTrue($results[$urls[0]]['cached']);
        $this->assertSame(2, $budget->pages);
        $fetcher->fetchMany($urls, new RetrievalBudget);
        Http::assertSentCount(2);
    }

    public function test_one_server_failure_does_not_discard_other_successful_sources(): void
    {
        Http::fake(fn ($request) => str_ends_with($request->url(), '/failed') ? Http::response('', 503)
            : Http::response('<article>'.self::TEXT.'</article>', 200, ['Content-Type' => 'text/html']));
        $results = app(SafeArticleFetcher::class)->fetchMany(['https://example.test/failed', 'https://example.test/good'], new RetrievalBudget);
        $this->assertSame('http_503', $results['https://example.test/failed']['status']);
        $this->assertSame('retrieved', $results['https://example.test/good']['status']);
        Http::assertSentCount(3);
    }

    public function test_parallel_responses_keep_the_size_and_compression_checks(): void
    {
        config()->set('fact_check.retrieval.max_bytes', 1024);
        Http::fake(fn ($request) => str_ends_with($request->url(), '/large')
            ? Http::response(str_repeat('x', 2048), 200, ['Content-Type' => 'text/html'])
            : Http::response(gzencode('<article>'.self::TEXT.'</article>'), 200, ['Content-Type' => 'text/html', 'Content-Encoding' => 'gzip']));
        $results = app(SafeArticleFetcher::class)->fetchMany(['https://example.test/large', 'https://example.test/compressed'], new RetrievalBudget);
        $this->assertSame('too_large', $results['https://example.test/large']['status']);
        $this->assertSame('retrieved', $results['https://example.test/compressed']['status']);
        $this->assertStringContainsString(self::TEXT, $results['https://example.test/compressed']['text']);
    }

    public function test_source_collection_still_reads_ten_distinct_sources_in_parallel_batches(): void
    {
        config()->set(['fact_check.min_relevant_sources' => 10, 'services.google_fact_check.key' => 'fixture',
            'services.gnews.key' => '', 'services.newsapi.key' => '', 'services.live_verification.enabled' => true,
            'fact_check.web_search.enabled' => false, 'fact_check.retrieval.google_languages' => ['en']]);
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'factchecktools.googleapis.com')) {
                return Http::response(['claims' => array_map(fn ($n) => [
                    'text' => self::TEXT, 'claimReview' => [['url' => 'https://example.gov.ph/record/'.$n,
                        'title' => 'Municipal library opening record '.$n, 'reviewDate' => '2024-05-02T00:00:00Z', 'textualRating' => 'Unspecified']],
                ], range(1, 10))]);
            }

            return Http::response('<article>'.self::TEXT.' Record number '.basename($request->url()).'.</article>', 200, ['Content-Type' => 'text/html']);
        });
        $budget = new RetrievalBudget;
        $records = app(ArticleSearch::class)->retrieve(['municipal library opening'], $budget, now()->utc()->toIso8601String(),
            onBatch: fn ($records) => count(array_filter($records, fn ($record) => $record['retrieval_status'] === 'retrieved')) >= 10 ? 'resolved' : null);
        $readable = array_filter($records, fn ($record) => $record['retrieval_status'] === 'retrieved');
        $this->assertCount(10, $readable);
        $this->assertSame(10, $budget->pages);
        $this->assertSame(4, $budget->peakFetchConcurrency);
        $this->assertSame('evidence_resolved', $budget->discoveryStop);
        foreach ($readable as $record) {
            $this->assertStringContainsString(self::TEXT, $record['text']);
        }
        Http::assertSentCount(11);
    }

    public function test_additional_articles_are_reviewed_in_groups_instead_of_one_call_per_page(): void
    {
        config()->set(['services.google_fact_check.key' => 'fixture', 'services.gnews.key' => '', 'services.newsapi.key' => '',
            'services.live_verification.enabled' => true, 'fact_check.web_search.enabled' => false,
            'fact_check.retrieval.google_languages' => ['en']]);
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'factchecktools.googleapis.com')) {
                return Http::response(['claims' => array_map(fn ($n) => [
                    'text' => self::TEXT, 'claimReview' => [['url' => 'https://example.gov.ph/record/'.$n,
                        'title' => 'Library opening record '.$n, 'textualRating' => 'Unspecified']],
                ], range(1, 7))]);
            }

            return Http::response('<article>'.self::TEXT.' Record '.basename($request->url()).'.</article>', 200, ['Content-Type' => 'text/html']);
        });
        $budget = new RetrievalBudget;
        $budget->reviewBatchSize = 1;
        $budget->reviewMinNewDocuments = 3;
        $reviews = [];
        $records = app(ArticleSearch::class)->retrieve(['library opening'], $budget, now()->utc()->toIso8601String(),
            onBatch: function ($records) use (&$reviews) {
                $readable = count(array_filter($records, fn ($r) => $r['retrieval_status'] === 'retrieved'));
                $reviews[] = $readable;

                return $readable >= 6 ? 'resolved' : null;
            });
        $this->assertSame([3, 7], $reviews);
        $this->assertSame(2, $budget->reviewCheckpoints);
        $this->assertCount(7, array_filter($records, fn ($r) => $r['retrieval_status'] === 'retrieved'));
        $this->assertFalse($budget->reviewDeferred);
    }

    public function test_a_smaller_final_group_is_always_reviewed_before_returning(): void
    {
        config()->set(['services.google_fact_check.key' => '', 'services.gnews.key' => '', 'services.newsapi.key' => '',
            'services.live_verification.enabled' => true, 'fact_check.web_search.enabled' => false]);
        Http::fake(fn ($request) => Http::response('<article>'.self::TEXT.' '.$request->url().'</article>', 200, ['Content-Type' => 'text/html']));
        $budget = new RetrievalBudget;
        $budget->reviewBatchSize = 1;
        $budget->reviewMinNewDocuments = 6;
        $records = [];
        foreach (range(1, 2) as $n) {
            $records[] = ['id' => 's'.$n, 'url' => 'https://example.gov.ph/record/'.$n,
                'source_type' => 'official_government', 'retrieval_status' => 'discovered', 'provider' => 'linked_primary_record',
                'title' => 'Library opening record '.$n, 'name' => 'Municipal archive', 'published_at' => null];
        }
        $reviews = [];
        app(ArticleSearch::class)->retrieve([], $budget, now()->utc()->toIso8601String(), $records, discovery: 'references',
            onBatch: function ($records) use (&$reviews) {
                $reviews[] = count(array_filter($records, fn ($r) => $r['retrieval_status'] === 'retrieved'));
            });
        $this->assertSame([2], $reviews);
        $this->assertSame(1, $budget->reviewCheckpoints);
        $this->assertFalse($budget->reviewDeferred);
    }
}
