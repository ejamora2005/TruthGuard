<?php

namespace Tests\Feature;

use App\Models\Detection;
use App\Models\User;
use App\Services\Detections\AiOriginClassifier;
use App\Services\Detections\DeepfakeCnnClassifier;
use App\Services\Detections\Evidence\StructuredAnalysis;
use App\Services\Detections\OpenAiDetectionReportService;
use App\Services\Detections\SubmissionTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PhotoQuestionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Http::preventStrayRequests();
        config()->set(['fact_check.enhanced_enabled' => true, 'fact_check.queue_enabled' => false,
            'fact_check.web_search.enabled' => false, 'services.openai.analysis_enabled' => false,
            'services.filipino_news_classifier.enabled' => false, 'services.google_fact_check.key' => 'fixture',
            'services.gnews.key' => '', 'services.newsapi.key' => '', 'playwright.related_posts_inline' => false]);
        Http::fake(['factchecktools.googleapis.com/*' => Http::response(['claims' => []], 200)]);
    }

    public static function eventQuestions(): array
    {
        return [
            ['check if this is real that sarah duterte higged that old lady', 'sarah duterte higged that old lady'],
            ['is this image real and verify if sarah duterte is real hugging that', 'sarah duterte is real hugging that'],
            ['Did Sara Duterte hug this woman?', 'Sara Duterte hug this woman'],
            ['Is it true that the mayor visited this village?', 'the mayor visited this village'],
        ];
    }

    public static function genericQuestions(): array
    {
        return [['was this real?'], ['Is this real?'], ['Is this fake?'], ['Is this legit?'], ['Totoo ba ito?']];
    }

    #[DataProvider('genericQuestions')]
    public function test_general_verification_never_invokes_an_ai_detector_when_ocr_is_unavailable(string $question): void
    {
        $this->mock(DeepfakeCnnClassifier::class)->shouldNotReceive('classify');
        $this->mock(AiOriginClassifier::class)->shouldNotReceive('classify');
        $this->mock(OpenAiDetectionReportService::class, function ($mock) {
            $mock->shouldReceive('extractClaimText')->once()->andReturn(null);
            $mock->shouldReceive('extractionDetails')->once()->andReturn(['status' => 'unavailable',
                'failure_reason' => 'quota_exhausted', 'visible_text' => '', 'inferred_claim' => '']);
        });
        $d = $this->submit($question);
        $r = $d->fact_check_result;
        $this->assertSame('claim', $r['primary_task']);
        $this->assertSame(['claim'], $r['request']['tasks']);
        $this->assertSame('ANALYSIS_UNAVAILABLE', $r['verdict']);
        $this->assertSame('not_requested', $r['media_assessment']['status']);
        $this->get(route('detections.result', $d))->assertOk()->assertSee('Fact-check verdict')
            ->assertDontSee('AI-generation assessment')->assertDontSee('Likely Not AI-Generated');
    }

    public function test_old_wrong_task_report_does_not_keep_presenting_an_ai_score_as_the_answer_to_a_factual_question(): void
    {
        $d = $this->mediaReport();
        $saved = $d->fact_check_result;
        $this->assertSame('LIKELY_NOT_AI_GENERATED', $saved['verdict']);
        $this->assertEqualsWithDelta(.9, $saved['score']['value'], .00001);
        $saved['request'] = ['question' => 'was this real?', 'tasks' => ['media'], 'selected_mode' => 'auto', 'resolved_task' => 'media'];
        $d->update(['fact_check_result' => $saved]);
        $this->get(route('detections.result', $d))->assertOk()->assertSee('Fact-check verdict')
            ->assertSee('assessed image origin instead of verifying the post')
            ->assertDontSee('AI-generation assessment')->assertDontSee('Model score: 90%');
        $this->getJson(route('detections.result', $d))->assertJsonPath('primary_task', 'claim')
            ->assertJsonPath('verdict', 'ANALYSIS_UNAVAILABLE')->assertJsonPath('score.value', null);
        $this->assertSame($saved, $d->fresh()->fact_check_result);
        $saved['request']['selected_mode'] = 'media';
        $d->update(['fact_check_result' => $saved]);
        $this->getJson(route('detections.result', $d))->assertJsonPath('primary_task', 'media');
    }

    #[DataProvider('eventQuestions')]
    public function test_inline_event_question_reaches_factual_verification_without_being_discarded(string $question, string $proposition): void
    {
        $this->assertSame($proposition, SubmissionTask::claimText($question));
        $this->assertSame('claim', SubmissionTask::primary(['caption_text' => $question, 'media_type' => 'image', 'analysis_task' => 'auto']));
        $this->mock(DeepfakeCnnClassifier::class)->shouldNotReceive('classify');
        $this->mock(AiOriginClassifier::class)->shouldNotReceive('classify');
        $this->mock(OpenAiDetectionReportService::class, function ($mock) {
            $mock->shouldReceive('extractClaimText')->once()->andReturn(null);
            $mock->shouldReceive('extractionDetails')->once()->andReturn(['status' => 'completed', 'visible_text' => '', 'inferred_claim' => '']);
        });
        $unclear = str_contains($question, 'higged');
        $this->partialMock(StructuredAnalysis::class)->shouldReceive('ask')->once()
            ->withArgs(fn ($stage, $instruction, $input) => $stage === 'claim_decomposition' && $input['original_text'] === $proposition)
            ->andReturn(['claims' => [['quote' => $proposition, 'kind' => $unclear ? 'unclear' : 'factual', 'entities' => [], 'dates' => [], 'locations' => [],
                'quantities' => [], 'qualifiers' => [], 'requires_context' => $unclear, 'context_reason' => $unclear ? 'The pictured person and date are not established.' : '',
                'searches' => ['person embrace original photograph', 'person woman event photograph'], 'references' => []]],
                'original_claim_date' => null, 'date_quote' => '']);
        $detection = $this->submit($question);
        $result = $detection->fact_check_result;
        $this->assertSame('claim', $result['primary_task']);
        $this->assertSame($question, $result['request']['question']);
        $this->assertSame($proposition, $result['claims'][0]['text']);
        $this->assertSame('not_requested', $result['media_assessment']['status']);
        $this->assertNotSame('CLARIFICATION_NEEDED', $result['verdict']);
        $this->assertSame('UNVERIFIED', $result['verdict']);
        $this->assertStringNotContainsString('clarification', $result['summary']);
        $this->assertSame('factual', $result['claims'][0]['kind']);
        $this->get(route('detections.result', $detection))->assertOk()->assertSee('Fact-check verdict')
            ->assertDontSee('One clarification')->assertDontSee('Choose what to check')
            ->assertDontSee('Do you want to check')->assertDontSee('Check AI generation')->assertDontSee('Verify a statement');
    }

    public function test_old_task_choice_report_is_read_only_and_no_longer_asks_a_question(): void
    {
        $d = $this->mediaReport();
        $r = $d->fact_check_result;
        $r['primary_task'] = 'clarification';
        $r['verdict'] = 'CLARIFICATION_NEEDED';
        $r['summary'] = 'Do you want to check whether this media is AI-generated, or whether a statement in it is true?';
        $r['answer_segments'] = [['text' => $r['summary'], 'source_ids' => []]];
        $d->update(['fact_check_result' => $r]);
        $this->get(route('detections.result', $d))->assertOk()->assertSee('Inconclusive')
            ->assertDontSee('One clarification')->assertDontSee('Choose what to check')
            ->assertDontSee('Do you want to check')->assertDontSee('Check AI generation')->assertDontSee('Verify a statement');
        $this->assertSame($r, $d->fresh()->fact_check_result);
    }

    public function test_a_question_and_poster_reach_one_factual_target_instead_of_becoming_year_and_logo_claims(): void
    {
        $this->mock(DeepfakeCnnClassifier::class)->shouldNotReceive('classify');
        $this->mock(AiOriginClassifier::class)->shouldNotReceive('classify');
        $this->mock(OpenAiDetectionReportService::class, function ($mock) {
            $mock->shouldReceive('extractClaimText')->once()->andReturn(null);
            $mock->shouldReceive('extractionDetails')->once()->andReturn(['status' => 'completed',
                'visible_text' => "RIVERSIDE LIBRARY\n\n2024\n\nXII\n\nLIBRARY REOPENING: A NEW CHAPTER", 'inferred_claim' => '']);
        });
        $this->partialMock(StructuredAnalysis::class)->shouldReceive('ask')->once()->withArgs(function ($stage, $instructions, $input, $schema) {
            $this->assertSame('the library really reopened', $input['question_target']);
            $this->assertStringContainsString('RIVERSIDE LIBRARY', $input['original_text']);
            $this->assertSame(['the library really reopened'], $schema['properties']['claims']['items']['properties']['quote']['enum']);

            return true;
        })->andReturn(['claims' => [['quote' => 'the library really reopened', 'kind' => 'factual',
            'entities' => ['RIVERSIDE LIBRARY'], 'dates' => ['2024'], 'locations' => [], 'quantities' => [], 'qualifiers' => [],
            'references' => [['phrase' => 'the library', 'referent' => 'RIVERSIDE LIBRARY', 'context_quote' => 'RIVERSIDE LIBRARY']],
            'searches' => ['Riverside library 2024 reopening records'], 'requires_context' => false, 'context_reason' => '']],
            'original_claim_date' => null, 'date_quote' => '']);
        $detection = $this->submit('Was the library really reopened?');
        $result = $detection->fact_check_result;
        $this->assertCount(1, $result['claims']);
        $this->assertSame('the library really reopened', $result['claims'][0]['text']);
        $this->assertSame('UNVERIFIED', $result['verdict']);
        $this->assertStringNotContainsString('identity or event depicted', $result['summary']);
        $this->get(route('detections.result', $detection))->assertOk()
            ->assertSee('Was the library really reopened?')->assertDontSee('Missing Context');
    }

    public function test_retry_limit_returns_to_report_and_does_not_block_other_reports(): void
    {
        $d = $this->mediaReport();
        for ($i = 0; $i < 3; $i++) {
            $this->post(route('detections.retry-media', $d))->assertRedirect(route('detections.result', $d));
        }
        $this->post(route('detections.retry-media', $d))->assertRedirect(route('detections.result', $d))
            ->assertSessionHas('status', fn ($message) => str_contains($message, 'saved result is still available'));
        $this->get(route('detections.result', $d))->assertOk()->assertSee('saved result is still available');
        $this->postJson(route('detections.retry-media', $d))->assertStatus(429)->assertJsonStructure(['message', 'retry_after']);
        $another = $this->submit('is this ai or not');
        $this->post(route('detections.retry-media', $another))->assertRedirect(route('detections.result', $another))
            ->assertSessionHas('status', 'Assessment retry finished. Review the recorded status below.');
    }

    private function mediaReport(): Detection
    {
        config()->set('services.ai_origin.enabled', true);
        $this->mock(DeepfakeCnnClassifier::class)->shouldNotReceive('classify');
        $this->mock(AiOriginClassifier::class)->shouldReceive('classify')->andReturn([
            'status' => 'completed', 'media_type' => 'image', 'label' => 'real', 'fake_probability' => .1,
            'threshold' => .5, 'model_version' => 'mocked-screening-only', 'label_mapping' => ['real', 'fake'],
            'output_contract' => ['activation' => 'sigmoid', 'positive_class' => 'fake', 'binary_exclusive' => true],
        ]);

        return $this->submit('is this ai or not');
    }

    private function submit(string $question): Detection
    {
        if (! auth()->check()) {
            $this->actingAs(User::factory()->create());
        }
        $this->post(route('detections.store'), ['caption_text' => $question, 'analysis_task' => 'auto',
            'media_file' => new UploadedFile(base_path('tests/Fixtures/evidence/media/notice.png'), 'submitted.png', null, null, true),
        ])->assertSessionHasNoErrors()->assertRedirect();

        return Detection::latest('id')->firstOrFail();
    }
}
