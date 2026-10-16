<?php

namespace Tests\Unit;

use App\Services\Detections\Evidence\ArticleSearch;
use App\Services\Detections\Evidence\RetrievalBudget;
use App\Services\Detections\Evidence\SafeArticleFetcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAiWebSearchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        config()->set([
            'fact_check.web_search.enabled' => true,
            'fact_check.web_search.model' => 'gpt-5.4',
            'fact_check.retrieval.max_search_requests' => 2,
            'fact_check.retrieval.max_pages' => 0,
            'services.live_verification.enabled' => true,
            'services.openai.key' => 'fixture-key',
            'services.google_fact_check.key' => '',
            'services.gnews.key' => '',
            'services.newsapi.key' => '',
        ]);
    }

    public function test_completed_tool_call_yields_candidates_and_preserves_source_annotations(): void
    {
        config()->set('services.openai.max_completion_tokens', 500);
        Http::fake(['api.openai.com/*' => Http::response([
            'status' => 'completed', 'usage' => ['input_tokens' => 100, 'output_tokens' => 50, 'total_tokens' => 150],
            'output' => [
                ['type' => 'web_search_call', 'status' => 'completed', 'action' => ['type' => 'search',
                    'sources' => [['url' => 'https://www.nasa.gov/mission/crew-12/', 'title' => 'NASA Crew-12']]]],
                ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'A result.', 'annotations' => [
                    ['type' => 'url_citation', 'url' => 'https://www.nasa.gov/mission/crew-12/', 'title' => 'NASA Crew-12', 'start_index' => 0, 'end_index' => 8],
                ]]]],
            ],
        ], 200, ['Content-Type' => 'application/json'])]);

        $budget = new RetrievalBudget;
        $records = app(ArticleSearch::class)->retrieve(['NASA Crew-12 launch date'], $budget, now()->utc()->toIso8601String());

        $this->assertCount(1, $records);
        $this->assertSame('official_government', array_values($records)[0]['source_type']);
        $this->assertSame('discovered', array_values($records)[0]['retrieval_status']);
        $this->assertSame('ok', $budget->providers['openai_web_search']);
        $this->assertSame(1, $budget->searches);
        $this->assertSame(1, $budget->attempts[0]['unique_added']);
        $this->assertSame(1, $budget->attempts[0]['web_search']['search_calls'][0]['source_count']);
        $this->assertSame(1, count($budget->attempts[0]['web_search']['citation_annotations']));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/responses')
            && $request['tools'][0]['type'] === 'web_search'
            && $request['tool_choice'] === 'required'
            && $request['include'] === ['web_search_call.action.sources']
            && $request['store'] === false
            && $request['model'] === 'gpt-5.4'
            && $request['max_output_tokens'] === 500
            && ! isset($request['text']['format']));
    }

    public function test_memory_only_message_is_not_reported_as_a_search(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['status' => 'completed', 'output' => [
            ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Memory answer',
                'annotations' => [['type' => 'url_citation', 'url' => 'https://example.com/claim', 'title' => 'Example']]]]],
        ]])]);

        $budget = new RetrievalBudget;
        $records = app(ArticleSearch::class)->retrieve(['public claim'], $budget, now()->utc()->toIso8601String());

        $this->assertSame([], $records);
        $this->assertSame('tool_not_completed', $budget->providers['openai_web_search']);
        $this->assertSame(0, $budget->attempts[0]['results']);
    }

    public function test_openai_failure_does_not_prevent_existing_provider_from_contributing(): void
    {
        config()->set('services.gnews.key', 'gnews-fixture');
        Http::fake([
            'api.openai.com/*' => Http::response(['error' => ['message' => 'Model lacks web_search for fixture-key']], 400),
            'gnews.io/*' => Http::response(['totalArticles' => 1, 'articles' => [[
                'url' => 'https://www.reuters.com/world/public-record', 'title' => 'Public record',
                'description' => 'A public result', 'publishedAt' => '2026-10-01T00:00:00Z',
                'source' => ['name' => 'Reuters'],
            ]]]),
        ]);

        $budget = new RetrievalBudget;
        $records = app(ArticleSearch::class)->retrieve(['public record'], $budget, now()->utc()->toIso8601String());

        $this->assertSame('invalid_request', $budget->providers['openai_web_search']);
        $this->assertSame('ok', $budget->providers['gnews']);
        $this->assertCount(1, $records);
        $this->assertSame('gnews', array_values($records)[0]['provider']);
        $this->assertStringNotContainsString('fixture-key', $budget->attempts[0]['sanitized_error']);
    }

    public function test_completed_empty_search_is_distinct_from_a_missing_tool_call(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['status' => 'completed', 'output' => [
            ['type' => 'web_search_call', 'status' => 'completed', 'action' => ['type' => 'search', 'sources' => []]],
        ]])]);

        $budget = new RetrievalBudget;
        app(ArticleSearch::class)->retrieve(['public claim'], $budget, now()->utc()->toIso8601String());

        $this->assertSame('ok', $budget->providers['openai_web_search']);
        $this->assertSame(0, $budget->attempts[0]['results']);
    }

    public function test_a_discovered_document_is_fetched_before_supplementary_searches_use_the_window(): void
    {
        config()->set(['services.gnews.key' => 'gnews-fixture', 'fact_check.retrieval.max_pages' => 2]);
        Http::fake([
            'api.openai.com/*' => Http::response(['status' => 'completed', 'output' => [
                ['type' => 'web_search_call', 'status' => 'completed', 'action' => ['type' => 'search',
                    'sources' => [['url' => 'https://www.nasa.gov/news-release/artemis-i/', 'title' => '']]]],
                ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'A record.', 'annotations' => [
                    ['type' => 'url_citation', 'url' => 'https://www.nasa.gov/news-release/artemis-i/?utm_source=openai',
                        'title' => 'Artemis I launch record', 'start_index' => 0, 'end_index' => 9],
                ]]]],
            ]]),
            'gnews.io/*' => Http::response(['totalArticles' => 0, 'articles' => []]),
        ]);
        $fetcher = new class extends SafeArticleFetcher
        {
            public function fetch(string $url, RetrievalBudget $budget, bool $checkRobots = true): array
            {
                Http::assertNotSent(fn ($request) => str_contains($request->url(), 'gnews.io'));
                $budget->take('pages');

                return ['url' => $url, 'status' => 'retrieved', 'http_status' => 200,
                    'retrieved_at' => now()->utc()->toIso8601String(), 'published_at' => '2022-11-16T00:00:00Z',
                    'updated_at' => null, 'text' => str_repeat('NASA Artemis I launched in 2022. ', 5),
                    'content_hash' => hash('sha256', $url), 'text_truncated' => false,
                    'content_type' => 'text/html', 'title' => 'NASA Artemis I launch record', 'links' => []];
            }
        };
        $this->app->instance(SafeArticleFetcher::class, $fetcher);

        $budget = new RetrievalBudget;
        $records = app(ArticleSearch::class)->retrieve(['NASA Artemis I launch'], $budget, now()->utc()->toIso8601String());

        $this->assertCount(1, $records);
        $this->assertSame('retrieved', array_values($records)[0]['retrieval_status']);
        $this->assertSame('NASA Artemis I launch record', array_values($records)[0]['title']);
        $this->assertTrue(array_values($records)[0]['web_search_citation']);
        $this->assertSame('ok', $budget->providers['gnews']);
        $this->assertSame(1, $budget->pages);
    }
}
