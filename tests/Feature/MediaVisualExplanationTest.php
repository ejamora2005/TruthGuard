<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Detections\AiOriginClassifier;
use App\Services\Detections\DeepfakeCnnClassifier;
use App\Services\Detections\DetectionPipeline;
use App\Services\Detections\Evidence\MediaEvidencePreparer;
use App\Services\Detections\EvidenceVerificationService;
use App\Services\Detections\GoogleFactCheckFeedService;
use App\Services\Detections\MediaAnalysisReport;
use App\Services\Detections\MediaVisualExplanation;
use App\Services\Detections\OpenAiMediaExplanation;
use App\Services\Detections\ReportAnswer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MediaVisualExplanationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config()->set(['services.openai.analysis_enabled' => true, 'services.openai.media_explanation_enabled' => true,
            'services.openai.key' => 'offline-fixture', 'services.openai.model' => 'gpt-5.4',
            'services.openai.media_explanation_max_tokens' => 400,
            'fact_check.enhanced_enabled' => true, 'fact_check.result_reuse.enabled' => true,
            'fact_check.queue_enabled' => false, 'services.ai_origin.enabled' => true,
            'services.deepfake_cnn.enabled' => false, 'services.filipino_news_classifier.enabled' => false,
            'services.live_verification.enabled' => false, 'playwright.related_posts_inline' => false]);
    }

    private function image(): UploadedFile
    {
        return new UploadedFile(base_path('tests/Fixtures/evidence/media/notice.png'), 'notice.png', null, null, true);
    }

    private function analysis(string $label = 'real'): array
    {
        $media = ['status' => 'completed', 'media_type' => 'image', 'label' => $label,
            'fake_probability' => $label === 'real' ? .01 : .99,
            'input_sha256' => hash_file('sha256', $this->image()->getPathname())];

        return ['fact_check_result' => ReportAnswer::complete(MediaAnalysisReport::result($media, ['status' => 'not_requested'])),
            'media_assessment' => $media, 'signals' => []];
    }

    private function observations(): array
    {
        // Describes the actual synthetic notice fixture, without fact-checking its words.
        return ['image_description' => 'The image shows a text notice on a white background.', 'observations' => [
            ['region' => 'the heading', 'detail' => 'The letters have clean edges.', 'kind' => 'appearance', 'visibility' => 'clear'],
            ['region' => 'the lower half', 'detail' => 'Most of this area is blank.', 'kind' => 'appearance', 'visibility' => 'clear'],
        ]];
    }

    private function prepareAndRespond(?array $answer = null): void
    {
        $this->mock(MediaEvidencePreparer::class)->shouldReceive('prepare')->once()->withArgs(fn ($file, $kind) => $kind === 'image' && hash_file('sha256', $file->getPathname()) === hash_file('sha256', $this->image()->getPathname()))
            ->andReturn(['status' => 'completed', 'inputs' => [['type' => 'input_image',
                'image_url' => 'data:image/jpeg;base64,'.base64_encode(file_get_contents(base_path('tests/Fixtures/evidence/media/notice.jpg')))]]]);
        Http::fake(['api.openai.com/v1/responses' => Http::response(['status' => 'completed',
            'usage' => ['input_tokens' => 800, 'output_tokens' => 80, 'total_tokens' => 880],
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text',
                'text' => json_encode($answer ?? $this->observations())]]]]])]);
    }

    public static function labels(): array
    {
        return [['real'], ['fake']];
    }

    #[DataProvider('labels')]
    public function test_actual_pixels_are_observed_without_the_label_and_cannot_change_the_detector_finding(string $label): void
    {
        $this->prepareAndRespond();
        $before = $this->analysis($label);
        $after = app(OpenAiMediaExplanation::class)->apply($before, $this->image());
        foreach (['verdict', 'score', 'sources', 'media_assessment', 'limitations'] as $key) {
            $this->assertSame($before['fact_check_result'][$key], $after['fact_check_result'][$key]);
        }
        $this->assertStringNotContainsString('text notice', $after['fact_check_result']['summary']);
        $this->assertStringNotContainsString('The heading: The letters have clean edges.', $after['fact_check_result']['summary']);
        $this->assertStringContainsString('recorded model output favoured', $after['fact_check_result']['summary']);
        $this->assertStringNotContainsString('threshold', $after['fact_check_result']['summary']);
        $this->assertStringNotContainsString('Specific visual details were not recorded', $after['fact_check_result']['summary']);
        $this->assertSame('completed', $after['signals']['media_explanation'][0]['status']);
        $this->assertSame(880, $after['signals']['openai_usage']['total_tokens']);
        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            $content = $request['input'][1]['content'];
            $basis = json_decode($content[0]['text'], true);

            return $content[1]['type'] === 'input_image' && str_starts_with($content[1]['image_url'], 'data:image/jpeg;base64,')
                && ! isset($basis['verdict'], $basis['detector'], $basis['score']) && ! isset($request['tools'])
                && ! isset($request['text']['format']['schema']['properties']['verdict'])
                && $request['max_output_tokens'] === 400;
        });
    }

    public function test_a_different_or_unbound_image_cannot_supply_visual_reasons_for_the_saved_detector(): void
    {
        $analysis = $this->analysis();
        $analysis['fact_check_result']['media_assessment']['input_sha256'] = str_repeat('a', 64);
        $this->mock(MediaEvidencePreparer::class)->shouldNotReceive('prepare');
        $after = app(OpenAiMediaExplanation::class)->apply($analysis, $this->image());
        $this->assertSame($analysis['fact_check_result'], $after['fact_check_result']);
        $this->assertSame('image_detector_binding_unavailable', $after['signals']['media_explanation'][0]['failure_reason']);
        Http::assertNothingSent();
    }

    public static function invalidObservations(): array
    {
        return [['The picture was made with AI.'], ['The image is real.'], ['The metadata proves its origin.'],
            ['The detector found distorted hands.'], ['See https://example.com/proof.'], ['Confidence is 99%.']];
    }

    #[DataProvider('invalidObservations')]
    public function test_visual_responses_cannot_claim_origin_metadata_or_detector_attribution(string $detail): void
    {
        $answer = $this->observations();
        $answer['observations'][0]['detail'] = $detail;
        $this->prepareAndRespond($answer);
        $before = $this->analysis();
        $after = app(OpenAiMediaExplanation::class)->apply($before, $this->image());
        $this->assertSame($before['fact_check_result'], $after['fact_check_result']);
        $this->assertSame('unavailable', $after['signals']['media_explanation'][0]['status']);
        Http::assertSentCount(1);
    }

    public function test_uncertain_features_are_omitted_instead_of_becoming_a_reason_to_call_the_image_fake(): void
    {
        $answer = $this->observations();
        $answer['observations'][0]['visibility'] = 'uncertain';
        $this->prepareAndRespond($answer);
        $after = app(OpenAiMediaExplanation::class)->apply($this->analysis(), $this->image());
        $this->assertStringNotContainsString('clean edges', $after['fact_check_result']['summary']);
        $this->assertStringNotContainsString('Most of this area is blank', $after['fact_check_result']['summary']);
    }

    public function test_saved_scene_captions_are_not_presented_as_ai_evidence_and_empty_descriptions_are_supported(): void
    {
        $result = $this->analysis()['fact_check_result'];
        $hash = $result['media_assessment']['input_sha256'];
        $result['visual_observations'] = MediaVisualExplanation::accept([
            'image_description' => 'A wooden fishing boat rests on a tropical beach.',
            'observations' => [['region' => 'the foreground', 'detail' => 'Blue nets hang over the wooden hull.',
                'kind' => 'appearance', 'visibility' => 'clear']]], $hash);
        $public = ReportAnswer::forPresentation($result);
        $this->assertStringNotContainsString('wooden', $public['summary']);
        $this->assertStringNotContainsString('Blue nets', $public['summary']);
        $this->assertStringContainsString('recorded model output favoured the non-AI class', $public['summary']);
        $this->assertStringContainsString('does not rule out AI generation', $public['summary']);
        $this->assertNotNull(MediaVisualExplanation::accept(['image_description' => '', 'observations' => []], $hash));
        $this->assertStringContainsString('Leave image_description empty', MediaVisualExplanation::instructions());
    }

    public function test_saved_observations_must_remain_bound_and_do_not_change_fact_check_presentation(): void
    {
        $result = $this->analysis()['fact_check_result'];
        $hash = $result['media_assessment']['input_sha256'];
        $result['visual_observations'] = MediaVisualExplanation::accept($this->observations(), $hash);
        $public = ReportAnswer::forPresentation($result);
        $this->assertStringNotContainsString('clean edges', $public['summary']);
        $result['visual_observations']['input_sha256'] = str_repeat('b', 64);
        $this->assertStringNotContainsString('clean edges', ReportAnswer::forPresentation($result)['summary']);
        $result['primary_task'] = 'claim';
        $this->assertSame($result, MediaVisualExplanation::present($result));
        Http::assertNothingSent();
    }

    public function test_unreadable_images_keep_the_detector_result_without_paying_for_a_guessed_explanation(): void
    {
        $this->mock(MediaEvidencePreparer::class)->shouldReceive('prepare')->once()
            ->andReturn(['status' => 'media_preparation_unavailable', 'inputs' => []]);
        $before = $this->analysis();
        $after = app(OpenAiMediaExplanation::class)->apply($before, $this->image());
        $this->assertSame($before['fact_check_result'], $after['fact_check_result']);
        $this->assertSame('image_preparation_unavailable', $after['signals']['media_explanation'][0]['failure_reason']);
        Http::assertNothingSent();
    }

    public function test_observations_do_not_resolve_an_inconclusive_origin_and_do_not_add_calls_to_inconclusive_video(): void
    {
        $this->prepareAndRespond();
        $analysis = $this->analysis();
        $analysis['fact_check_result']['verdict'] = 'INCONCLUSIVE';
        $analysis['fact_check_result']['media_assessment']['origin_policy'] = ['inconclusive' => true];
        $beforePublic = ReportAnswer::forPresentation($analysis['fact_check_result']);
        $after = app(OpenAiMediaExplanation::class)->apply($analysis, $this->image());
        $this->assertSame('INCONCLUSIVE', $after['fact_check_result']['verdict']);
        $public = ReportAnswer::forPresentation($after['fact_check_result']);
        $this->assertSame($beforePublic['verdict'], $public['verdict']);
        $this->assertSame($beforePublic['score'], $public['score']);
        $this->assertStringNotContainsString('clean edges', $public['summary']);
        $analysis['fact_check_result']['media_assessment']['media_type'] = 'video';
        $this->assertSame($analysis, app(OpenAiMediaExplanation::class)->apply($analysis, $this->image()));
        Http::assertSentCount(1);
    }

    public function test_observations_cannot_add_a_verdict_and_lack_of_artifacts_does_not_override_a_positive_detector(): void
    {
        $answer = $this->observations();
        $hash = $this->analysis()['media_assessment']['input_sha256'];
        $this->assertNull(MediaVisualExplanation::accept($answer + ['verdict' => 'REAL'], $hash));
        $result = $this->analysis('fake')['fact_check_result'];
        $result['visual_observations'] = MediaVisualExplanation::accept($answer, $hash);
        $public = MediaVisualExplanation::present($result);
        $this->assertSame($result['verdict'], $public['verdict']);
        $this->assertStringContainsString('did not record feature-level evidence', $public['summary']);
        $answer['observations'][0]['visibility'] = $answer['observations'][1]['visibility'] = 'uncertain';
        $result['visual_observations'] = MediaVisualExplanation::accept($answer, $hash);
        $this->assertStringNotContainsString('clean edges', MediaVisualExplanation::present($result)['summary']);
    }

    public function test_new_upload_reuse_and_repeated_page_reads_preserve_visible_observations_at_no_extra_cost(): void
    {
        Storage::fake('public');
        $this->prepareAndRespond();
        $analysis = $this->analysis();
        $this->mock(DeepfakeCnnClassifier::class)->shouldNotReceive('classify');
        $this->mock(AiOriginClassifier::class)->shouldReceive('classify')->once()->andReturn($analysis['media_assessment']);
        $this->mock(EvidenceVerificationService::class)->shouldReceive('apply')->once()
            ->andReturnUsing(fn ($local) => array_replace($local, $analysis));
        $this->mock(GoogleFactCheckFeedService::class)->shouldReceive('latest')->andReturn(['items' => []]);
        $user = User::factory()->create();
        $submit = fn () => app(DetectionPipeline::class)->run($user,
            ['analysis_task' => 'media'], UploadedFile::fake()->createWithContent('notice.png', file_get_contents($this->image()->getPathname())));
        $first = $submit();
        $second = $submit();
        $this->assertSame($first->id, $second->reused_from_detection_id);
        $this->assertSame(0, $second->signals['openai_usage']['total_tokens']);
        $before = $first->fresh()->getRawOriginal();
        foreach ([$first, $second, $first] as $record) {
            $html = $this->actingAs($user)->get(route('detections.result', $record))->assertOk()->getContent();
            $document = new \DOMDocument;
            @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
            $node = (new \DOMXPath($document))->query('//div[contains(@class,"tg-verdict-explanation")]')->item(0);
            $this->assertStringNotContainsString('The heading: The letters have clean edges.', $node->textContent);
            $this->assertStringContainsString('recorded model output favoured', $node->textContent);
            $this->assertStringNotContainsString('threshold', $node->textContent);
            $this->assertStringNotContainsString('benchmark', $node->textContent);
        }
        $this->getJson(route('detections.result', $first))->assertOk()
            ->assertJsonPath('summary', fn ($summary) => str_contains($summary, 'recorded model output favoured'));
        $this->assertSame($before, $first->fresh()->getRawOriginal());
        Http::assertSentCount(1);
    }
}
