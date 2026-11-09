<?php

namespace Tests\Unit;

use App\Jobs\AnalyzeDetectionEvidence;
use App\Services\Detections\Evidence\ArticleSearch;
use App\Services\Detections\Evidence\ClaimPlanner;
use App\Services\Detections\Evidence\OpenAiWebSearch;
use App\Services\Detections\Evidence\ReportApiBudget;
use App\Services\Detections\Evidence\RetrievalBudget;
use App\Services\Detections\Evidence\SafeArticleFetcher;
use App\Services\Detections\Evidence\StructuredAnalysis;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UnrestrictedResearchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Cache::flush();
        config()->set([
            'fact_check.unrestricted_research' => true,
            'services.openai.key' => 'fixture-key', 'services.openai.analysis_enabled' => true,
            'fact_check.report_budget.max_requests' => 1, 'fact_check.report_budget.stop_after_tokens' => 1,
            'fact_check.retrieval.seconds' => 0, 'fact_check.retrieval.max_search_requests' => 1,
            'fact_check.retrieval.max_pages' => 1, 'fact_check.max_model_calls' => 1,
            'fact_check.web_search.enabled' => true, 'fact_check.web_search.max_calls' => 1,
            'fact_check.web_search.max_candidates' => 1, 'fact_check.web_search.max_output_tokens' => 1,
        ]);
    }

    public function test_discovery_keeps_all_returned_links_without_call_or_output_caps(): void
    {
        $sources = array_map(fn ($i) => ['url' => "https://example.gov/fictional-record-{$i}"], range(1, 12));
        Http::fake(['api.openai.com/*' => Http::response(['status' => 'completed',
            'output' => [['type' => 'web_search_call', 'status' => 'completed',
                'action' => ['type' => 'search', 'sources' => $sources]]]])]);
        $budget = new RetrievalBudget;
        $search = app(OpenAiWebSearch::class);
        foreach (['fictional first query', 'fictional second query'] as $query) {
            $result = $search->discover([$query], $budget, now()->utc()->toIso8601String());
            $this->assertCount(12, $result['candidates']);
            $budget->attempts[] = ['provider' => 'openai_web_search', 'status' => $result['status']];
        }
        $this->assertSame(2, $budget->searches);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => ! isset($request['max_output_tokens']) && ! isset($request['max_tool_calls'])
            && ! isset($request['tools'][0]['filters']) && $request['tool_choice'] === 'required');
    }

    public function test_review_and_retrieval_continue_past_previously_configured_caps(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['status' => 'completed',
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '{"ok":true}']]]]])]);
        $model = app(StructuredAnalysis::class);
        $budget = new RetrievalBudget;
        for ($i = 0; $i < 4; $i++) {
            $this->assertTrue($budget->take('searches'));
            $this->assertTrue($budget->take('pages'));
            $this->assertSame(['ok' => true], $model->ask('evidence_relationships_'.$i, 'Fictional fixture.', [],
                StructuredAnalysis::object(['ok' => ['type' => 'boolean']])));
        }
        $this->assertTrue($model->canAsk());
        $this->assertNull($model->remainingCalls());
        $this->assertFalse($budget->diagnostics()['time_budget_exhausted']);
        $this->assertSame(0, app(ReportApiBudget::class)->snapshot()['max_requests']);
        $this->assertSame(0, (new AnalyzeDetectionEvidence(1, []))->timeout);
        Http::assertSentCount(4);
        Http::assertSent(fn ($request) => ! isset($request['max_output_tokens']));
    }

    public function test_query_variants_are_exhausted_instead_of_repeating_the_second_round(): void
    {
        $claim = ['id' => 'c1', 'kind' => 'factual', 'text' => 'A fictional library opened in 2024.',
            'entities' => ['Fictional Library'], 'locations' => [], 'quantities' => [], 'dates' => ['2024'],
            'searches' => ['fictional library opening record', 'fictional library published fact check',
                'fictional library correction', 'fictional library original announcement', 'fictional library history']];
        $planner = app(ClaimPlanner::class);
        $all = [];
        for ($round = 0; ; $round++) {
            $queries = $planner->queries([$claim], $round);
            if ($queries === []) {
                break;
            }
            $this->assertSame([], array_values(array_intersect($all, $queries)));
            $all = [...$all, ...$queries];
        }
        $this->assertGreaterThan(4, count($all));
        $this->assertContains('fictional library history', $all);
    }

    public function test_published_review_pagination_reads_beyond_previous_page_and_candidate_caps(): void
    {
        config()->set(['fact_check.web_search.enabled' => false, 'services.live_verification.enabled' => true,
            'services.google_fact_check.key' => 'fixture-key', 'services.gnews.key' => '', 'services.newsapi.key' => '',
            'fact_check.retrieval.google_pages' => 1, 'fact_check.retrieval.max_candidates' => 1]);
        $hosts = ['rappler.com', 'verafiles.org', 'factcheck.afp.com', 'fullfact.org'];
        Http::fake(['factchecktools.googleapis.com/*' => function ($request) use ($hosts) {
            $page = (int) ($request['pageToken'] ?? 1);
            return Http::response(['claims' => [['text' => 'A fictional library opened in 2024.',
                'claimReview' => [['url' => 'https://'.$hosts[$page - 1].'/fact-check/fictional-library',
                    'title' => 'A fictional review fixture', 'textualRating' => 'True',
                    'reviewDate' => '2026-10-01T00:00:00Z']]]],
                ...($page < 4 ? ['nextPageToken' => (string) ($page + 1)] : [])]);
        }]);
        $this->mock(SafeArticleFetcher::class)->shouldReceive('fetch')->times(4)->andReturnUsing(function ($url, $budget) {
            $this->assertTrue($budget->take('pages'));
            return ['url' => $url, 'status' => 'retrieved', 'http_status' => 200,
                'text' => str_repeat('A fictional library opened in 2024. This page is a software fixture. ', 5),
                'retrieved_at' => now()->toIso8601String(), 'published_at' => '2026-10-01T00:00:00Z', 'updated_at' => null,
                'content_hash' => hash('sha256', $url), 'text_truncated' => false, 'content_type' => 'text/html',
                'title' => 'Fictional review', 'links' => []];
        });
        $budget = new RetrievalBudget;
        $records = app(ArticleSearch::class)->retrieve(['fictional library opening'], $budget, now()->utc()->toIso8601String());
        $this->assertCount(4, $records);
        $this->assertSame(4, $budget->pages);
        $this->assertSame($hosts, array_map(fn ($record) => parse_url($record['url'], PHP_URL_HOST), array_values($records)));
        Http::assertSentCount(4);
    }

    public static function newsProviders(): array
    {
        return [['gnews'], ['newsapi']];
    }

    #[DataProvider('newsProviders')]
    public function test_news_discovery_does_not_stop_at_the_first_result_page(string $provider): void
    {
        config()->set(['fact_check.web_search.enabled' => false, 'services.live_verification.enabled' => true,
            'services.google_fact_check.key' => '', 'services.gnews.key' => '', 'services.newsapi.key' => '',
            'services.'.$provider.'.key' => 'fixture-key', 'services.'.$provider.'.max' => 1,
            'services.'.$provider.'.page_size' => 1]);
        $endpoint = $provider === 'gnews' ? 'gnews.io/*' : 'newsapi.org/*';
        Http::fake([$endpoint => function ($request) use ($provider) {
            $page = (int) $request['page'];
            return Http::response(['status' => 'ok', ($provider === 'gnews' ? 'totalArticles' : 'totalResults') => 4,
                'articles' => [['url' => 'https://example.gov/fictional-record-'.$page,
                    'title' => 'Fictional record', 'publishedAt' => '2026-10-01T00:00:00Z']]]);
        }]);
        $this->mock(SafeArticleFetcher::class)->shouldReceive('fetch')->times(4)->andReturnUsing(fn ($url) => [
            'url' => $url, 'status' => 'http_403', 'http_status' => 403, 'text' => '', 'links' => [],
            'retrieved_at' => now()->toIso8601String(), 'published_at' => null, 'updated_at' => null,
            'content_hash' => null, 'text_truncated' => false,
        ]);
        $budget = new RetrievalBudget;
        $records = app(ArticleSearch::class)->retrieve(['fictional library opening'], $budget, now()->utc()->toIso8601String());
        $this->assertCount(4, $records);
        $this->assertSame([1, 2, 3, 4], array_column($budget->attempts, 'page'));
        Http::assertSentCount(4);
    }
}
