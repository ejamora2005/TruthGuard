<?php

namespace Tests\Feature;

use App\Models\Detection;
use App\Models\User;
use App\Services\Detections\GoogleFactCheckFeedService;
use App\Services\Detections\LiveVerificationEvidenceService;
use App\Services\Detections\OpenAiDetectionReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CommittedResultPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_openai_status_reads_recorded_token_usage_and_failures_without_claiming_unrecorded_success(): void
    {
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $this->mock(GoogleFactCheckFeedService::class)->shouldReceive('latest')->times(3)->with(6)->andReturn(['items' => []]);
        foreach ([
            [['openai_usage' => ['input_tokens' => 12, 'output_tokens' => 8, 'total_tokens' => 20]], 'Used for this report'],
            [['submission_extraction' => [['status' => 'unavailable', 'failure_reason' => 'quota_exhausted']]], 'API credits exhausted'],
            [[], 'Not recorded'],
        ] as [$signals, $status]) {
            $detection = Detection::create([
                'user_id' => $user->id, 'source_kind' => 'upload', 'media_type' => 'image',
                'processing_status' => 'completed', 'verdict' => 'review', 'fake_score' => 0,
                'analysis_summary' => 'Saved finding.', 'signals' => $signals,
                'fact_check_result' => ['primary_task' => 'media', 'verdict' => 'INCONCLUSIVE'],
            ]);
            $before = $detection->fresh()->getRawOriginal();
            $this->actingAs($user)->get(route('detections.result', $detection))->assertOk()->assertSee('Status: '.$status);
            $this->assertSame($before, $detection->fresh()->getRawOriginal());
        }
        Http::assertNothingSent();
    }

    public function test_report_reference_uses_each_owners_sequence_including_archived_and_pending_reports(): void
    {
        Http::preventStrayRequests();
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $create = fn (User $user, array $attributes = []) => Detection::create(array_merge([
            'user_id' => $user->id, 'source_kind' => 'upload', 'media_type' => 'image',
            'processing_status' => 'completed', 'verdict' => 'review', 'fake_score' => 0,
        ], $attributes));
        $otherFirst = $create($other);
        $create($other);
        $first = $create($owner, ['archived_at' => now()]);
        $create($other);
        $second = $create($owner);
        $queued = $create($owner, ['processing_status' => 'queued']);
        $this->mock(GoogleFactCheckFeedService::class)->shouldReceive('latest')->with(6)
            ->times(3)->andReturn(['items' => []]);

        foreach ([[$owner, $first, 1], [$owner, $second, 2], [$owner, $queued, 3], [$other, $otherFirst, 1]] as [$user, $report, $number]) {
            $this->actingAs($user)->get(route('detections.result', $report))->assertOk()
                ->assertViewHas('userReportNumber', $number)
                ->assertSee('<strong>TG-'.str_pad((string) $number, 2, '0', STR_PAD_LEFT).'</strong>', false);
        }

        $this->actingAs($owner)->get(route('detections.result', $otherFirst))->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_committed_result_renders_saved_explanation_verdict_and_original_source_fields_without_reanalysis(): void
    {
        Http::preventStrayRequests();
        config()->set('services.live_verification.refresh_on_view', true);
        $user = User::factory()->create();
        $saved = ['schema_version' => 4, 'primary_task' => 'claim', 'verdict' => 'UNVERIFIED',
            'summary' => 'A separate structured assessment remains stored unchanged.'];
        $detection = Detection::create([
            'user_id' => $user->id, 'source_kind' => 'upload', 'media_type' => 'image',
            'media_path' => 'detections/committed-report.jpg', 'caption_text' => 'A submitted statement.',
            'processing_status' => 'completed', 'verdict' => 'fake', 'fake_score' => 82,
            'analysis_summary' => 'Saved assessment from the committed result flow.',
            'explanation_summary' => 'Saved explanation tied to the original source record.',
            'verification_summary' => 'One saved source was compared.', 'recommendation' => 'Read the cited source.',
            'signals' => ['textual' => [['label' => 'Saved source comparison', 'weight' => 82]]],
            'verification_sources' => [['name' => 'Saved publisher', 'title' => 'Original saved source title',
                'source_type' => 'fact_check', 'rating' => 'False', 'summary' => 'Original saved relevance explanation.',
                'url' => 'https://example.com/saved-source', 'published_at' => '2026-10-01']],
            'fact_check_result' => $saved, 'analyzed_at' => now(),
        ]);
        $before = $detection->fresh()->getRawOriginal();
        $this->mock(GoogleFactCheckFeedService::class)->shouldReceive('latest')->once()->with(6)->andReturn(['items' => []]);
        $this->mock(OpenAiDetectionReportService::class)->shouldNotReceive('extractClaimText')->shouldNotReceive('enhance');
        $this->mock(LiveVerificationEvidenceService::class)->shouldNotReceive('verify');

        $html = $this->actingAs($user)->get(route('detections.result', $detection))->assertOk()
            ->assertSee('Media analysis report')->assertSee('Your question')->assertSee('What the evidence shows')
            ->assertSee('Confidence not available')->assertSee('Saved assessment from the committed result flow.')
            ->assertSee('Saved explanation tied to the original source record.')->assertSee('Source result')->assertSee('False')
            ->assertSee('Source verification')->assertSee('Original saved source title')->assertSee('Original saved relevance explanation.')
            ->assertSee('href="https://example.com/saved-source"', false)->assertSee('Enlarge submitted image')
            ->assertDontSee('tg-result-report')->getContent();
        $this->assertStringContainsString('id="source-verification"', $html);
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($document);
        $details = $xpath->query('//*[@id="assessment-details"]')->item(0);
        $this->assertNotNull($details);
        $this->assertFalse($details->hasAttribute('open'));
        $this->assertSame(1, $xpath->query('.//dl[contains(@class, "tg-report-metrics")]', $details)->length);
        $this->assertSame(1, $xpath->query('.//article[contains(@class, "tg-report-claim")]', $details)->length);
        $this->assertSame(0, $xpath->query('//*[@id="source-verification"]/ancestor::details')->length);
        $this->assertSame(0, $xpath->query('//article[contains(@class, "tg-report-assessment")]/ancestor::details')->length);
        $this->assertSame(1, $xpath->query('//div[contains(@class, "tg-report-overview")]/following-sibling::*[1][contains(@class, "tg-report-assessment")]')->length);
        $this->assertSame($before, $detection->fresh()->getRawOriginal());
        $this->assertSame($saved, $detection->fresh()->fact_check_result);
        Http::assertNothingSent();
    }
}
