<?php

namespace Tests\Feature;

use App\Models\Detection;
use App\Models\User;
use App\Services\Detections\DeepfakeCnnClassifier;
use App\Services\Detections\Evidence\ClaimPlanner;
use App\Services\Detections\Evidence\SubmissionExtractor;
use App\Services\Detections\GoogleFactCheckFeedService;
use App\Services\Detections\MediaAnalysisReport;
use App\Services\Detections\ReportAnswer;
use App\Services\Detections\SubmissionTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VideoScreeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_video_upload_answers_origin_question_without_news_search(): void
    {
        Storage::fake('public');
        Http::preventStrayRequests();
        config()->set(['fact_check.enhanced_enabled' => true, 'fact_check.queue_enabled' => false,
            'services.openai.analysis_enabled' => false, 'services.filipino_news_classifier.enabled' => false,
            'services.live_verification.enabled' => false, 'playwright.related_posts_inline' => false]);
        $this->mock(DeepfakeCnnClassifier::class)->shouldReceive('classify')->once()
            ->andReturn(['status' => 'completed', 'media_type' => 'video', 'label' => 'real',
                'frames_used' => 12, 'fake_probability' => .37, 'threshold' => .68]);
        $this->mock(SubmissionExtractor::class)->shouldReceive('extract')
            ->andReturn(['analysis_text' => '', 'visible_text' => '', 'status' => 'completed']);
        $this->mock(ClaimPlanner::class)->shouldNotReceive('plan');
        $this->mock(GoogleFactCheckFeedService::class)->shouldReceive('latest')->andReturn([]);
        $this->actingAs(User::factory()->create())->post(route('detections.store'), [
            'media_file' => UploadedFile::fake()->create('clip.mp4', 10, 'video/mp4'),
            'caption_text' => 'please please please verify this video if it is ai generated or not',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $detection = Detection::sole();
        $this->assertSame('media', $detection->fact_check_result['primary_task']);
        $this->assertSame('INCONCLUSIVE', $detection->fact_check_result['verdict']);
        $this->get(route('detections.result', $detection))->assertOk()->assertSee('Inconclusive')
            ->assertDontSee('Likely Not AI-Generated')->assertSee('Retry media analysis');
        Http::assertNothingSent();
    }

    public function test_repeated_polite_video_request_routes_to_origin_screening(): void
    {
        $context = ['media_type' => 'video', 'analysis_task' => 'auto',
            'caption_text' => 'please please please verify this video if it is ai generated or not'];
        $this->assertSame('media', SubmissionTask::primary($context));
        $this->assertSame(['media'], SubmissionTask::tasks($context));
        $this->assertSame('', SubmissionTask::claimText($context['caption_text']));
    }

    public function test_low_face_detector_score_cannot_establish_non_ai_video_origin(): void
    {
        $raw = ['status' => 'completed', 'media_type' => 'video', 'frames_used' => 16,
            'label' => 'real', 'fake_probability' => 0.1];
        $result = ReportAnswer::complete(MediaAnalysisReport::result($raw, []));
        $this->assertSame('INCONCLUSIVE', $result['verdict']);
        $this->assertNull($result['score']['value']);
        $this->assertSame($raw, array_intersect_key($result['media_assessment'], $raw));
        $this->assertStringContainsString('does not establish', $result['summary']);
    }

    public function test_old_negative_video_report_is_corrected_without_overwriting_its_raw_output(): void
    {
        $result = ReportAnswer::forPresentation(['schema_version' => 4, 'primary_task' => 'media',
            'verdict' => 'LIKELY_NOT_AI_GENERATED', 'summary' => 'This video is likely not AI-generated.',
            'generated_wording' => ['explanation' => 'This video is real.'],
            'media_assessment' => ['status' => 'completed', 'media_type' => 'video', 'frames_used' => 16, 'label' => 'real']]);
        $this->assertSame('INCONCLUSIVE', $result['verdict']);
        $this->assertStringNotContainsString('This video is real.', json_encode($result));
    }

    public function test_no_face_frames_has_clear_scope_explanation(): void
    {
        $result = ReportAnswer::complete(MediaAnalysisReport::result(['status' => 'completed',
            'media_type' => 'video', 'frames_used' => 0, 'label' => null,
            'video_scope' => ['face_crop_required' => true, 'face_frames' => 0]], []));
        $this->assertSame('INCONCLUSIVE', $result['verdict']);
        $this->assertStringContainsString('No usable human faces', $result['summary']);
    }

    public function test_old_wrong_task_video_report_does_not_display_unrelated_claim_references(): void
    {
        $result = ReportAnswer::forPresentation(['schema_version' => 4, 'primary_task' => 'claim',
            'verdict' => 'UNVERIFIED', 'summary' => 'No matching news source.',
            'request' => ['question' => 'please please please verify this video if it is ai generated or not'],
            'sources' => [['id' => 's1', 'url' => 'https://linktr.ee/example', 'title' => 'Unrelated link']]]);
        $this->assertSame('media', $result['primary_task']);
        $this->assertSame('ANALYSIS_UNAVAILABLE', $result['verdict']);
        $this->assertSame([], $result['sources']);
        $this->assertStringContainsString('Retry media analysis', $result['summary']);
    }

    public function test_positive_video_finding_and_image_findings_are_preserved(): void
    {
        foreach (['video' => 'fake', 'image' => 'real'] as $kind => $label) {
            $result = ReportAnswer::complete(MediaAnalysisReport::result(['status' => 'completed',
                'media_type' => $kind, 'frames_used' => 16, 'label' => $label,
                'fake_probability' => $label === 'fake' ? .99 : .01], []));
            $this->assertSame($kind === 'video' ? 'LIKELY_AI_GENERATED' : 'LIKELY_NOT_AI_GENERATED', $result['verdict']);
        }
    }
}
