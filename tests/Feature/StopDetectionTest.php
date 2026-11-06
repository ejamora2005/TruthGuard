<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeDetectionEvidence;
use App\Models\Detection;
use App\Models\User;
use App\Services\Detections\ActiveDetectionManager;
use App\Services\Detections\AiOriginClassifier;
use App\Services\Detections\AnalysisStopped;
use App\Services\Detections\DeepfakeCnnClassifier;
use App\Services\Detections\DetectionPipeline;
use App\Services\Detections\Evidence\ReportApiBudget;
use App\Services\Detections\Evidence\ResponsesTransport;
use App\Services\Detections\Evidence\RetrievalBudget;
use App\Services\Detections\Evidence\SafeArticleFetcher;
use App\Services\Detections\EvidenceVerificationService;
use App\Services\Detections\GoogleFactCheckFeedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StopDetectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        config()->set(['fact_check.enhanced_enabled' => true, 'fact_check.queue_enabled' => true,
            'services.openai.analysis_enabled' => false, 'services.live_verification.enabled' => false,
            'services.filipino_news_classifier.enabled' => false, 'playwright.related_posts_inline' => false]);
    }

    private function pending(string $status = 'queued', ?User $user = null): Detection
    {
        return Detection::create(['user_id' => ($user ?? User::factory()->create())->id,
            'source_kind' => 'text', 'media_type' => 'text', 'caption_text' => 'The bridge opened in 2010.',
            'processing_status' => $status, 'fake_score' => 0, 'verdict' => 'review']);
    }

    public static function activeStatuses(): array
    {
        return [['queued'], ['processing']];
    }

    #[DataProvider('activeStatuses')]
    public function test_confirmed_stop_targets_only_the_owned_check_and_is_idempotent(string $status): void
    {
        $detection = $this->pending($status);
        $other = $this->pending('queued', $detection->user);
        $this->actingAs($detection->user)->postJson(route('detections.stop', $detection), ['confirmed' => true])
            ->assertOk()->assertJsonPath('cancelled', true)->assertJsonPath('processing_status', 'failed');
        $this->assertSame('queued', $other->fresh()->processing_status);
        $this->assertSame('ANALYSIS_UNAVAILABLE', $detection->fresh()->fact_check_result['verdict']);
        $this->getJson(route('detections.progress', $detection))->assertJsonPath('cancelled', true)
            ->assertJsonPath('stage', 'Fact check stopped');
        $snapshot = $detection->fresh()->getRawOriginal();
        $this->postJson(route('detections.stop', $detection), ['confirmed' => true])->assertOk();
        $this->assertSame($snapshot, $detection->fresh()->getRawOriginal());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_missing_confirmation_and_other_users_cannot_stop_a_check(): void
    {
        $detection = $this->pending();
        $before = $detection->fresh()->getRawOriginal();
        $this->actingAs($detection->user)->postJson(route('detections.stop', $detection))->assertUnprocessable();
        $this->postJson(route('detections.stop', $detection), ['confirmed' => false])->assertUnprocessable();
        $this->actingAs(User::factory()->create())->postJson(route('detections.stop', $detection), ['confirmed' => true])->assertNotFound();
        $this->assertSame($before, $detection->fresh()->getRawOriginal());
    }

    public function test_a_queued_job_does_not_start_after_the_user_stops_it(): void
    {
        $detection = $this->pending();
        app(ActiveDetectionManager::class)->stopForUser($detection->id, $detection->user_id);
        $pipeline = $this->mock(DetectionPipeline::class);
        $pipeline->shouldNotReceive('run');
        (new AnalyzeDetectionEvidence($detection->id, []))->handle($pipeline);
        Http::assertNothingSent();
    }

    public static function jobKinds(): array
    {
        return [['initial'], ['retry']];
    }

    #[DataProvider('jobKinds')]
    public function test_a_stop_and_new_retry_between_job_read_and_claim_cannot_start_the_old_work(string $kind): void
    {
        $detection = $this->pending();
        if ($kind === 'retry') {
            $detection->update(['fact_check_result' => ['verdict' => 'UNVERIFIED', 'summary' => 'Saved report.'],
                'signals' => ['analysis_retry' => ['id' => 'old-run']]]);
        }
        $changed = false;
        Event::listen('eloquent.retrieved: '.Detection::class, function ($row) use ($detection, &$changed): void {
            if ($row->id !== $detection->id || $changed) {
                return;
            }
            $changed = true;
            // The old job has a snapshot, while cancellation and a new retry
            // have already changed the row before its atomic claim executes.
            DB::table('detections')->where('id', $row->id)->update([
                'fact_check_result' => json_encode(['verdict' => 'ANALYSIS_UNAVAILABLE', 'summary' => 'Stopped original report.']),
                'signals' => json_encode(['analysis_retry' => ['id' => 'new-run', 'status' => 'queued']]),
            ]);
        });
        $pipeline = $this->mock(DetectionPipeline::class);
        $pipeline->shouldNotReceive('run');
        (new AnalyzeDetectionEvidence($detection->id, [], $kind === 'retry' ? 'old-run' : null))->handle($pipeline);
        $this->assertTrue($changed);
        $this->assertSame('queued', $detection->fresh()->processing_status);
        $this->assertSame('new-run', $detection->fresh()->signals['analysis_retry']['id']);
        Http::assertNothingSent();
    }

    public function test_stopping_a_retry_preserves_the_saved_assessment_and_original_usage(): void
    {
        $detection = $this->pending('processing');
        $result = ['schema_version' => 4, 'processing_status' => 'completed', 'primary_task' => 'claim',
            'verdict' => 'UNVERIFIED', 'summary' => 'Previously saved evidence.', 'sources' => []];
        $detection->update(['fact_check_result' => $result, 'analysis_summary' => 'Previously saved evidence.',
            'signals' => ['openai_usage' => ['total_tokens' => 700], 'analysis_retry' => ['id' => 'old-run', 'status' => 'processing', 'previous_status' => 'completed']]]);
        $this->actingAs($detection->user)->postJson(route('detections.stop', $detection), ['confirmed' => true])
            ->assertOk()->assertJsonPath('cancelled', true)->assertJsonPath('processing_status', 'completed');
        $this->assertSame($result, $detection->fresh()->fact_check_result);
        $this->assertSame(700, $detection->fresh()->signals['openai_usage']['total_tokens']);
        $this->assertSame('cancelled', $detection->fresh()->signals['analysis_retry']['status']);
        $snapshot = $detection->fresh()->getRawOriginal();
        (new AnalyzeDetectionEvidence($detection->id, [], 'old-run'))->failed(new \RuntimeException);
        $this->assertSame($snapshot, $detection->fresh()->getRawOriginal());
    }

    public function test_a_completed_check_cannot_be_replaced_by_a_late_stop_request(): void
    {
        $detection = $this->pending('completed');
        $detection->update(['fact_check_result' => ['verdict' => 'UNVERIFIED', 'summary' => 'Completed finding.']]);
        $snapshot = $detection->fresh()->getRawOriginal();
        $this->actingAs($detection->user)->postJson(route('detections.stop', $detection), ['confirmed' => true])
            ->assertOk()->assertJsonPath('cancelled', false)->assertJsonPath('processing_status', 'completed');
        $this->assertSame($snapshot, $detection->fresh()->getRawOriginal());
    }

    public function test_reopening_a_stopped_report_cannot_upgrade_it_from_a_saved_catalog_answer(): void
    {
        $detection = $this->pending();
        $detection->update(['caption_text' => 'The world will end on Friday, November 13, 2026, according to a scientist mathematical prediction.']);
        $this->actingAs($detection->user)->postJson(route('detections.stop', $detection), ['confirmed' => true])->assertOk();
        $snapshot = $detection->fresh()->getRawOriginal();
        $this->get(route('detections.result', $detection))->assertOk()
            ->assertSee('You stopped this fact check before it finished.');
        $this->getJson(route('detections.result', $detection))->assertJsonPath('processing_status', 'failed');
        $this->assertSame($snapshot, $detection->fresh()->getRawOriginal());
        Http::assertNothingSent();
    }

    public static function checkpoints(): array
    {
        return [['model'], ['retrieval']];
    }

    #[DataProvider('checkpoints')]
    public function test_confirmed_stop_blocks_subsequent_paid_and_retrieval_work(string $checkpoint): void
    {
        $detection = $this->pending('processing');
        $budget = app(ReportApiBudget::class);
        $budget->trackDetection($detection);
        $budget->reset(); // Nested evidence stages must retain the cancellation scope.
        app(ActiveDetectionManager::class)->stopForUser($detection->id, $detection->user_id);
        try {
            $checkpoint === 'model' ? $budget->beforeRequest() : (new RetrievalBudget)->take('pages');
            $this->fail('Stopped work must not issue another request.');
        } catch (AnalysisStopped) {
            $this->assertSame(0, $budget->snapshot()['requests']);
        }
        Http::assertNothingSent();
    }

    public static function stoppedRuns(): array
    {
        return [['stop'], ['new_retry']];
    }

    public function test_stop_during_a_detector_prevents_the_supporting_detector_and_research_from_starting(): void
    {
        Storage::fake('public');
        config()->set(['services.ai_origin.enabled' => true, 'services.deepfake_cnn.enabled' => true]);
        $detection = $this->pending('processing');
        $detection->update(['media_type' => 'image']);
        $this->mock(AiOriginClassifier::class)->shouldReceive('classify')->once()->andReturnUsing(function () use ($detection) {
            app(ActiveDetectionManager::class)->stopForUser($detection->id, $detection->user_id);

            return ['status' => 'completed', 'media_type' => 'image', 'label' => 'fake', 'fake_probability' => .97];
        });
        $this->mock(DeepfakeCnnClassifier::class)->shouldNotReceive('classify');
        $this->mock(EvidenceVerificationService::class)->shouldNotReceive('apply');
        $result = app(DetectionPipeline::class)->run($detection->user, ['analysis_task' => 'media'],
            new UploadedFile(base_path('tests/Fixtures/evidence/media/notice.png'), 'notice.png', null, null, true), $detection);
        $this->assertSame('failed', $result->processing_status);
        $this->assertSame('user_stopped', $result->signals['analysis_cancellation']['reason']);
        Http::assertNothingSent();
    }

    public function test_stop_during_an_in_flight_model_response_prevents_its_paid_retry(): void
    {
        $detection = $this->pending('processing');
        app(ReportApiBudget::class)->trackDetection($detection);
        config()->set(['services.openai.key' => 'offline-fixture', 'fact_check.openai_retry.max_retries' => 1]);
        Http::fake(['api.openai.com/*' => function () use ($detection) {
            app(ActiveDetectionManager::class)->stopForUser($detection->id, $detection->user_id);

            return Http::response([], 503, ['Retry-After' => '0']);
        }]);
        try {
            (new ResponsesTransport)->post(['model' => 'fixture'], 5);
            $this->fail('A stopped report cannot start the retry.');
        } catch (AnalysisStopped) {
            Http::assertSentCount(1);
        }
    }

    public function test_stop_during_an_in_flight_source_response_prevents_the_http_retry(): void
    {
        $detection = $this->pending('processing');
        app(ReportApiBudget::class)->trackDetection($detection);
        config()->set('fact_check.retrieval.respect_robots', false);
        $fetcher = $this->partialMock(SafeArticleFetcher::class);
        $fetcher->shouldReceive('publicAddress')->andReturn('93.184.216.34');
        Http::fake(['example.com/*' => function () use ($detection) {
            app(ActiveDetectionManager::class)->stopForUser($detection->id, $detection->user_id);

            return Http::response('', 503, ['Content-Type' => 'text/html']);
        }]);
        $fetcher->fetch('https://example.com/report', new RetrievalBudget);
        Http::assertSentCount(1);
    }

    #[DataProvider('stoppedRuns')]
    public function test_a_late_worker_cannot_rewrite_cancellation_or_a_newer_run(string $case): void
    {
        $detection = $this->pending('processing');
        $snapshot = null;
        $this->mock(EvidenceVerificationService::class)->shouldReceive('apply')->once()
            ->andReturnUsing(function ($analysis, $context, $file, $progress) use ($detection, $case, &$snapshot) {
                $progress('First stage');
                app(ActiveDetectionManager::class)->stopForUser($detection->id, $detection->user_id);
                if ($case === 'new_retry') {
                    $current = $detection->fresh();
                    $signals = $current->signals;
                    unset($signals['analysis_cancellation']);
                    $signals['analysis_retry'] = ['id' => 'new-run', 'status' => 'processing'];
                    $current->update(['processing_status' => 'processing', 'signals' => $signals]);
                }
                $snapshot = $detection->fresh()->getRawOriginal();
                $progress('Late stage must not be saved');

                return $analysis;
            });
        $result = app(DetectionPipeline::class)->run($detection->user,
            ['analysis_task' => 'claim', 'caption_text' => $detection->caption_text], null, $detection);
        $this->assertSame($snapshot, $result->fresh()->getRawOriginal());
        app(ReportApiBudget::class)->beforeRequest(); // The worker scope was released.
        Http::assertNothingSent();
    }

    public function test_both_loading_locations_offer_stop_without_stopping_on_navigation_or_hide(): void
    {
        $detection = $this->pending();
        $this->mock(GoogleFactCheckFeedService::class)->shouldReceive('latest')->andReturn(['items' => []]);
        $snapshot = $detection->fresh()->getRawOriginal();
        $composerHtml = $this->actingAs($detection->user)->get(route('detections.create'))->assertOk()
            ->assertSee('Stop fact check')->assertSee('@click="requestStopAnalysis()"', false)
            ->assertSee('Hide progress; analysis continues')->getContent();
        $backgroundHtml = $this->get(route('history'))->assertOk()->assertSee('truthguard-analysis-stop')
            ->assertSee('@click="requestStopAnalysis()"', false)->getContent();
        if (getenv('TRUTHGUARD_STOP_BROWSER_FIXTURE')) {
            file_put_contents(storage_path('logs/stop-controls-fixtures.json'), json_encode([
                'composer' => $composerHtml, 'background' => $backgroundHtml,
            ], JSON_THROW_ON_ERROR));
        }
        $this->assertSame($snapshot, $detection->fresh()->getRawOriginal());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }
}
