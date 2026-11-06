<?php

namespace Tests\Feature;

use App\Services\Detections\Evidence\ArticleSearch;
use App\Services\Detections\Evidence\ClaimPlanner;
use App\Services\Detections\Evidence\StructuredAnalysis;
use App\Services\Detections\Evidence\SubmissionExtractor;
use App\Services\Detections\EvidenceVerificationService;
use App\Services\Detections\SubmissionTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class QuestionHeadlineEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private const HEADLINE = 'GMA SARADO NA?!';

    private const OCR = "GMA SARADO NA?!\n\nKHARMA ANG INABOT HATOL SA KORTE?!\n\nFriday Oct. 23, 2026\n\nBYE BYE MEL TIANGCO?!";

    private function row(string $quote, string $reason = 'The heading names a topic but does not state a specific factual outcome to verify.'): array
    {
        return ['quote' => $quote, 'kind' => 'rhetorical', 'requires_context' => true, 'context_reason' => $reason,
            'entities' => [], 'dates' => [], 'locations' => [], 'quantities' => [], 'qualifiers' => [],
            'searches' => ['GMA shutdown court libel complaint', 'GMA Mel Tiangco court order'], 'references' => []];
    }

    protected function setUp(): void
    {
        parent::setUp();
        config()->set(['services.openai.analysis_enabled' => true, 'services.openai.key' => 'fixture',
            'services.live_verification.enabled' => true, 'services.filipino_news_classifier.enabled' => false,
            'fact_check.web_search.enabled' => false, 'fact_check.retrieval.max_rounds' => 1]);
        Http::preventStrayRequests();
    }

    public function test_the_full_poster_and_default_instruction_reach_a_checkable_headline_instead_of_missing_context(): void
    {
        $this->mock(StructuredAnalysis::class)->shouldReceive('ask')->once()->withArgs(function ($stage, $instructions, $input) {
            $this->assertNull($input['question_target']);
            $this->assertStringContainsString('HATOL SA KORTE', $input['original_text']);
            $this->assertStringContainsString('MEL TIANGCO', $input['original_text']);
            $this->assertStringContainsString('explicit status predicate', $instructions);

            return $stage === 'claim_decomposition';
        })->andReturn(['claims' => [$this->row(self::HEADLINE)], 'date_quote' => '', 'original_claim_date' => null]);
        $plan = app(ClaimPlanner::class)->plan(self::OCR, ['question' => SubmissionTask::CLAIM_INSTRUCTION.'.', 'media_attached' => true]);
        $claim = $plan['claims'][0];
        $this->assertSame(self::HEADLINE, $claim['text']);
        $this->assertSame('factual', $claim['kind']);
        $this->assertFalse($claim['requires_context']);
        $this->assertTrue($claim['question_framed_assertion']);
        $this->assertStringContainsString('MEL TIANGCO', $claim['submission_context']);
        $this->assertStringContainsString('Oct. 23, 2026', $claim['submission_context']);
    }

    public function test_an_explicit_status_question_survives_when_the_planner_only_extracts_a_secondary_fact(): void
    {
        $secondary = 'Mel Tiangco was named in a complaint.';
        $this->mock(StructuredAnalysis::class)->shouldReceive('ask')->once()->andReturn([
            'claims' => [array_replace($this->row($secondary), ['kind' => 'factual', 'requires_context' => false, 'context_reason' => ''])],
            'date_quote' => '', 'original_claim_date' => null,
        ]);
        $plan = app(ClaimPlanner::class)->plan(self::OCR."\n\n".$secondary);
        $this->assertContains(self::HEADLINE, array_column($plan['claims'], 'text'));
        $this->assertContains($secondary, array_column($plan['claims'], 'text'));
    }

    public function test_a_specific_user_question_keeps_its_target_instead_of_adding_competing_poster_claims(): void
    {
        $target = 'the official filing concerned Mel Tiangco';
        $this->mock(StructuredAnalysis::class)->shouldReceive('ask')->once()->andReturn([
            'claims' => [array_replace($this->row($target), ['kind' => 'factual', 'requires_context' => false, 'context_reason' => ''])],
            'date_quote' => '', 'original_claim_date' => null,
        ]);
        $plan = app(ClaimPlanner::class)->plan($target."\n\n".self::OCR, ['question' => 'Verify whether '.$target.'.']);
        $this->assertCount(1, $plan['claims']);
        $this->assertSame($target, $plan['claims'][0]['text']);
        $this->assertStringContainsString('HATOL SA KORTE', $plan['claims'][0]['submission_context']);
    }

    public static function nonStatusQuestions(): array
    {
        return [['GMA court case?!'], ['Why was GMA closed?!'], ['Should GMA be closed?!'],
            ['GMA will be closed?!'], ['The station closed?!'], ['BSKE postponement: a race against time?!']];
    }

    public static function namedStatusQuestions(): array
    {
        return [['Orchid School sinara na?!'], ['Orchid School closed?!'],
            ['Is Acme Corporation suspended?!'], ['Aurora Mayor naaresto na?!']];
    }

    #[DataProvider('namedStatusQuestions')]
    public function test_named_status_questions_in_english_and_filipino_are_candidates_without_a_preset_truth_value(string $quote): void
    {
        $this->mock(StructuredAnalysis::class)->shouldReceive('ask')->once()->andReturn([
            'claims' => [$this->row($quote)], 'date_quote' => '', 'original_claim_date' => null,
        ]);
        $claim = app(ClaimPlanner::class)->plan($quote)['claims'][0];
        $this->assertSame('factual', $claim['kind']);
        $this->assertFalse($claim['requires_context']);
        $this->assertArrayNotHasKey('outcome', $claim);
        $this->assertArrayNotHasKey('verdict', $claim);
    }

    #[DataProvider('nonStatusQuestions')]
    public function test_topic_open_ended_conditional_and_unidentified_subject_questions_are_not_turned_into_assertions(string $quote): void
    {
        $this->mock(StructuredAnalysis::class)->shouldReceive('ask')->once()->andReturn([
            'claims' => [$this->row($quote)], 'date_quote' => '', 'original_claim_date' => null,
        ]);
        $claim = app(ClaimPlanner::class)->plan($quote)['claims'][0];
        $this->assertTrue($claim['requires_context']);
        $this->assertArrayNotHasKey('question_framed_assertion', $claim);
    }

    public function test_a_real_material_ambiguity_is_preserved_for_a_question_framed_candidate(): void
    {
        $this->mock(StructuredAnalysis::class)->shouldReceive('ask')->once()->andReturn([
            'claims' => [$this->row('Orchid school closed?!', 'Which Orchid school is meant? The location is ambiguous.')],
            'date_quote' => '', 'original_claim_date' => null,
        ]);
        $claim = app(ClaimPlanner::class)->plan('Orchid school closed?!')['claims'][0];
        $this->assertSame('factual', $claim['kind']);
        $this->assertTrue($claim['requires_context']);
        $this->assertStringContainsString('location is ambiguous', $claim['context_reason']);
    }

    public static function evidenceCases(): array
    {
        return [['direct_reporting', 'FALSE'], ['unrelated_rating', 'UNVERIFIED']];
    }

    #[DataProvider('evidenceCases')]
    public function test_a_real_planner_routes_the_preset_and_frozen_ocr_to_evidence_validation(string $variant, string $verdict): void
    {
        // Synthetic article bodies test the pipeline; these are not quotations
        // from Rappler or a newly established verdict about the live article.
        $passage = $variant === 'direct_reporting'
            ? 'No court order shut down GMA Network in the event described by this post. The network continued broadcasting.'
            : 'The older video claimed that Marcos ordered a GMA Network shutdown. That 2023 video was rated false.';
        $url = $variant === 'direct_reporting'
            ? 'https://www.rappler.com/newsbreak/fact-check/no-court-order-gma-network-shutdown-over-libel-complaint/'
            : 'https://verafiles.org/articles/vera-files-fact-check-marcos-not-closing-down-gma-network';
        $this->mock(SubmissionExtractor::class)->shouldReceive('extract')->once()->andReturn([
            'status' => 'completed', 'analysis_text' => self::OCR, 'visible_text' => self::OCR,
            'original_text' => self::OCR, 'reconstructed_text' => self::OCR, 'input_scope' => 'visible image text',
            'limitations' => [], 'source_record' => null,
        ]);
        $search = $this->mock(ArticleSearch::class);
        $search->shouldReceive('prefetchExactReviews')->andReturn([]);
        $search->shouldReceive('isGenericLandingPage')->andReturn(false);
        $search->shouldReceive('retrieve')->andReturn([[
            'id' => 's1', 'url' => $url, 'name' => 'Publisher', 'title' => 'Publisher review',
            'text' => $passage, 'source_type' => 'fact_check', 'retrieval_status' => 'retrieved',
            'reviewed_claim' => $variant === 'direct_reporting' ? 'A court ordered GMA Network to shut down over a complaint.' : 'Marcos ordered a GMA shutdown in 2023.',
            'review_rating' => 'False', 'published_at' => '2026-11-05T00:00:00Z',
            'retrieved_at' => now()->utc()->toIso8601String(), 'text_truncated' => false,
        ]]);
        Http::fake(['api.openai.com/*' => function ($request) use ($variant, $passage) {
            if ($request['text']['format']['name'] === 'claim_decomposition') {
                $data = ['claims' => [$this->row(self::HEADLINE)], 'date_quote' => '', 'original_claim_date' => null];
            } else {
                $input = json_decode($request['input'][1]['content'], true);
                $this->assertSame('factual', $input['claims'][0]['kind']);
                $this->assertFalse($input['claims'][0]['requires_context']);
                $this->assertStringContainsString('MEL TIANGCO', $input['submission_contexts'][0]);
                $this->assertStringContainsString('question_framed_assertion', $request['input'][0]['content']);
                $data = ['context_findings' => [], 'wording' => [], 'relationships' => [[
                    'claim_id' => 'c1', 'source_id' => 's1', 'stance' => 'contradicting', 'excerpt' => $passage,
                    'explanation' => $passage, 'basis' => $variant === 'direct_reporting' ? 'direct_reporting' : 'fact_check',
                    'matches' => array_fill_keys(['event', 'entities', 'action', 'date', 'location', 'quantities', 'qualifiers'], true),
                    'supersedes_source_ids' => [], 'supersession_excerpt' => '',
                ]]];
            }

            return Http::response(['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($data)]]]]]);
        }]);
        $image = UploadedFile::fake()->image('poster.png', 640, 640);
        $result = app(EvidenceVerificationService::class)->apply(['signals' => []], [
            'analysis_task' => 'claim', 'media_type' => 'image', 'caption_text' => SubmissionTask::CLAIM_INSTRUCTION.'.',
        ], $image)['fact_check_result'];
        $this->assertSame($verdict, $result['verdict'], json_encode($result['claims']));
        $this->assertNotSame('MISSING_CONTEXT', $result['verdict']);
        $this->assertSame(self::HEADLINE, $result['claims'][0]['text']);
        $this->assertStringContainsString('HATOL SA KORTE', $result['extraction']['analysis_text']);
        Http::assertSentCount(2);
    }
}
