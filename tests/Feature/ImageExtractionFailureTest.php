<?php

namespace Tests\Feature;

use App\Models\Detection;
use App\Models\User;
use App\Services\Detections\DetectionPipeline;
use App\Services\Detections\EvidenceVerificationService;
use App\Services\Detections\GoogleFactCheckFeedService;
use App\Services\Detections\OpenAiExtractionFailure;
use App\Services\Detections\ReportAnswer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ImageExtractionFailureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config()->set(['services.openai.key' => 'offline-test-key', 'services.openai.analysis_enabled' => true,
            'services.openai.claim_extraction_enabled' => true, 'services.openai.image_context_enabled' => true,
            'services.openai.extraction_model' => 'configured-routine-model', 'services.filipino_news_classifier.enabled' => false,
            'services.live_verification.enabled' => false, 'firebase.enabled' => false]);
    }

    public static function failures(): array
    {
        return [
            'invalid key' => [401, null, 'authentication_failed'],
            'denied access' => [403, 'permission_denied', 'access_denied'],
            'model unavailable' => [404, 'model_not_found', 'model_unavailable'],
            'region unavailable' => [403, 'unsupported_country_region_territory', 'unsupported_region'],
            'credits' => [429, 'credit_balance_exhausted', 'quota_exhausted'],
            'rate limited' => [429, null, 'rate_limited'],
            'provider unavailable' => [503, null, 'provider_unavailable'],
        ];
    }

    #[DataProvider('failures')]
    public function test_failed_image_reading_reports_the_service_reason_without_searches_or_paid_retries(int $status, ?string $code, string $reason): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['code' => $code, 'message' => 'private-provider-detail']], $status)]);
        $file = UploadedFile::fake()->createWithContent('notice.png', file_get_contents(base_path('tests/Fixtures/evidence/media/notice.png')));
        $analysis = app(EvidenceVerificationService::class)->apply(['signals' => []], [
            'analysis_task' => 'claim', 'caption_text' => 'Is the claim in this post true?', 'media_type' => 'image',
        ], $file);
        $result = $analysis['fact_check_result'];
        $this->assertSame('ANALYSIS_UNAVAILABLE', $result['verdict']);
        $this->assertSame($status, $result['extraction']['http_status']);
        $this->assertSame($reason, $result['extraction']['failure_reason']);
        $this->assertSame('extraction_unavailable', $result['diagnostics']['outcome_reason']);
        $this->assertSame('not_run', $result['diagnostics']['retrieval_status']);
        $this->assertSame([], $result['sources']);
        $this->assertSame(OpenAiExtractionFailure::explanation($result['extraction']), $result['generated_wording']['explanation']);
        $this->assertSame(OpenAiExtractionFailure::action($result['extraction']), $result['generated_wording']['recommended_action']);
        $this->assertStringNotContainsString('private-provider-detail', json_encode($analysis));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['model'] === 'configured-routine-model');
    }

    public function test_legacy_403_report_explains_access_failure_and_offers_manual_retry_without_mutating_or_calling_openai(): void
    {
        $this->mock(GoogleFactCheckFeedService::class)->shouldReceive('latest')->twice()->andReturn(['items' => []]);
        $user = User::factory()->create();
        $extraction = ['status' => 'unavailable', 'http_status' => 403, 'failure_reason' => 'authentication_failed'];
        $result = ReportAnswer::complete(['schema_version' => 4, 'verdict' => 'ANALYSIS_UNAVAILABLE', 'primary_task' => 'claim',
            'summary' => 'Text extraction was unavailable.', 'extraction' => $extraction,
            'diagnostics' => ['outcome_reason' => 'extraction_unavailable']]);
        $detection = Detection::create(['user_id' => $user->id, 'media_type' => 'image', 'source_kind' => 'upload',
            'media_path' => 'detections/fixture.png', 'processing_status' => 'completed', 'verdict' => 'review', 'fake_score' => 0,
            'analysis_summary' => $result['summary'], 'explanation_summary' => $result['summary'],
            'fact_check_result' => $result, 'signals' => ['submission_extraction' => [$extraction]]]);
        $snapshot = $detection->fresh()->getRawOriginal();
        foreach (range(1, 2) as $refresh) {
            $response = $this->actingAs($user)->get(route('detections.result', $detection))->assertOk()
                ->assertSee('Analysis Unavailable')->assertSee('HTTP 403')->assertSee('API access denied')
                ->assertSee('Retry assessment')->assertSee('API usage may apply.')->assertDontSee('View full explanation');
            $document = new \DOMDocument;
            @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
            $xpath = new \DOMXPath($document);
            $form = $xpath->query('//form[@action="'.route('detections.retry-media', $detection).'"]')->item(0);
            $this->assertNotNull($form);
            $this->assertSame('POST', $form->getAttribute('method'));
            $this->assertSame('claim', $xpath->query('.//input[@name="analysis_task"]', $form)->item(0)->getAttribute('value'));
            $this->assertSame(1, $xpath->query('.//input[@name="_token"]', $form)->length);
            $this->assertSame(OpenAiExtractionFailure::explanation($extraction), trim($xpath->query('//div[contains(@class, "tg-verdict-explanation")]/p')->item(0)->textContent));
            if (getenv('TRUTHGUARD_EXTRACTION_FAILURE_BROWSER_FIXTURE')) {
                file_put_contents(storage_path('tmp-extraction-failure-browser.html'), $response->getContent());
            }
        }
        $this->assertSame($snapshot, $detection->fresh()->getRawOriginal());
        Http::assertNothingSent();
    }

    public function test_manual_retry_reuses_the_original_upload_and_keeps_factual_mode(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('detections/fixture.png', file_get_contents(base_path('tests/Fixtures/evidence/media/notice.png')));
        $user = User::factory()->create();
        $detection = Detection::create(['user_id' => $user->id, 'media_type' => 'image', 'source_kind' => 'upload',
            'media_path' => 'detections/fixture.png', 'processing_status' => 'completed', 'verdict' => 'review', 'fake_score' => 0,
            'caption_text' => 'Is the claim in this post true?', 'fact_check_result' => ['verdict' => 'ANALYSIS_UNAVAILABLE']]);
        $this->mock(DetectionPipeline::class)->shouldReceive('run')->once()
            ->withArgs(fn ($owner, $input, $file, $pending) => $owner->is($user) && $pending->is($detection)
                && $input['analysis_task'] === 'claim' && $input['caption_text'] === $detection->caption_text
                && hash_file('sha256', $file->getRealPath()) === hash_file('sha256', base_path('tests/Fixtures/evidence/media/notice.png')))
            ->andReturn($detection);
        $this->actingAs(User::factory()->create())->post(route('detections.retry-media', $detection), ['analysis_task' => 'claim'])->assertNotFound();
        $this->actingAs($user)->post(route('detections.retry-media', $detection), ['analysis_task' => 'claim'])
            ->assertRedirect(route('detections.result', $detection))->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Assessment retry finished. Review the recorded status below.');
        $this->assertTrue(Storage::disk('public')->exists($detection->media_path));
        Http::assertNothingSent();
    }
}
