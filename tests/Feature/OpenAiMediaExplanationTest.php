<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Detections\AiOriginClassifier;
use App\Services\Detections\DeepfakeCnnClassifier;
use App\Services\Detections\DetectionPipeline;
use App\Services\Detections\EvidenceVerificationService;
use App\Services\Detections\GoogleFactCheckFeedService;
use App\Services\Detections\MediaAnalysisReport;
use App\Services\Detections\OpenAiMediaExplanation;
use App\Services\Detections\ReportAnswer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OpenAiMediaExplanationTest extends TestCase
{
    use RefreshDatabase;

    private const PROSE = 'This image is likely AI-generated according to the trained image detector. Its recorded output favors synthetic content over the alternative class. That is a screening finding, not proof of how the image was created. The detector did not identify a specific visual feature that caused its prediction. This result also does not determine whether an accompanying caption or news claim is true.';

    private const SHORT = 'The trained detector favors AI generation, so the image is likely AI-generated. This screening result does not prove its origin or establish whether an accompanying news claim is true.';

    private const ACTION = 'Check the original publication or creation record before sharing this image.';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config()->set(['services.openai.key' => 'offline-test-key', 'services.openai.model' => 'gpt-5.4',
            'services.openai.analysis_enabled' => true, 'services.openai.media_explanation_enabled' => true,
            'services.openai.media_explanation_max_tokens' => 400,
            'services.ai_origin.enabled' => true, 'services.filipino_news_classifier.enabled' => false,
            'services.live_verification.enabled' => false, 'fact_check.enhanced_enabled' => true]);
    }

    private function analysis(): array
    {
        $media = ['status' => 'completed', 'media_type' => 'image', 'label' => 'fake',
            'fake_probability' => .97, 'threshold' => .475, 'model_version' => 'fixture',
            'output_contract' => ['activation' => 'sigmoid', 'positive_class' => 'fake', 'binary_exclusive' => true],
            'label_mapping' => ['real', 'fake'], 'assessed_at' => now()->toIso8601String()];
        $result = ReportAnswer::complete(MediaAnalysisReport::result($media, ['status' => 'not_requested']));

        return ['fact_check_result' => $result, 'media_assessment' => $media, 'signals' => [], 'verdict' => 'fake',
            'analysis_summary' => $result['summary'], 'explanation_summary' => $result['summary']];
    }

    private function fakeResponse(array $answer = [], string $status = 'completed'): void
    {
        $answer += ['verdict' => 'LIKELY_AI_GENERATED', 'explanation' => self::PROSE,
            'result_summary' => self::SHORT, 'recommended_action' => self::ACTION, 'source_ids' => []];
        Http::fake(['api.openai.com/v1/responses' => Http::response([
            'id' => 'resp_offline_fixture', 'model' => 'gpt-5.4', 'status' => $status,
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text',
                'text' => json_encode($answer)]]]],
            'usage' => ['input_tokens' => 450, 'output_tokens' => 110, 'total_tokens' => 560],
        ])]);
    }

    public function test_narration_is_one_small_text_only_request_and_preserves_verdict_score_and_sources(): void
    {
        $this->fakeResponse();
        $before = $this->analysis();
        $before['signals']['openai_usage'] = ['input_tokens' => 10, 'output_tokens' => 5, 'total_tokens' => 15];
        $after = app(OpenAiMediaExplanation::class)->apply($before);
        $this->assertSame(self::PROSE, $after['fact_check_result']['summary']);
        $this->assertSame(self::SHORT, $after['fact_check_result']['generated_wording']['result_summary']);
        $this->assertSame(self::ACTION, $after['fact_check_result']['generated_wording']['recommended_action']);
        foreach (['verdict', 'score', 'sources', 'media_assessment', 'limitations'] as $key) {
            $this->assertSame($before['fact_check_result'][$key], $after['fact_check_result'][$key]);
        }
        $this->assertSame(575, $after['signals']['openai_usage']['total_tokens']);
        $this->assertSame('completed', $after['signals']['media_explanation'][0]['status']);
        $this->assertSame($after, app(OpenAiMediaExplanation::class)->apply($after));
        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            $body = $request->data();

            return $body['max_output_tokens'] === 400 && $body['store'] === false
                && $body['reasoning']['effort'] === 'none' && ! isset($body['tools'])
                && strlen(json_encode($body)) <= 7000 && ! str_contains(json_encode($body), 'input_image')
                && str_contains($body['input'][0]['content'], 'Never invent visual defects');
        });
    }

    public function test_claim_explanations_reuse_reviewed_openai_prose_with_publisher_attribution_without_a_request(): void
    {
        $result = ReportAnswer::complete(['verdict' => 'FALSE', 'primary_task' => 'claim',
            'claim' => 'A fictional local election was canceled.', 'summary' => 'The evidence refutes the assertion.',
            'sources' => [['id' => 's1', 'url' => 'https://rappler.com/example-fixture', 'name' => 'Rappler',
                'used_for' => 'The election remains scheduled in the reviewed announcement. The cancellation assertion is contradicted by that record.']]]);
        $after = app(OpenAiMediaExplanation::class)->apply(['fact_check_result' => $result]);
        $this->assertStringContainsString('Rappler reports:', $after['fact_check_result']['summary']);
        $this->assertSame(['s1'], $result['answer_segments'][1]['source_ids']);
        $this->assertSame('FALSE', $after['fact_check_result']['verdict']);
        Http::assertNothingSent();
    }

    public function test_disabled_or_incomplete_detector_uses_existing_explanation_without_a_request(): void
    {
        config()->set('services.openai.media_explanation_enabled', false);
        $analysis = $this->analysis();
        $this->assertSame($analysis, app(OpenAiMediaExplanation::class)->apply($analysis));
        config()->set('services.openai.media_explanation_enabled', true);
        $analysis['fact_check_result']['media_assessment']['status'] = 'unavailable';
        $this->assertSame($analysis, app(OpenAiMediaExplanation::class)->apply($analysis));
        Http::assertNothingSent();
    }

    public function test_automatic_image_reading_usage_is_kept_in_the_report_total(): void
    {
        $this->fakeResponse();
        $analysis = $this->analysis();
        $analysis['signals']['submission_extraction'] = [['usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'total_tokens' => 15]]];
        $after = app(OpenAiMediaExplanation::class)->apply($analysis);
        $this->assertSame(575, $after['signals']['openai_usage']['total_tokens']);
        Http::assertSentCount(1);
    }

    public function test_inconclusive_media_is_explained_without_promoting_the_verdict(): void
    {
        $prose = 'The image origin remains inconclusive. The trained detector did not produce a sufficiently decisive signal to resolve whether the upload was generated by AI. This uncertainty does not establish human origin or prove that the image is authentic. The associated factual claims have not been verified by this media screening.';
        $this->fakeResponse(['verdict' => 'INCONCLUSIVE', 'explanation' => $prose,
            'result_summary' => 'The detector did not resolve the image origin. This does not establish human origin or authenticity, and the accompanying factual claims have not been verified by this screening.']);
        $before = $this->analysis();
        $before['fact_check_result']['verdict'] = 'INCONCLUSIVE';
        $before['fact_check_result']['assessment_label'] = 'Inconclusive';
        $after = app(OpenAiMediaExplanation::class)->apply($before);
        $this->assertSame('INCONCLUSIVE', $after['fact_check_result']['verdict']);
        $this->assertSame($prose, $after['fact_check_result']['summary']);
        Http::assertSentCount(1);
    }

    public function test_changed_verdict_is_rejected_without_changing_original_finding(): void
    {
        $this->fakeResponse(['verdict' => 'TRUE', 'explanation' => self::PROSE]);
        $before = $this->analysis();
        $after = app(OpenAiMediaExplanation::class)->apply($before);
        $this->assertSame($before['fact_check_result'], $after['fact_check_result']);
        $this->assertSame('unavailable', $after['signals']['media_explanation'][0]['status']);
        Http::assertSentCount(1);
    }

    public function test_percentage_in_narration_is_rejected(): void
    {
        $this->fakeResponse(['verdict' => 'LIKELY_AI_GENERATED', 'explanation' => self::PROSE.' Confidence: 97%.']);
        $before = $this->analysis();
        $after = app(OpenAiMediaExplanation::class)->apply($before);
        $this->assertSame($before['fact_check_result'], $after['fact_check_result']);
        Http::assertSentCount(1);
    }

    public function test_quota_error_keeps_original_result_and_does_not_retry(): void
    {
        Http::fake(['api.openai.com/v1/responses' => Http::response(['error' => ['code' => 'insufficient_quota']], 429)]);
        $before = $this->analysis();
        $after = app(OpenAiMediaExplanation::class)->apply($before);
        $this->assertSame($before['fact_check_result'], $after['fact_check_result']);
        $this->assertSame('quota_exhausted', $after['signals']['media_explanation'][0]['failure_reason']);
        Http::assertSentCount(1);
    }

    public function test_invented_source_link_is_rejected(): void
    {
        $this->fakeResponse(['verdict' => 'LIKELY_AI_GENERATED', 'explanation' => self::PROSE.' See https://example.com/invented.']);
        $before = $this->analysis();
        $after = app(OpenAiMediaExplanation::class)->apply($before);
        $this->assertSame($before['fact_check_result'], $after['fact_check_result']);
        Http::assertSentCount(1);
    }

    public function test_incomplete_response_keeps_local_explanation_and_records_returned_usage(): void
    {
        $this->fakeResponse(status: 'incomplete');
        $before = $this->analysis();
        $after = app(OpenAiMediaExplanation::class)->apply($before);
        $this->assertSame($before['fact_check_result'], $after['fact_check_result']);
        $this->assertSame(560, $after['signals']['openai_usage']['total_tokens']);
        Http::assertSentCount(1);
    }

    public function test_repeated_image_reuses_saved_explanation_before_any_new_api_call(): void
    {
        Storage::fake('public');
        $this->fakeResponse();
        $analysis = $this->analysis();
        $this->mock(DeepfakeCnnClassifier::class)->shouldNotReceive('classify');
        $this->mock(AiOriginClassifier::class)->shouldReceive('classify')->once()->andReturn($analysis['media_assessment']);
        $this->mock(EvidenceVerificationService::class)->shouldReceive('apply')->once()
            ->andReturnUsing(fn ($local) => array_replace($local, $analysis));
        $user = User::factory()->create();
        $submit = fn () => app(DetectionPipeline::class)->run($user,
            ['analysis_task' => 'media', 'caption_text' => 'Check image origin.'],
            UploadedFile::fake()->createWithContent('fixture.png', file_get_contents(base_path('tests/Fixtures/evidence/media/notice.png'))));
        $first = $submit();
        $second = $submit();
        $this->assertSame($first->id, $second->reused_from_detection_id);
        $this->assertSame(self::PROSE, $first->analysis_summary);
        $this->assertSame($first->analysis_summary, $second->analysis_summary);
        $this->assertSame($first->fresh()->fact_check_result['generated_wording'], $second->fresh()->fact_check_result['generated_wording']);
        $this->assertSame(0, $second->signals['openai_usage']['total_tokens']);
        $this->mock(GoogleFactCheckFeedService::class)->shouldReceive('latest')->andReturn(['items' => []]);
        $snapshot = $first->fresh()->getRawOriginal();
        foreach (range(1, 2) as $refresh) {
            $this->actingAs($user)->get(route('detections.result', $first))->assertOk()->assertSee(self::PROSE)
                ->assertSee(self::SHORT)->assertSee(self::ACTION)->assertDontSee('View full explanation');
        }
        $this->assertSame($snapshot, $first->fresh()->getRawOriginal());
        Http::assertSentCount(1);
    }
}
