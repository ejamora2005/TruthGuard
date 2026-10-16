<?php

namespace Tests\Feature;

use App\Models\CanonicalFactCheckResult;
use App\Models\Detection;
use App\Models\User;
use App\Services\Detections\Evidence\ArticleSearch;
use App\Services\Detections\Evidence\SubmissionExtractor;
use App\Services\Detections\EvidenceVerificationService;
use App\Services\Detections\GoogleFactCheckFeedService;
use App\Services\Detections\ResultWording;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GeneratedResultWordingTest extends TestCase
{
    use RefreshDatabase;

    private const CLAIM = 'The fictional library opened on January 3, 2024.';

    private const EXPLANATION = 'The claim is that the fictional library opened on January 3, 2024. The municipal library record gives that exact opening date and names the same library as the submission. This directly supports the claim about when it opened, rather than simply mentioning a similar building or a proposed opening. The finding is limited to the opening date recorded in that document; it does not establish other claims about the library. Within that scope, the available record agrees with the submitted statement, so the result is True.';

    private const SHORT = 'The municipal record gives the same opening date as the submitted claim, directly supporting it. The result covers that recorded date and does not establish other claims about the library.';

    private const ACTION = 'Read the municipal library record before quoting its opening date.';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config()->set(['services.openai.key' => 'offline-test-key', 'services.openai.analysis_enabled' => true,
            'services.openai.model' => 'gpt-5.4', 'services.openai.planning_model' => 'gpt-5.4-mini',
            'services.filipino_news_classifier.enabled' => false, 'fact_check.retrieval.max_rounds' => 2]);
    }

    public static function presentationCases(): array
    {
        return ['valid copy' => ['valid'], 'wrong verdict' => ['wrong_verdict'],
            'invented source' => ['invented_source'], 'malformed copy' => ['malformed'], 'missing copy' => ['missing']];
    }

    #[DataProvider('presentationCases')]
    public function test_wording_shares_the_existing_review_call_without_affecting_validated_results(string $variant): void
    {
        $this->mock(SubmissionExtractor::class)->shouldReceive('extract')->once()->andReturn([
            'submitted_text' => self::CLAIM, 'submitted_notes' => '', 'visible_text' => '', 'inferred_claim' => '',
            'article_text' => '', 'analysis_text' => self::CLAIM, 'original_text' => self::CLAIM, 'reconstructed_text' => self::CLAIM,
            'status' => 'completed', 'limitations' => [], 'input_scope' => 'submitted text', 'source_record' => null,
        ]);
        $this->partialMock(ArticleSearch::class)->shouldReceive('retrieve')->once()->andReturn([[
            'id' => 's1', 'url' => 'https://example.gov.ph/library-record', 'name' => 'Municipal records',
            'title' => 'Library opening record', 'retrieval_status' => 'retrieved', 'source_type' => 'official_government',
            'published_at' => '2024-01-04T00:00:00Z', 'retrieved_at' => now()->utc()->toIso8601String(),
            'text' => self::CLAIM, 'text_truncated' => false,
        ]]);
        Http::fake(['api.openai.com/*' => function ($request) use ($variant) {
            $input = json_decode($request['input'][1]['content'], true);
            if ($request['text']['format']['name'] === 'claim_decomposition') {
                $data = ['claims' => [['quote' => self::CLAIM, 'kind' => 'factual', 'entities' => [], 'dates' => [],
                    'locations' => [], 'quantities' => [], 'qualifiers' => [], 'requires_context' => false, 'context_reason' => '',
                    'searches' => ['fictional municipal library record'], 'references' => []]], 'original_claim_date' => null, 'date_quote' => ''];
            } else {
                $this->assertSame(3000, $request['max_output_tokens']);
                $this->assertArrayNotHasKey('tools', $request->data());
                $this->assertSame(1, substr_count($request['input'][0]['content'], 'approximately 80–120 words'));
                $this->assertCount(1, $input['sources']);
                $data = ['context_findings' => [], 'relationships' => [[
                    'claim_id' => $input['claims'][0]['id'], 'source_id' => 's1', 'stance' => 'supporting',
                    'excerpt' => self::CLAIM, 'explanation' => 'The municipal record gives the same opening date.',
                    'matches' => array_fill_keys(['event', 'entities', 'action', 'date', 'location', 'quantities', 'qualifiers'], true),
                    'basis' => 'primary_record', 'supersedes_source_ids' => [], 'supersession_excerpt' => '',
                ]], 'wording' => [[
                    'verdict' => 'TRUE', 'explanation' => self::EXPLANATION, 'result_summary' => self::SHORT,
                    'recommended_action' => self::ACTION, 'source_ids' => ['s1'],
                ]]];
                if ($variant === 'wrong_verdict') {
                    $data['wording'][0]['verdict'] = 'FALSE';
                } elseif ($variant === 'invented_source') {
                    $data['wording'][0]['source_ids'] = ['not-reviewed'];
                } elseif ($variant === 'malformed') {
                    $data['wording'] = 'unusable presentation';
                } elseif ($variant === 'missing') {
                    unset($data['wording']);
                }
            }

            return Http::response(['status' => 'completed', 'model' => $request['model'],
                'usage' => ['input_tokens' => 100, 'output_tokens' => 100, 'total_tokens' => 200],
                'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($data)]]]]]);
        }]);
        $analysis = app(EvidenceVerificationService::class)->apply(['signals' => []], [
            'analysis_task' => 'claim', 'caption_text' => self::CLAIM, 'media_type' => 'text',
        ]);
        $result = $analysis['fact_check_result'];
        $this->assertSame('TRUE', $result['verdict']);
        $this->assertSame(['s1'], array_column($result['sources'], 'id'));
        $this->assertSame('completed', $result['diagnostics']['model_stages']['evidence_relationships_0']);
        $this->assertSame(400, $analysis['signals']['openai_usage']['total_tokens']);
        $saved = CanonicalFactCheckResult::sole()->result;
        if ($variant === 'valid') {
            $this->assertSame(self::EXPLANATION, $saved['generated_wording']['explanation']);
            $this->assertSame(self::SHORT, $saved['generated_wording']['result_summary']);
            $this->assertSame(self::ACTION, $saved['generated_wording']['recommended_action']);
        } else {
            $this->assertArrayNotHasKey('generated_wording', $saved);
            $before = $result;
            $fallback = ResultWording::ensure($result, self::ACTION);
            foreach (['verdict', 'sources', 'score', 'summary', 'answer_segments'] as $key) {
                $this->assertSame($before[$key], $fallback[$key]);
            }
            $this->assertNotNull(ResultWording::saved($fallback));
        }
        Http::assertSentCount(2); // Existing planning and evidence review only.
    }

    public function test_saved_copy_renders_as_one_paragraph_and_summary_without_requests_or_record_changes(): void
    {
        $this->mock(GoogleFactCheckFeedService::class)->shouldReceive('latest')->twice()->andReturn(['items' => []]);
        $user = User::factory()->create();
        $result = ResultWording::attach(['schema_version' => 4, 'primary_task' => 'claim', 'verdict' => 'TRUE',
            'summary' => 'Internal recorded evidence notes.', 'sources' => [['id' => 's1', 'name' => 'Municipal records',
                'url' => 'https://example.gov.ph/library-record', 'used_for' => 'Recorded opening date.']]], [[
                    'verdict' => 'TRUE', 'explanation' => self::EXPLANATION, 'result_summary' => self::SHORT,
                    'recommended_action' => self::ACTION, 'source_ids' => ['s1'],
                ]]);
        $detection = Detection::create(['user_id' => $user->id, 'source_kind' => 'upload', 'media_type' => 'image',
            'processing_status' => 'completed', 'verdict' => 'real', 'fake_score' => 0,
            'analysis_summary' => 'Old internal wording.', 'explanation_summary' => 'Old internal explanation.',
            'recommendation' => 'Old action.', 'fact_check_result' => $result]);
        $snapshot = $detection->fresh()->getRawOriginal();
        foreach (range(1, 2) as $refresh) {
            $html = $this->actingAs($user)->get(route('detections.result', $detection))->assertOk()
                ->assertSee('What the evidence shows')->assertSee('Why this result?')
                ->assertDontSee('View full explanation')->getContent();
            $document = new \DOMDocument;
            @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
            $xpath = new \DOMXPath($document);
            $paragraphs = $xpath->query('//div[contains(@class, "tg-verdict-explanation")]/p');
            $this->assertCount(1, $paragraphs);
            $this->assertSame(self::EXPLANATION, trim($paragraphs->item(0)->textContent));
            $this->assertSame(self::SHORT, trim($xpath->query('//p[contains(@class, "tg-interpretation-copy")]')->item(0)->textContent));
            $this->assertSame(self::ACTION, trim($xpath->query('//p[contains(@class, "tg-interpretation-recommendation")]')->item(0)->textContent));
        }
        $this->assertSame($snapshot, $detection->fresh()->getRawOriginal());
        Http::assertNothingSent();
    }
}
