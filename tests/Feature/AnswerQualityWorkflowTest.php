<?php

namespace Tests\Feature;

use App\Services\Detections\Evidence\ArticleSearch;
use App\Services\Detections\EvidenceVerificationService;
use App\Services\Detections\ResultWording;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AnswerQualityWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public static function wordingCases(): array
    {
        return ['grounded prose' => [false, false], 'invented price' => [true, false], 'missing bindings' => [false, true]];
    }

    #[DataProvider('wordingCases')]
    public function test_evidence_verdict_survives_rejected_prose_without_an_extra_api_request(bool $inventPrice, bool $omitBindings): void
    {
        Http::preventStrayRequests();
        config()->set(['services.openai.analysis_enabled' => true, 'services.openai.key' => 'fixture',
            'services.filipino_news_classifier.enabled' => false, 'fact_check.web_search.enabled' => false,
            'fact_check.retrieval.max_rounds' => 1, 'fact_check.max_model_calls' => 3,
            'fact_check.report_budget.max_requests' => 0, 'fact_check.report_budget.stop_after_tokens' => 0]);
        $claim = 'The record lists six rescue vehicles.';
        $passage = 'The procurement record lists six rescue vehicles for PHP 4.5 million.';
        $record = ['id' => 's1', 'url' => 'https://example.gov.ph/award', 'source_type' => 'official_government',
            'name' => 'Municipal record', 'title' => 'Synthetic award', 'text' => $passage,
            'retrieval_status' => 'retrieved', 'published_at' => '2024-05-05T00:00:00Z',
            'retrieved_at' => now()->utc()->toIso8601String(), 'text_truncated' => false];
        $search = $this->mock(ArticleSearch::class);
        $search->shouldReceive('retrieve')->once()->andReturn([$record]);
        $search->shouldReceive('isGenericLandingPage')->andReturn(false);
        Http::fake(['api.openai.com/*' => function ($request) use ($claim, $passage, $inventPrice, $omitBindings) {
            if ($request['text']['format']['name'] === 'claim_decomposition') {
                $data = ['claims' => [['quote' => $claim, 'kind' => 'factual', 'entities' => [], 'locations' => [],
                    'dates' => [], 'quantities' => [], 'qualifiers' => [], 'references' => [],
                    'requires_context' => false, 'context_reason' => '', 'searches' => ['rescue vehicle award record']]],
                    'date_quote' => '', 'original_claim_date' => null];
            } else {
                $wordingSchema = $request['text']['format']['schema']['properties']['wording']['items'];
                $this->assertContains('statement_evidence', $wordingSchema['required']);
                $text = $inventPrice ? 'The procurement record lists each rescue vehicle for PHP 500000.' : $passage;
                $wording = ['verdict' => 'TRUE', 'explanation' => $text,
                    'result_summary' => 'The record confirms the listed vehicles.',
                    'recommended_action' => 'Read the award record.', 'source_ids' => ['s1']];
                if (! $omitBindings) {
                    $wording['statement_evidence'] = [['statement' => $text, 'claim_id' => 'c1', 'source_id' => 's1', 'excerpt' => $passage]];
                }
                $data = ['context_findings' => [], 'scope_resolutions' => [], 'wording' => [$wording],
                    'relationships' => [['claim_id' => 'c1', 'source_id' => 's1', 'stance' => 'supporting',
                        'basis' => 'primary_record', 'excerpt' => $passage, 'explanation' => $passage,
                        'matches' => array_fill_keys(['event', 'entities', 'action', 'date', 'location', 'quantities', 'qualifiers'], true),
                        'supersedes_source_ids' => [], 'supersession_excerpt' => '']]];
            }

            return Http::response(['status' => 'completed', 'output' => [['type' => 'message', 'content' => [
                ['type' => 'output_text', 'text' => json_encode($data)],
            ]]]]);
        }]);
        $result = app(EvidenceVerificationService::class)->apply(['signals' => []], [
            'caption_text' => $claim, 'analysis_task' => 'claim', 'media_type' => 'text', 'force_refresh' => true,
        ])['fact_check_result'];
        $this->assertSame('TRUE', $result['verdict']);
        $this->assertSame('supported', $result['claims'][0]['outcome']);
        $copy = ResultWording::saved($result);
        $this->assertNotNull($copy);
        $this->assertStringNotContainsString('500000', $copy['explanation']);
        $this->assertStringContainsString(strtolower($passage), strtolower($copy['explanation']));
        $this->assertSame($inventPrice || $omitBindings ? 'rebuilt_from_validated_passages' : 'passed_bounded_checks', $result['answer_validation']['status']);
        $this->assertSame(['s1'], $copy['source_ids']);
        Http::assertSentCount(2);
    }
}
