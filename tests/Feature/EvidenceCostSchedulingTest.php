<?php

namespace Tests\Feature;

use App\Services\Detections\Evidence\ClaimPlanner;
use App\Services\Detections\Evidence\SafeArticleFetcher;
use App\Services\Detections\EvidenceVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EvidenceCostSchedulingTest extends TestCase
{
    use RefreshDatabase;

    private const CLAIM = 'The fictional municipal library opened on May 1, 2024.';

    public static function cases(): array
    {
        return [['resolved', 'TRUE', 1], ['gap', 'TRUE', 3], ['review_failure', 'ANALYSIS_UNAVAILABLE', 1],
            ['exact_review', 'FALSE', 0], ['budget_stop', 'ANALYSIS_UNAVAILABLE', 1]];
    }

    #[DataProvider('cases')]
    public function test_paid_search_only_runs_after_an_actual_evidence_gap(string $variant, string $verdict, int $apiCalls): void
    {
        config()->set(['services.openai.analysis_enabled' => true, 'services.openai.key' => 'fixture',
            'services.live_verification.enabled' => true, 'services.filipino_news_classifier.enabled' => false,
            'services.google_fact_check.key' => 'fixture', 'services.gnews.key' => '', 'services.newsapi.key' => '',
            'fact_check.web_search.enabled' => true, 'fact_check.retrieval.max_search_requests' => 4,
            'fact_check.retrieval.max_rounds' => 1, 'fact_check.retrieval.respect_robots' => false]);
        if ($variant === 'budget_stop') {
            config()->set('fact_check.report_budget.stop_after_tokens', 900);
        }
        $this->partialMock(SafeArticleFetcher::class)->shouldReceive('publicAddress')->andReturn('93.184.216.34');
        $planner = $this->mock(ClaimPlanner::class);
        $planner->shouldReceive('plan')->once()->andReturn(['status' => 'completed', 'original_claim_date' => null, 'claims' => [[
            'id' => 'c1', 'text' => self::CLAIM, 'kind' => 'factual', 'requires_context' => false, 'context_reason' => '',
            'references' => [], 'searches' => ['municipal library opening'],
        ]]]);
        $planner->shouldReceive('queries')->andReturn(['municipal library opening']);
        $firstUrl = $variant === 'exact_review' ? 'https://rappler.com/fact-check/library' : 'https://example.gov.ph/library/announcement';
        $newUrl = 'https://example.gov.ph/library/opening-record';
        $stages = [];
        Http::fake(function ($request) use ($variant, $firstUrl, $newUrl, &$stages) {
            if (str_contains($request->url(), 'factchecktools.googleapis.com')) {
                $review = [
                    'url' => $firstUrl, 'publisher' => ['name' => 'Municipal archive'], 'title' => 'Library announcement',
                    'textualRating' => $variant === 'exact_review' ? 'False' : 'Unspecified',
                ];

                return Http::response(['claims' => [['text' => self::CLAIM, 'claimReview' => [$review]]]]);
            }
            if ($request->url() === $firstUrl || $request->url() === $newUrl) {
                $body = $request->url() === $firstUrl && in_array($variant, ['gap', 'budget_stop'], true)
                    ? 'The proposed library opening remains under discussion. No opening date is established in this notice.'
                    : self::CLAIM.' The municipal archive lists the opening date and the original record.';

                return Http::response('<article>'.$body.'</article>', 200, ['Content-Type' => 'text/html']);
            }
            if (($request['tools'][0]['type'] ?? '') === 'web_search') {
                $this->assertSame('gap', $variant, 'Paid search must not precede or repeat an already resolved review.');
                $this->assertSame(['evidence_relationships_0'], $stages);
                $stages[] = 'web_search';

                return Http::response(['status' => 'completed', 'usage' => ['total_tokens' => 800], 'output' => [[
                    'type' => 'web_search_call', 'status' => 'completed', 'action' => ['type' => 'search', 'sources' => [['url' => $newUrl, 'title' => 'Opening record']]],
                ]]]);
            }
            $stage = $request['text']['format']['name'];
            $stages[] = $stage;
            if ($variant === 'review_failure') {
                return Http::response(['status' => 'incomplete', 'usage' => ['total_tokens' => 100]]);
            }
            $input = json_decode($request['input'][1]['content'], true);
            $rows = [];
            if ($variant === 'resolved' || ($variant === 'gap' && $stage === 'evidence_relationships_1')) {
                if ($variant === 'gap') {
                    $this->assertSame([$newUrl], array_column($input['sources'], 'url'));
                }
                $rows[] = ['claim_id' => 'c1', 'source_id' => $input['sources'][0]['id'], 'stance' => 'supporting',
                    'excerpt' => self::CLAIM, 'explanation' => self::CLAIM, 'basis' => 'primary_record',
                    'matches' => array_fill_keys(['event', 'entities', 'action', 'date', 'location', 'quantities', 'qualifiers'], true),
                    'supersedes_source_ids' => [], 'supersession_excerpt' => ''];
            }

            return Http::response(['status' => 'completed', 'usage' => ['input_tokens' => 900, 'output_tokens' => 100, 'total_tokens' => 1000],
                'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['relationships' => $rows, 'context_findings' => [], 'wording' => []])]]]]]);
        });
        $result = app(EvidenceVerificationService::class)->apply(['signals' => []], [
            'analysis_task' => 'claim', 'media_type' => 'text', 'caption_text' => self::CLAIM,
        ])['fact_check_result'];
        $this->assertSame($verdict, $result['verdict'], json_encode($result['diagnostics']));
        $this->assertSame($apiCalls, $result['diagnostics']['api_budget']['requests']);
        $this->assertSame($variant === 'gap' ? 1 : 0, count(array_filter($stages, fn ($stage) => $stage === 'web_search')));
        $this->assertSame($variant === 'gap' ? 2800 : ($variant === 'exact_review' ? 0 : ($variant === 'review_failure' ? 100 : 1000)), $result['diagnostics']['api_usage']['total_tokens']);
        if ($variant === 'budget_stop') {
            $this->assertSame('report_budget_exhausted', $result['diagnostics']['outcome_reason']);
            $this->assertStringContainsString('processing budget', $result['summary']);
            $this->assertSame('reported_token_limit', $result['diagnostics']['api_budget']['blocked_reason']);
        }
    }
}
