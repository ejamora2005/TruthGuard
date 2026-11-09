<?php

namespace Tests\Feature;

use App\Models\PublicClaimReview;
use App\Services\Detections\Evidence\ArticleSearch;
use App\Services\Detections\Evidence\ClaimPlanner;
use App\Services\Detections\Evidence\EvidenceJudge;
use App\Services\Detections\Evidence\RetrievalBudget;
use App\Services\Detections\Evidence\SafeArticleFetcher;
use App\Services\Detections\Evidence\StructuredAnalysis;
use App\Services\Detections\Evidence\SubmissionExtractor;
use App\Services\Detections\EvidenceVerificationService;
use App\Services\Detections\OpenAiDetectionReportService;
use App\Services\Detections\ResultWording;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CaptionFactCheckResearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_caption_lookup_can_discover_other_publishers_without_promoting_their_ratings(): void
    {
        $claim = 'The record lists six rescue vehicles.';
        foreach (['https://www.rappler.com/newsbreak/fact-check/synthetic-vehicles/',
            'https://verafiles.org/articles/synthetic-vehicles',
            'https://independent-review.example/fact-check/synthetic-vehicles'] as $index => $url) {
            PublicClaimReview::create(['feed_item_id' => 'caption-review-'.$index, 'claim' => $claim,
                'headline' => 'Synthetic publisher review', 'publisher' => 'Fixture publisher '.$index,
                'url' => $url, 'rating' => 'False', 'published_at' => '2026-10-08T00:00:00Z']);
        }
        $rows = app(ArticleSearch::class)->collectedReviews([['text' => $claim]], now()->toIso8601String());
        $this->assertCount(3, $rows);
        $this->assertSame('unapproved', collect($rows)->firstWhere('url', 'https://independent-review.example/fact-check/synthetic-vehicles')['source_type']);
        $this->assertNotContains('reviewed_database', array_column($rows, 'retrieval_status'));
    }

    public function test_a_nonfinancial_caption_also_gets_a_published_fact_check_search(): void
    {
        $target = 'What is the evidence for this treatment';
        $caption = 'The treatment cures every illness.';
        $this->mock(StructuredAnalysis::class)->shouldReceive('ask')->once()->andReturn([
            'claims' => [['quote' => $target, 'kind' => 'factual', 'entities' => [], 'dates' => [], 'locations' => [],
                'quantities' => [], 'qualifiers' => [], 'references' => [], 'requires_context' => false,
                'context_reason' => '', 'searches' => ['treatment evidence clinical records']]],
            'original_claim_date' => null, 'date_quote' => '',
        ]);
        $planner = app(ClaimPlanner::class);
        $plan = $planner->plan($target."\n\n".$caption, ['question' => $target.'?',
            'visual_context' => 'A medicine package beside the caption.', 'visible_text' => $caption]);
        $this->assertSame($caption.' fact check', $planner->queries($plan['claims'], 0)[0]);
        $this->assertSame($target, $plan['claims'][0]['text']);
    }

    public function test_caption_amount_enters_first_pass_discovery_without_replacing_the_question(): void
    {
        $target = 'How much did each pictured vehicle actually cost';
        $caption = '10 million pesos bawat isa?';
        $visual = 'Small red three-wheeled emergency vehicles.';
        $this->mock(StructuredAnalysis::class)->shouldReceive('ask')->once()->andReturn([
            'claims' => [['quote' => $target, 'kind' => 'factual', 'entities' => [], 'dates' => [], 'locations' => [],
                'quantities' => [], 'qualifiers' => [], 'references' => [], 'requires_context' => true,
                'context_reason' => 'The exact procurement is not identified.',
                'searches' => ['red emergency vehicle price', 'rescue vehicle procurement']]],
            'original_claim_date' => null, 'date_quote' => '',
        ]);
        $planner = app(ClaimPlanner::class);
        $plan = $planner->plan($target."\n\n".$caption, ['question' => $target.'?',
            'media_attached' => true, 'visual_context' => $visual, 'visible_text' => $caption]);
        $queries = $planner->queries($plan['claims'], 0);
        $this->assertSame('10 million pesos fact check', $queries[0]);
        $this->assertCount(2, $queries);
        $this->assertSame($target, $plan['claims'][0]['text']);
        $this->assertSame([$caption], ClaimPlanner::researchContext($plan['claims'], $target.'?', $caption, now()->toIso8601String())['caption_contexts']);
    }

    public static function referenceStatuses(): array
    {
        return [['retrieved'], ['restricted']];
    }

    #[DataProvider('referenceStatuses')]
    public function test_an_image_and_reference_link_are_both_processed_without_losing_ocr(string $status): void
    {
        $caption = '10 million pesos bawat isa?';
        $question = 'How much did each pictured vehicle actually cost';
        $url = 'https://example.com/fact-check/vehicles';
        $this->mock(OpenAiDetectionReportService::class, function ($mock) use ($caption) {
            $mock->shouldReceive('extractClaimText')->once()->andReturn($caption);
            $mock->shouldReceive('extractionDetails')->once()->andReturn(['status' => 'completed',
                'visible_text' => $caption, 'visual_context' => 'Small emergency vehicles.']);
        });
        $this->mock(SafeArticleFetcher::class)->shouldReceive('fetch')->once()->with($url, \Mockery::type(RetrievalBudget::class))
            ->andReturn(['url' => $url, 'status' => $status, 'text' => $status === 'retrieved' ? 'Evidence, not another claim to extract.' : '',
                'text_truncated' => false]);
        config()->set('services.live_verification.enabled', true);
        $result = app(SubmissionExtractor::class)->extract(['caption_text' => $question, 'source_url' => $url,
            'media_type' => 'image'], UploadedFile::fake()->image('synthetic.png'), new RetrievalBudget);
        $this->assertSame('completed', $result['status']);
        $this->assertSame($status, $result['source_record']['status']);
        $this->assertSame($caption, $result['visible_text']);
        $this->assertStringContainsString($question, $result['analysis_text']);
        $this->assertStringContainsString($caption, $result['analysis_text']);
        $this->assertStringNotContainsString('another claim to extract', $result['analysis_text']);
    }

    public static function partialFindings(): array
    {
        return ['explicit contextual finding' => [false], 'partial relationship retains related source' => [true]];
    }

    #[DataProvider('partialFindings')]
    public function test_a_related_published_correction_is_retained_without_proving_an_unknown_price(bool $partialRelationship): void
    {
        Http::preventStrayRequests();
        config()->set(['services.openai.analysis_enabled' => true, 'services.openai.key' => 'fixture',
            'services.filipino_news_classifier.enabled' => false, 'fact_check.web_search.enabled' => false,
            'fact_check.retrieval.max_rounds' => 1, 'fact_check.max_model_calls' => 3,
            'fact_check.report_budget.max_requests' => 0, 'fact_check.report_budget.stop_after_tokens' => 0]);
        $question = 'How much did each pictured vehicle actually cost?';
        $target = rtrim($question, '?');
        $caption = '10 million pesos bawat isa?';
        $passage = 'The viral claim that small three-wheeled emergency vehicles cost 10 million pesos each is false; the money referred to different equipment.';
        $this->mock(SubmissionExtractor::class)->shouldReceive('extract')->once()->andReturn([
            'status' => 'completed', 'analysis_text' => $target."\n\n".$caption, 'original_text' => $caption,
            'input_scope' => 'visible image text and supplied question', 'visible_text' => $caption,
            'limitations' => [],
            'visual_context' => 'Small red three-wheeled emergency vehicles.', 'source_record' => null,
        ]);
        $records = [['id' => 's1', 'url' => 'https://example.com/fact-check/rescue-vehicles', 'source_type' => 'fact_check',
            'name' => 'Fixture fact-check publisher', 'title' => 'Synthetic published fact-check', 'text' => $passage,
            'retrieval_status' => 'retrieved', 'published_at' => '2026-10-08T00:00:00Z',
            'retrieved_at' => now()->toIso8601String(), 'text_truncated' => false]];
        $search = $this->mock(ArticleSearch::class);
        $search->shouldReceive('retrieve')->once()->andReturn($records);
        $search->shouldReceive('isGenericLandingPage')->andReturn(false);
        Http::fake(['api.openai.com/*' => function ($request) use ($target, $caption, $passage, $partialRelationship) {
            $data = $request['text']['format']['name'] === 'claim_decomposition'
                ? ['claims' => [['quote' => $target, 'kind' => 'factual', 'entities' => [], 'dates' => [], 'locations' => [],
                    'quantities' => [], 'qualifiers' => [], 'references' => [], 'requires_context' => true,
                    'context_reason' => 'An exact price is not established.', 'searches' => ['emergency vehicles price']]],
                    'original_claim_date' => null, 'date_quote' => '']
                : ['relationships' => $partialRelationship ? [['claim_id' => 'c1', 'source_id' => 's1', 'stance' => 'insufficient',
                    'excerpt' => $passage, 'explanation' => 'The actual unit price remains unknown.', 'basis' => 'direct_reporting',
                    'matches' => array_fill_keys(['event', 'entities', 'action', 'date', 'location', 'quantities', 'qualifiers'], true),
                    'supersedes_source_ids' => [], 'supersession_excerpt' => '']] : [],
                    'wording' => [], 'scope_resolutions' => [], 'context_findings' => $partialRelationship ? [] : [[
                        'claim_id' => 'c1', 'source_id' => 's1', 'label' => 'RELATED_STATUS', 'excerpt' => $passage,
                        'explanation' => 'The published correction addresses the caption, not the exact price of the pictured vehicles.',
                        'submitted_quote' => $caption, 'exact_subject_match' => true,
                        'presented_as_current' => false, 'valid_until' => null,
                    ]]];

            return Http::response(['status' => 'completed', 'output' => [['type' => 'message', 'content' => [
                ['type' => 'output_text', 'text' => json_encode($data)],
            ]]]]);
        }]);
        $result = app(EvidenceVerificationService::class)->apply(['signals' => []], ['caption_text' => $question,
            'analysis_task' => 'claim', 'media_type' => 'image', 'force_refresh' => true])['fact_check_result'];
        $this->assertSame('MISSING_CONTEXT', $result['verdict']);
        $this->assertSame([], $result['sources']);
        $this->assertCount(1, $result['contextual_sources']);
        $this->assertSame('c1', $result['contextual_sources'][0]['claim_id']);
        $this->assertStringContainsString($passage, $result['summary']);
        $this->assertStringContainsString($passage, ResultWording::saved($result)['explanation']);
        Http::assertSentCount(2);
    }

    public function test_a_different_procurement_cannot_become_context_through_generic_price_words(): void
    {
        $quote = '10 million pesos bawat isa?';
        $excerpt = 'The pictured bridge actually cost 10 million pesos under a separate municipal project.';
        $claim = ['id' => 'c1', 'text' => 'How much did each pictured vehicle actually cost',
            'kind' => 'factual', 'requires_context' => true, 'searches' => ['vehicles price'],
            'submission_context' => $quote, 'visual_context' => 'Small red three-wheeled emergency vehicles.'];
        $finding = ['claim_id' => 'c1', 'source_id' => 's1', 'label' => 'RELATED_STATUS',
            'excerpt' => $excerpt, 'submitted_quote' => $quote, 'explanation' => 'A separate project.',
            'exact_subject_match' => true, 'presented_as_current' => false, 'valid_until' => null];
        $source = ['id' => 's1', 'url' => 'https://example.gov/bridge', 'text' => $excerpt,
            'published_at' => '2026-10-08T00:00:00Z'];
        $this->assertSame([], app(EvidenceJudge::class)->validateContext([$finding], [$claim], [$source], now()->toIso8601String()));
    }
}
