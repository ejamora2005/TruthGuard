<?php

namespace Tests\Unit;

use App\Services\Detections\Evidence\ArticleSearch;
use App\Services\Detections\Evidence\ClaimPlanner;
use App\Services\Detections\Evidence\RetrievalBudget;
use App\Services\Detections\Evidence\SafeArticleFetcher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ArticleSearchDiagnosticsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        config()->set([
            'services.live_verification.enabled' => true,
            'services.google_fact_check.key' => '',
            'services.gnews.key' => '',
            'services.newsapi.key' => '',
            'fact_check.web_search.enabled' => false,
            'fact_check.retrieval.max_search_requests' => 2,
            'fact_check.retrieval.max_pages' => 0,
        ]);
    }

    public function test_article_time_is_recorded_as_publication_separately_from_retrieval(): void
    {
        $page = app(SafeArticleFetcher::class)->html(
            '<html><head><title>Agency notice</title></head><body><main><article><time datetime="2022-11-16T01:47:00-05:00">November 16, 2022</time><p>NASA launched its uncrewed Artemis I mission.</p></article></main></body></html>',
            'https://www.nasa.gov/news-release/notice/'
        );

        $this->assertSame('2022-11-16T06:47:00+00:00', $page['published_at']);
        $this->assertArrayNotHasKey('retrieved_at', $page);
    }

    public function test_leyte_claim_uses_broad_location_event_terms_and_a_bounded_official_query(): void
    {
        $claim = json_decode(file_get_contents(base_path('tests/Fixtures/evidence/leyte-post.json')), true);
        $row = ['kind' => 'factual', 'text' => $claim['claim'], 'submission_context' => $claim['paragraphs'],
            'entities' => ['PHIVOLCS'], 'locations' => ['LEYTE'], 'quantities' => ['Magnitude 7'], 'dates' => [],
            'searches' => ['PHIVOLCS Leyte magnitude 7 warning', 'Leyte active fault PHIVOLCS',
                'possible Magnitude 7 earthquake Leyte', 'PHIVOLCS warning clarification']];

        $planner = app(ClaimPlanner::class);
        $this->assertSame(['PHIVOLCS Leyte magnitude 7 warning', 'LEYTE earthquake fault'], $planner->queries([$row], 0));
        $this->assertSame(['Leyte active fault PHIVOLCS', 'PHIVOLCS LEYTE earthquake fault site:phivolcs.dost.gov.ph'], $planner->queries([$row], 1));
    }

    public function test_weather_and_election_claims_select_the_responsible_philippine_institution(): void
    {
        $planner = app(ClaimPlanner::class);
        foreach ([
            ['PAGASA warned of a typhoon in Samar.', 'PAGASA', 'Samar', 'pagasa.dost.gov.ph'],
            ['COMELEC announced the election schedule in Manila.', 'COMELEC', 'Manila', 'comelec.gov.ph'],
        ] as [$text, $agency, $location, $domain]) {
            $claim = ['kind' => 'factual', 'text' => $text, 'submission_context' => $text.' An unrelated PHIVOLCS item appears elsewhere.',
                'entities' => [$agency], 'locations' => [$location], 'dates' => [], 'quantities' => [],
                'searches' => [$agency.' '.$location, $location.' announcement', 'alternate wording']];
            $round = $planner->queries([$claim], 1);
            $this->assertCount(2, $round);
            $this->assertStringEndsWith('site:'.$domain, $round[1]);
        }
    }

    public function test_official_domain_is_a_newsapi_filter_and_other_providers_receive_clean_terms(): void
    {
        config()->set(['services.gnews.key' => 'fixture', 'services.newsapi.key' => 'fixture']);
        Http::fake([
            'gnews.io/*' => Http::response(['totalArticles' => 0, 'articles' => []]),
            'newsapi.org/*' => Http::response(['status' => 'ok', 'totalResults' => 1, 'articles' => [[
                'url' => 'https://phivolcs.dost.gov.ph/earthquake-record', 'title' => 'Earthquake record',
                'source' => ['name' => 'PHIVOLCS'], 'publishedAt' => '2026-10-01T00:00:00Z',
            ]]]),
        ]);
        $budget = new RetrievalBudget;
        $records = app(ArticleSearch::class)->retrieve(['PHIVOLCS LEYTE earthquake fault site:phivolcs.dost.gov.ph'],
            $budget, '2026-10-14T00:00:00Z');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'newsapi.org')
            && $request['domains'] === 'phivolcs.dost.gov.ph' && $request['q'] === 'PHIVOLCS LEYTE earthquake fault');
        Http::assertSent(fn ($request) => str_contains($request->url(), 'gnews.io')
            && ! str_contains($request['q'], 'site:'));
        $this->assertCount(1, $records);
        $this->assertSame('official_government', array_values($records)[0]['source_type']);
        $this->assertSame(1, $budget->attempts[1]['raw_results']);
        $this->assertSame(1, $budget->attempts[1]['unique_added']);
        $this->assertSame(200, $budget->attempts[1]['http_status']);
        $this->assertSame('reputable_news', app(ArticleSearch::class)->sourceType('https://www.reuters.com/world/story', 'reputable_news'));
        $this->assertSame('fact_check', app(ArticleSearch::class)->sourceType('https://www.reuters.com/world/story'));
    }

    public function test_web_search_failure_leaves_a_supplementary_search_in_a_two_request_first_round(): void
    {
        config()->set([
            'fact_check.web_search.enabled' => true,
            'services.openai.key' => 'fixture',
            'services.google_fact_check.key' => 'fixture',
        ]);
        Http::fake([
            'api.openai.com/*' => Http::response(['error' => ['message' => 'Service unavailable']], 503),
            'factchecktools.googleapis.com/*' => Http::response(['claims' => [[
                'text' => 'A public demonstration claim',
                'claimReview' => [['url' => 'https://www.nasa.gov/news-release/example/', 'title' => 'NASA record']],
            ]]]),
        ]);

        $budget = new RetrievalBudget;
        $records = app(ArticleSearch::class)->retrieve(
            ['public demonstration claim'], $budget, '2026-10-14T00:00:00Z', reserveNextRound: true
        );

        $this->assertSame(2, $budget->searches);
        $this->assertSame('http_503', $budget->attempts[0]['status']);
        $this->assertSame('ok', $budget->attempts[1]['status']);
        $this->assertCount(1, $records);
        $this->assertSame('google_fact_check', array_values($records)[0]['provider']);
    }

    public function test_malformed_success_cannot_masquerade_as_empty_and_cached_empty_is_explicit(): void
    {
        config()->set('services.gnews.key', 'fixture');
        Http::fake(['gnews.io/*' => Http::response(['articles' => []])]);
        $invalid = new RetrievalBudget;
        app(ArticleSearch::class)->retrieve(['Leyte earthquake'], $invalid, '2026-10-14T00:00:00Z');
        $this->assertSame('invalid_response', $invalid->attempts[0]['status']);
        $this->assertSame(200, $invalid->attempts[0]['http_status']);

        Http::swap(new Factory);
        Http::fake(['gnews.io/*' => Http::response(['totalArticles' => 0, 'articles' => []])]);
        $empty = new RetrievalBudget;
        app(ArticleSearch::class)->retrieve(['Leyte earthquake'], $empty, '2026-10-14T00:00:00Z');
        $this->assertSame('ok', $empty->attempts[0]['status']);
        $this->assertSame(0, $empty->attempts[0]['raw_results']);

        $cached = new RetrievalBudget;
        app(ArticleSearch::class)->retrieve(['Leyte earthquake'], $cached, '2026-10-14T00:00:00Z');
        $this->assertSame('cached', $cached->attempts[0]['status']);
        $this->assertTrue($cached->attempts[0]['cache_hit']);
        $this->assertSame(0, $cached->providerRequests);
    }

    public function test_gnews_free_plan_removed_page_is_valid_but_archive_limited_in_search_and_doctor(): void
    {
        config()->set([
            'services.gnews.key' => 'fixture',
            'services.openai.analysis_enabled' => false,
            'services.filipino_news_classifier.enabled' => false,
        ]);
        $payload = [
            'information' => ['realTimeArticles' => ['message' => 'Free plan has a 12-hour delay.']],
            'articlesRemovedFromResponse' => ['historicalArticles' => [
                'message' => '3 articles were removed from the response because historical data beyond 30 days is only available on paid plans.',
            ]],
            'totalArticles' => 38,
            'articles' => [],
        ];
        Http::fake(['gnews.io/*' => Http::response($payload, 200, ['Content-Type' => 'application/json; charset=utf-8'])]);

        $budget = new RetrievalBudget;
        $records = app(ArticleSearch::class)->retrieve(['Philippines public information'], $budget, now()->utc()->toIso8601String());
        $this->assertSame([], $records);
        $this->assertSame('archive_limited', $budget->attempts[0]['status']);
        $this->assertSame(200, $budget->attempts[0]['http_status']);
        $this->assertStringStartsWith('application/json', $budget->attempts[0]['content_type']);
        $this->assertSame(38, $budget->attempts[0]['provider_total']);
        $this->assertSame(0, $budget->attempts[0]['raw_results']);
        $this->assertSame(['historical_articles_removed' => 3, 'realtime_delay_hours' => 12], $budget->attempts[0]['availability']);
        $this->assertNull($budget->attempts[0]['sanitized_error']);

        Cache::flush();
        Artisan::call('truthguard:evidence-doctor', ['--live' => true]);
        $doctor = json_decode(Artisan::output(), true);
        $this->assertSame('enhanced_article_search', $doctor['search_adapter']);
        $this->assertSame('enhanced_article_search', $doctor['production_search_adapter']);
        $this->assertTrue($doctor['tests_active_production_adapter']);
        $this->assertSame('archive_limited', $doctor['providers']['gnews']['live_status']);
        $this->assertSame(200, $doctor['providers']['gnews']['last_search']['http_status']);
        $this->assertSame(38, $doctor['providers']['gnews']['last_search']['provider_total']);
        $this->assertSame(3, $doctor['providers']['gnews']['last_search']['availability']['historical_articles_removed']);

        Cache::flush();
        Http::swap(new Factory);
        Http::fake(['gnews.io/*' => Http::response(['totalArticles' => 38, 'articles' => []])]);
        $emptyPage = new RetrievalBudget;
        app(ArticleSearch::class)->retrieve(['Philippines public information'], $emptyPage, now()->utc()->toIso8601String());
        $this->assertSame('ok', $emptyPage->attempts[0]['status']);
    }

    public function test_authentication_rate_limit_timeout_and_network_failure_remain_distinct(): void
    {
        config()->set('services.gnews.key', 'fixture');
        foreach ([400 => 'invalid_request', 401 => 'authentication_failed', 403 => 'quota_or_subscription_limited', 429 => 'rate_limited'] as $code => $status) {
            Http::swap(new Factory);
            Http::fake(['gnews.io/*' => Http::response([], $code)]);
            $budget = new RetrievalBudget;
            app(ArticleSearch::class)->retrieve(['distinct '.$code], $budget, '2026-10-14T00:00:00Z');
            $this->assertSame($status, $budget->attempts[0]['status']);
            $this->assertSame($code, $budget->attempts[0]['http_status']);
        }
        Cache::flush();
        foreach (['Operation timed out' => 'timeout', 'Could not resolve host' => 'network_failure'] as $message => $status) {
            Http::swap(new Factory);
            Http::fake(fn () => throw new ConnectionException($message));
            $budget = new RetrievalBudget;
            app(ArticleSearch::class)->retrieve(['distinct '.$status], $budget, '2026-10-14T00:00:00Z');
            $this->assertSame($status, $budget->attempts[0]['status']);
            $this->assertNull($budget->attempts[0]['http_status']);
        }
    }

    public function test_candidate_counts_show_invalid_and_duplicate_links_before_document_fetch(): void
    {
        config()->set('services.google_fact_check.key', 'fixture');
        Http::fake(['factchecktools.googleapis.com/*' => Http::response(['claims' => [[
            'text' => 'A claim', 'claimReview' => [
                ['url' => 'https://phivolcs.dost.gov.ph/record', 'title' => 'Record'],
                ['url' => 'https://phivolcs.dost.gov.ph/record?utm_source=feed', 'title' => 'Record copy'],
                ['url' => 'javascript:alert(1)', 'title' => 'Invalid'],
            ],
        ]]])]);
        $budget = new RetrievalBudget;
        $records = app(ArticleSearch::class)->retrieve(['Leyte fault'], $budget, '2026-10-14T00:00:00Z');

        $this->assertCount(1, $records);
        $this->assertSame(1, $budget->attempts[0]['raw_results']);
        $this->assertSame(3, $budget->attempts[0]['results']);
        $this->assertSame(1, $budget->attempts[0]['unique_added']);
        $this->assertSame(['invalid_url' => 1, 'duplicate_url' => 1, 'candidate_limit' => 0], $budget->attempts[0]['rejected']);
    }

    public function test_article_header_remains_in_document_text_but_site_navigation_does_not(): void
    {
        $html = '<html><body><header>Site navigation</header><article><header>PHIVOLCS notice: </header>'
            .'<p>The Leyte fault is assessed in this record.</p></article></body></html>';
        $document = app(SafeArticleFetcher::class)->html($html, 'https://phivolcs.dost.gov.ph/record');

        $this->assertStringContainsString('PHIVOLCS notice:', $document['text']);
        $this->assertStringContainsString('The Leyte fault', $document['text']);
        $this->assertStringNotContainsString('Site navigation', $document['text']);
    }

    public function test_document_download_timeout_has_a_distinct_outcome(): void
    {
        config()->set(['fact_check.retrieval.max_pages' => 1, 'fact_check.retrieval.respect_robots' => false]);
        Http::fake(fn () => throw new ConnectionException('Operation timed out'));
        $fetcher = new class extends SafeArticleFetcher
        {
            protected function resolve(string $host): array
            {
                return ['93.184.216.34'];
            }
        };

        $page = $fetcher->fetch('https://example.org/document', new RetrievalBudget);
        $this->assertSame('timeout', $page['status']);
        $this->assertNull($page['http_status']);
    }
}
