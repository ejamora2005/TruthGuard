<?php

namespace Tests\Unit;

use App\Services\Detections\Evidence\ArticleSearch;
use App\Services\Detections\Evidence\ClaimPlanner;
use App\Services\Detections\Evidence\EvidenceJudge;
use App\Services\Detections\Evidence\ResponsesTransport;
use App\Services\Detections\Evidence\RetrievalBudget;
use App\Services\Detections\Evidence\SafeArticleFetcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ResearchWorkflowTest extends TestCase
{
    public function test_research_sends_the_question_upload_subject_and_live_tool_controls_and_keeps_all_output_messages(): void
    {
        config()->set(['services.openai.key' => 'fixture', 'fact_check.web_search.enabled' => true,
            'fact_check.web_search.model' => 'configured-model', 'fact_check.retrieval.max_search_requests' => 1,
            'fact_check.retrieval.max_pages' => 0, 'services.live_verification.enabled' => true]);
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/*' => Http::response(['status' => 'completed', 'output' => [
            ['type' => 'web_search_call', 'status' => 'completed', 'action' => ['type' => 'search',
                'sources' => [['url' => 'https://www.comelec.gov.ph/specific-record']]]],
            ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'An initial finding.', 'annotations' => []]]],
            ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'A later qualification.', 'annotations' => [[
                'type' => 'url_citation', 'url' => 'https://www.comelec.gov.ph/specific-record', 'title' => 'Specific record',
            ]]]]],
        ]])]);
        $claims = [['id' => 'c1', 'text' => 'the election really postponed', 'kind' => 'factual',
            'entities' => ['BSKE'], 'dates' => ['2026'], 'locations' => ['Philippines'],
            'references' => [['phrase' => 'the election', 'referent' => 'BSKE', 'context_quote' => 'BSKE 2026']]]];
        $budget = new RetrievalBudget;
        $budget->researchContext = app(ClaimPlanner::class)->researchContext($claims, 'was the election really postponed?', 'BSKE 2026\nBSKE POSTPONEMENT', '2026-10-15T00:00:00Z');
        app(ArticleSearch::class)->retrieve(['BSKE 2026 postponement'], $budget, '2026-10-15T00:00:00Z');
        Http::assertSent(function ($request) {
            $input = json_decode($request['input'][1]['content'], true);
            $this->assertSame('was the election really postponed?', $input['research_context']['question']);
            $this->assertSame('was BSKE really postponed?', $input['research_context']['resolved_question']);
            $this->assertSame(['2026'], $input['research_context']['claims'][0]['dates']);
            $this->assertSame('configured-model', $request['model']);
            $this->assertTrue($request['tools'][0]['external_web_access']);
            $this->assertSame('required', $request['tool_choice']);
            $this->assertSame(['web_search_call.action.sources'], $request['include']);
            $this->assertSame(1, $request['max_tool_calls']);
            $this->assertSame('low', $request['tools'][0]['search_context_size']);

            return true;
        });
        $this->assertSame(['An initial finding.', 'A later qualification.'], array_column($budget->attempts[0]['web_search']['research_messages'], 'text'));
        $this->assertCount(1, $budget->attempts[0]['web_search']['citation_annotations']);
    }

    public function test_historical_cutoff_is_requested_explicitly_and_converts_philippine_day_end_to_utc(): void
    {
        $planner = app(ClaimPlanner::class);
        $runtime = '2026-10-15T00:00:00Z';
        $this->assertSame('2026-09-22T15:59:59+00:00', $planner->cutoff('Was it postponed as of September 22, 2026?', $runtime));
        $this->assertSame('2026-09-22T15:59:59+00:00', $planner->cutoff('Was it postponed as of 2026-09-22?', $runtime));
        $this->assertSame($runtime, $planner->cutoff('Were the 2026 elections postponed?', $runtime));
        $this->assertSame($runtime, $planner->cutoff('As of February 31, 2026?', $runtime));
        $this->assertSame($runtime, $planner->cutoff('As of October 30, 2026?', $runtime));
    }

    public function test_later_review_refines_the_same_pair_and_preserves_unresolved_contrary_sources(): void
    {
        $row = fn ($id, $stance, $supersedes = []) => ['claim_id' => 'c1', 'source_id' => $id, 'stance' => $stance,
            'supersedes_source_ids' => $supersedes, 'supersession_excerpt' => $supersedes ? 'The newer order supersedes the previous schedule.' : ''];
        $judge = app(EvidenceJudge::class);
        $merged = $judge->mergeReviews([$row('s1', 'insufficient'), $row('s2', 'contradicting')], [$row('s1', 'supporting', ['s-old'])]);
        $this->assertCount(2, $merged);
        $this->assertSame('supporting', $merged[0]['stance']);
        $this->assertSame(['s-old'], $merged[0]['supersedes_source_ids']);
        $this->assertSame('contradicting', $merged[1]['stance']);
        $this->assertSame('insufficient', $judge->mergeReviews([$row('s1', 'supporting')], [$row('s1', 'contradicting')])[0]['stance']);
        $updated = $judge->mergeReviews([$row('s1', 'supporting')], [$row('s1', 'supporting', ['s-old'])]);
        $this->assertSame(['s-old'], $updated[0]['supersedes_source_ids']);
    }

    public function test_transient_failure_retries_the_identical_request_with_a_strict_attempt_bound(): void
    {
        config()->set(['services.openai.key' => 'fixture', 'fact_check.openai_retry.max_retries' => 1]);
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/*' => Http::sequence()->push([], 429, ['Retry-After' => '0'])->push(['status' => 'completed'], 200)]);
        $client = app(ResponsesTransport::class);
        $payload = ['model' => 'configured-model', 'input' => 'Question', 'tools' => [['type' => 'web_search', 'external_web_access' => true]]];
        $this->assertSame(200, $client->post($payload, 3)->status());
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->data() === $payload);
        $this->assertSame([429, 200], array_column($client->attempts, 'http_status'));
    }

    public function test_long_retry_after_does_not_trigger_unbounded_waits(): void
    {
        config()->set(['services.openai.key' => 'fixture', 'fact_check.openai_retry.max_retries' => 2]);
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/*' => Http::response([], 429, ['Retry-After' => '60'])]);
        $this->assertSame(429, app(ResponsesTransport::class)->post(['model' => 'configured-model'], 3)->status());
        Http::assertSentCount(1);
    }

    public function test_credit_balance_error_code_and_quota_type_stop_retries_without_relying_on_error_wording(): void
    {
        config()->set(['services.openai.key' => 'fixture', 'fact_check.openai_retry.max_retries' => 2]);
        Http::preventStrayRequests();
        Http::fakeSequence()->push(['error' => ['code' => 'credit_balance_exhausted', 'message' => 'Request declined.']], 429)
            ->push(['error' => ['code' => 'billing_limit', 'type' => 'insufficient_quota', 'message' => 'Request declined.']], 429);
        foreach ([1, 2] as $requests) {
            $response = app(ResponsesTransport::class)->post(['model' => 'configured-model'], 3);
            $this->assertTrue(ResponsesTransport::quotaExhausted($response));
            Http::assertSentCount($requests);
        }
    }

    public function test_exhausted_billing_quota_is_distinct_from_a_temporary_rate_limit_and_is_not_retried(): void
    {
        config()->set(['services.openai.key' => 'fixture', 'fact_check.openai_retry.max_retries' => 2]);
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/*' => Http::response(['error' => [
            'code' => 'insufficient_quota', 'message' => 'You have no credits remaining.',
        ]], 429)]);
        $response = app(ResponsesTransport::class)->post(['model' => 'configured-model'], 3);
        $this->assertTrue(ResponsesTransport::quotaExhausted($response));
        Http::assertSentCount(1);
    }

    public function test_current_status_rechecks_bypass_a_cached_document_without_changing_historical_reports(): void
    {
        $url = 'https://example.gov/specific-record';
        $old = str_repeat('A previously retrieved schedule. ', 4);
        $new = str_repeat('The updated official schedule. ', 4);
        Cache::put('evidence-page:v2:'.hash('sha256', $url), ['text' => $old, 'status' => 'retrieved'], 300);
        Http::preventStrayRequests();
        Http::fake([$url => Http::response($new, 200, ['Content-Type' => 'text/plain'])]);
        $fetcher = $this->partialMock(SafeArticleFetcher::class);
        $fetcher->shouldReceive('publicAddress')->once()->andReturn('93.184.216.34');
        $this->assertSame($old, $fetcher->fetch($url, new RetrievalBudget, false)['text']);
        Http::assertNothingSent();
        $fresh = new RetrievalBudget;
        $fresh->freshSources = true;
        $this->assertSame(trim($new), $fetcher->fetch($url, $fresh, false)['text']);
        Http::assertSentCount(1);
    }

    public function test_a_slow_current_check_does_not_become_a_historical_cutoff_during_retrieval(): void
    {
        config()->set(['services.openai.key' => 'fixture', 'fact_check.web_search.enabled' => true,
            'fact_check.retrieval.max_search_requests' => 1, 'fact_check.retrieval.max_pages' => 1,
            'services.live_verification.enabled' => true]);
        $url = 'https://example.gov/undated-record';
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/*' => Http::response(['status' => 'completed', 'output' => [[
            'type' => 'web_search_call', 'status' => 'completed', 'action' => ['type' => 'search', 'sources' => [['url' => $url]]],
        ]]])]);
        $fetcher = $this->mock(SafeArticleFetcher::class);
        $fetcher->shouldReceive('fetch')->once()->andReturnUsing(function ($url, $budget) {
            $budget->take('pages');
            $this->travel(2)->minutes();

            return ['url' => $url, 'status' => 'retrieved', 'retrieved_at' => now()->toIso8601String(),
                'published_at' => null, 'updated_at' => null, 'text' => 'A specific official record without an available date.'];
        });
        try {
            $records = app(ArticleSearch::class)->retrieve(['official record'], new RetrievalBudget, now()->utc()->toIso8601String());
            $this->assertSame('retrieved', array_values($records)[0]['retrieval_status']);
        } finally {
            $this->travelBack();
        }
    }
}
