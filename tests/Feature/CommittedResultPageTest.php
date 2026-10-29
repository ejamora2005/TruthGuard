<?php

namespace Tests\Feature;

use App\Models\Detection;
use App\Models\User;
use App\Services\Detections\GoogleFactCheckFeedService;
use App\Services\Detections\LiveVerificationEvidenceService;
use App\Services\Detections\MediaAnalysisReport;
use App\Services\Detections\OpenAiDetectionReportService;
use App\Services\Detections\ReportAnswer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CommittedResultPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_source_header_groups_match_details_counts_and_list_only_their_recorded_sources(): void
    {
        Http::preventStrayRequests();
        $this->mock(GoogleFactCheckFeedService::class)->shouldReceive('latest')->twice()->andReturn(['items' => []]);
        $user = User::factory()->create();
        $sources = array_map(fn ($type, $i) => ['source_type' => $type, 'name' => 'Fixture publisher '.$i,
            'title' => 'Fixture record '.$i, 'url' => 'https://example.com/record/'.$i],
            ['fact_check', 'official', 'news', 'social_context'], range(1, 4));
        foreach ([$sources, []] as $records) {
            $detection = Detection::create(['user_id' => $user->id, 'source_kind' => 'upload', 'media_type' => 'image',
                'caption_text' => 'A fictional claim.', 'processing_status' => 'completed', 'verdict' => 'review', 'fake_score' => 0,
                'analysis_summary' => 'Saved unresolved assessment.', 'verification_sources' => $records,
                'fact_check_result' => ['schema_version' => 4, 'primary_task' => 'claim', 'verdict' => 'UNVERIFIED']]);
            $before = $detection->fresh()->getRawOriginal();
            $html = $this->actingAs($user)->get(route('detections.result', $detection))->assertOk()->getContent();
            $document = new \DOMDocument;
            @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
            $xpath = new \DOMXPath($document);
            $expected = $records ? 2 : 0;
            foreach (['trusted' => [1, 2], 'other' => [3, 4]] as $key => $indices) {
                $group = $xpath->query('//*[@id="source-verification"]//*[@data-source-group="'.$key.'"]')->item(0);
                $button = $xpath->query('.//button', $group)->item(0);
                $this->assertSame((string) $expected, trim($xpath->query('.//strong', $button)->item(0)->textContent));
                $this->assertSame('false', $button->getAttribute('aria-expanded'));
                $panel = $xpath->query('//*[@id="'.$button->getAttribute('aria-controls').'"]')->item(0);
                $this->assertTrue($panel->hasAttribute('x-cloak'));
                $this->assertSame('region', $panel->getAttribute('role'));
                $this->assertSame($expected, $xpath->query('.//a', $panel)->length);
                if ($records) {
                    foreach ($indices as $index) {
                        $this->assertStringContainsString('Fixture publisher '.$index, $panel->textContent);
                        $this->assertSame(1, $xpath->query('.//a[@href="https://example.com/record/'.$index.'"]', $panel)->length);
                    }
                    $opposite = $key === 'trusted' ? 3 : 1;
                    $this->assertStringNotContainsString('Fixture publisher '.$opposite, $panel->textContent);
                } else {
                    $this->assertStringContainsString('recorded.', $panel->textContent);
                }
            }
            $this->assertSame((string) $expected, trim($xpath->query('//*[@id="assessment-details"]//div[dt="Trusted sources"]/dd')->item(0)->textContent));
            $this->assertSame($before, $detection->fresh()->getRawOriginal());
            if ($records && getenv('TRUTHGUARD_SOURCE_GROUPS_BROWSER_FIXTURE')) {
                file_put_contents(storage_path('tmp-source-groups-browser.html'), $html);
            }
        }
        Http::assertNothingSent();
    }

    public function test_claim_details_do_not_treat_a_saved_article_body_as_a_matched_claim(): void
    {
        Http::preventStrayRequests();
        $this->mock(GoogleFactCheckFeedService::class)->shouldReceive('latest')->twice()->andReturn(['items' => []]);
        $user = User::factory()->create();
        $source = ['name' => 'Example publisher', 'source_type' => 'fact_check',
            'url' => 'https://example.com/fact-check/library', 'title' => 'A saved source article',
            'text' => str_repeat('Article body and unrelated recommended stories. ', 150)];
        foreach ([[], ['reviewed_claim' => 'The fictional library opened in 2024.']] as $explicitClaim) {
            $detection = Detection::create(['user_id' => $user->id, 'source_kind' => 'upload', 'media_type' => 'image',
                'caption_text' => 'Is the claim in this post true?', 'processing_status' => 'completed',
                'verdict' => 'review', 'fake_score' => 0, 'analysis_summary' => 'Saved unresolved assessment.',
                'verification_sources' => [array_merge($source, $explicitClaim)],
                'fact_check_result' => ['schema_version' => 4, 'primary_task' => 'claim', 'verdict' => 'UNVERIFIED']]);
            $before = $detection->fresh()->getRawOriginal();
            $html = $this->actingAs($user)->get(route('detections.result', $detection))->assertOk()
                ->assertSee('href="https://example.com/fact-check/library"', false)->getContent();
            $document = new \DOMDocument;
            @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
            $claimCard = (new \DOMXPath($document))->query('//article[contains(@class, "tg-report-claim")]')->item(0);
            $this->assertNotNull($claimCard);
            $this->assertStringContainsString('Is the claim in this post true?', $claimCard->textContent);
            $this->assertStringNotContainsString('Article body and unrelated', $claimCard->textContent);
            $this->assertStringNotContainsString('Matched fact-check claim', $claimCard->textContent);
            if ($explicitClaim) {
                $this->assertStringContainsString('Claim checked by source', $claimCard->textContent);
                $this->assertStringContainsString($explicitClaim['reviewed_claim'], $claimCard->textContent);
            }
            $this->assertSame($before, $detection->fresh()->getRawOriginal());
        }
        Http::assertNothingSent();
    }

    public function test_long_submitted_text_has_a_short_preview_and_closed_full_text_control(): void
    {
        Http::preventStrayRequests();
        $this->mock(GoogleFactCheckFeedService::class)->shouldReceive('latest')->once()->andReturn(['items' => []]);
        $user = User::factory()->create();
        $text = str_repeat('A long recorded statement with material qualifications. ', 30).'Final qualifier preserved.';
        $detection = Detection::create(['user_id' => $user->id, 'source_kind' => 'upload', 'media_type' => 'text',
            'caption_text' => $text, 'processing_status' => 'completed', 'verdict' => 'review', 'fake_score' => 0,
            'analysis_summary' => 'Saved unresolved assessment.',
            'fact_check_result' => ['schema_version' => 4, 'primary_task' => 'claim', 'verdict' => 'UNVERIFIED']]);
        $before = $detection->fresh()->getRawOriginal();
        $html = $this->actingAs($user)->get(route('detections.result', $detection))->assertOk()->getContent();
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($document);
        $details = $xpath->query('//article[contains(@class, "tg-report-claim")]//details[contains(@class, "tg-claim-full-text")]')->item(0);
        $this->assertNotNull($details);
        $this->assertFalse($details->hasAttribute('open'));
        $this->assertStringContainsString('Final qualifier preserved.', $details->textContent);
        $this->assertLessThanOrEqual(503, mb_strlen(trim($xpath->query('preceding-sibling::p[1]', $details)->item(0)->textContent)));
        $this->assertSame($before, $detection->fresh()->getRawOriginal());
        Http::assertNothingSent();
    }

    public function test_result_badge_and_details_use_the_recorded_model_score_instead_of_legacy_risk_confidence(): void
    {
        Http::preventStrayRequests();
        config()->set('services.media_calibration.enabled', false);
        $this->mock(GoogleFactCheckFeedService::class)->shouldReceive('latest')->times(2)->andReturn(['items' => []]);
        $user = User::factory()->create();
        foreach ([[.97, false, 'Model score: 97%'], [.55, true, 'AI-generation score: 55%']] as [$value, $uncertain, $expected]) {
            $result = ReportAnswer::complete(MediaAnalysisReport::result([
                'status' => 'completed', 'media_type' => 'image', 'label' => 'fake', 'fake_probability' => $value,
                'label_mapping' => ['real', 'fake'], 'origin_policy' => ['inconclusive' => $uncertain],
                'output_contract' => ['activation' => 'sigmoid', 'positive_class' => 'fake', 'binary_exclusive' => true],
            ], ['status' => 'not_requested']));
            $detection = Detection::create(['user_id' => $user->id, 'source_kind' => 'upload', 'media_type' => 'image',
                'processing_status' => 'completed', 'verdict' => 'review', 'fake_score' => 11,
                'analysis_summary' => $result['summary'], 'fact_check_result' => $result]);
            $before = $detection->fresh()->getRawOriginal();
            $this->actingAs($user)->get(route('detections.result', $detection))->assertOk()
                ->assertSee($expected)->assertDontSee('AI confidence')->assertDontSee('Confidence: 40%');
            $this->assertSame($before, $detection->fresh()->getRawOriginal());
        }
        Http::assertNothingSent();
    }

    public function test_media_verdict_uses_saved_origin_label_without_hiding_disagreement_or_inventing_calibration(): void
    {
        Http::preventStrayRequests();
        config()->set('services.media_calibration.enabled', false);
        $this->mock(GoogleFactCheckFeedService::class)->shouldReceive('latest')->twice()->andReturn(['items' => []]);
        $user = User::factory()->create();
        $media = ['status' => 'completed', 'media_type' => 'image', 'label' => 'fake', 'fake_probability' => .9920251369476318,
            'model_name' => 'Primary AI-origin detector', 'label_mapping' => ['real', 'fake'],
            'output_contract' => ['activation' => 'sigmoid', 'positive_class' => 'fake', 'binary_exclusive' => true],
            'detector_disagreement' => true, 'supporting_assessments' => ['image_cnn' => [
                'status' => 'completed', 'label' => 'real', 'fake_probability' => .009008923545479774, 'model_name' => 'Secondary MobileNet classifier',
            ]]];
        $result = ReportAnswer::complete(MediaAnalysisReport::result($media, ['status' => 'not_requested']));
        $detection = Detection::create(['user_id' => $user->id, 'source_kind' => 'upload', 'media_type' => 'image',
            'processing_status' => 'completed', 'verdict' => 'fake', 'fake_score' => 99,
            'analysis_summary' => $result['summary'], 'fact_check_result' => $result]);
        $snapshot = $detection->fresh()->getRawOriginal();
        foreach (range(1, 2) as $refresh) {
            $response = $this->actingAs($user)->get(route('detections.result', $detection))->assertOk()
                ->assertSee('Model score: 99%')->assertSee('Uncalibrated model output')
                ->assertSee('The classifiers disagree.')->assertSee('Primary AI-origin detector')->assertSee('Additional image screening');
            $document = new \DOMDocument;
            @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
            $xpath = new \DOMXPath($document);
            $label = $xpath->query('//button[contains(@class, "tg-report-main-verdict")]/span')->item(0);
            $this->assertSame('Likely AI-Generated', trim($label->textContent));
        }
        $this->assertSame($snapshot, $detection->fresh()->getRawOriginal());
        Http::assertNothingSent();
    }

    public function test_interpretation_shows_only_a_short_summary_without_a_full_explanation_control(): void
    {
        Http::preventStrayRequests();
        $this->mock(GoogleFactCheckFeedService::class)->shouldReceive('latest')->once()->andReturn(['items' => []]);
        $user = User::factory()->create();
        $summary = 'The text is too fragmentary to identify a specific factual assertion. The related material provides background rather than a factual verdict.';
        $original = 'I searched for records about the submitted topic. '.$summary.' A related source dated 2026-10-16 states: “A long background quotation.”';
        $nextStep = 'Confirm the unreadable words before relying on this claim.';
        $detection = Detection::create(['user_id' => $user->id, 'source_kind' => 'upload', 'media_type' => 'image',
            'processing_status' => 'completed', 'verdict' => 'review', 'fake_score' => 0,
            'analysis_summary' => $original, 'explanation_summary' => $original,
            'recommendation' => 'The text is too fragmentary to identify a specific factual assertion. '.$nextStep,
            'verification_sources' => [['name' => 'Fixture publisher', 'source_type' => 'fact_check',
                'title' => 'Recorded reference', 'url' => 'https://example.com/recorded-reference']],
            'fact_check_result' => ['schema_version' => 4, 'primary_task' => 'claim', 'verdict' => 'MISSING_CONTEXT', 'summary' => $original]]);
        $before = $detection->fresh()->getRawOriginal();
        $html = $this->actingAs($user)->get(route('detections.result', $detection))->assertOk()
            ->assertSee('Result summary')->assertDontSee('View full explanation')->getContent();
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($document);
        $this->assertSame($summary, trim($xpath->query('//p[contains(@class, "tg-interpretation-copy")]')->item(0)->textContent));
        $this->assertSame(0, $xpath->query('//details[contains(@class, "tg-interpretation-summary")]')->length);
        $this->assertSame($nextStep, trim($xpath->query('//p[contains(@class, "tg-interpretation-recommendation")]')->item(0)->textContent));
        $this->assertSame(1, $xpath->query('//aside[contains(@class, "tg-interpretation-action")]//a[@href="https://example.com/recorded-reference"]')->length);
        $this->assertSame(0, $xpath->query('//footer[contains(@class, "tg-interpretation-footer")]')->length);
        $this->assertSame(0, $xpath->query('//section[contains(@class, "tg-interpretation-reasoning")]//details')->length);
        $this->assertSame($before, $detection->fresh()->getRawOriginal());
        if (getenv('TRUTHGUARD_INTERPRETATION_BROWSER_FIXTURE')) {
            file_put_contents(storage_path('tmp-interpretation-browser.html'), $html);
        }
        Http::assertNothingSent();
    }

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
            ->assertDontSee('Evidence insufficient')->assertDontSee('No calibrated confidence recorded')->assertSee($saved['summary'])
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
