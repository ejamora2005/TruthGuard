<?php

namespace Tests\Feature;

use App\Services\Detections\Evidence\ArticleSearch;
use App\Services\Detections\Evidence\RetrievalBudget;
use App\Services\Detections\Evidence\SubmissionExtractor;
use App\Services\Detections\EvidenceVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ResearchCostAccountingTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_counts_search_extraction_and_both_models_without_repaying_for_unchanged_evidence(): void
    {
        Http::preventStrayRequests();
        config()->set(['services.openai.analysis_enabled' => true, 'services.openai.key' => 'offline-fixture',
            'services.openai.model' => 'gpt-5.4', 'services.openai.planning_model' => 'gpt-5.4-mini',
            'services.filipino_news_classifier.enabled' => false, 'fact_check.retrieval.max_rounds' => 2]);
        $claim = 'The fictional library opened in 2024.';
        $this->mock(SubmissionExtractor::class)->shouldReceive('extract')->once()->andReturn([
            'submitted_text' => $claim, 'submitted_notes' => '', 'visible_text' => '', 'inferred_claim' => '',
            'article_text' => '', 'analysis_text' => $claim, 'original_text' => $claim, 'reconstructed_text' => $claim,
            'status' => 'completed', 'limitations' => [],
            'input_scope' => 'submitted text', 'source_record' => null,
            'response_model' => 'gpt-5.4-mini', 'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'total_tokens' => 15],
        ]);
        $round = 0;
        $this->partialMock(ArticleSearch::class)->shouldReceive('retrieve')->twice()->andReturnUsing(
            function ($queries, RetrievalBudget $budget) use (&$round) {
                if ($round++ === 0) {
                    $budget->searches++;
                    $budget->providers['openai_web_search'] = 'ok';
                    $budget->attempts[] = ['provider' => 'openai_web_search', 'status' => 'ok', 'raw_results' => 1,
                        'web_search' => ['model' => 'gpt-5.4-mini', 'usage' => ['input_tokens' => 100, 'output_tokens' => 50, 'total_tokens' => 150]]];
                }

                return [['id' => 's1', 'url' => 'https://example.gov.ph/records/library', 'name' => 'Fictional records',
                    'title' => 'Library record', 'retrieval_status' => 'retrieved', 'source_type' => 'official_government',
                    'published_at' => '2024-01-01T00:00:00Z', 'retrieved_at' => now()->utc()->toIso8601String(),
                    'text' => 'This fictional record does not establish the submitted library opening date.']];
            }
        );
        Http::fake(['api.openai.com/*' => function ($request) use ($claim) {
            $planning = $request['text']['format']['name'] === 'claim_decomposition';
            $data = $planning ? ['claims' => [[
                'quote' => $claim, 'kind' => 'factual', 'entities' => [], 'dates' => [], 'locations' => [],
                'quantities' => [], 'qualifiers' => [], 'requires_context' => false, 'context_reason' => '',
                'searches' => ['fictional library opening record', 'fictional library announcement', 'fictional library archive', 'fictional library corrections'], 'references' => [],
            ]], 'original_claim_date' => null, 'date_quote' => ''] : ['relationships' => [], 'context_findings' => []];

            return Http::response(['status' => 'completed', 'model' => $request['model'],
                'usage' => ['input_tokens' => 100, 'output_tokens' => 20, 'total_tokens' => 120],
                'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($data)]]]]]);
        }]);
        $analysis = app(EvidenceVerificationService::class)->apply(['signals' => []], [
            'analysis_task' => 'claim', 'caption_text' => $claim, 'media_type' => 'text',
        ]);
        Http::assertSentCount(2);
        $this->assertSame(['gpt-5.4-mini', 'gpt-5.4'], array_column(Http::recorded()->map(fn ($pair) => $pair[0]->data())->all(), 'model'));
        $usage = $analysis['signals']['openai_usage'];
        $this->assertSame(310, $usage['input_tokens']);
        $this->assertSame(95, $usage['output_tokens']);
        $this->assertSame(405, $usage['total_tokens']);
        $this->assertSame(['gpt-5.4-mini', 'gpt-5.4'], $usage['models']);
        $this->assertSame($usage, $analysis['fact_check_result']['diagnostics']['api_usage']);
        $this->assertSame(1, $analysis['fact_check_result']['diagnostics']['duplicate_reviews_skipped'], json_encode([
            'retrieval_rounds' => $round, 'stages' => $analysis['fact_check_result']['diagnostics']['model_stages'],
        ]));
        $this->assertSame('UNVERIFIED', $analysis['fact_check_result']['verdict']);
    }
}
