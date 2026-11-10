<?php

namespace Tests\Feature;

use App\Models\Detection;
use App\Models\User;
use App\Services\Detections\AssessmentScore;
use App\Services\Detections\EvidenceVerificationService;
use App\Services\Detections\FactCheckReportPresenter;
use App\Services\Detections\GoogleFactCheckFeedService;
use App\Services\Detections\MediaAnalysisReport;
use App\Services\Detections\ReportAnswer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TransparentFactCheckReportTest extends TestCase
{
    use RefreshDatabase;

    private function record(User $owner, bool $withSupport = false): Detection
    {
        $row = ['claim_id' => 'c1', 'stance' => 'contradicting', 'excerpt' => 'The original programme lists four vehicles, not six.',
            'explanation' => 'The programme contradicts the stated quantity of six vehicles.', 'superseded' => false];
        $claims = [['id' => 'c1', 'text' => 'Six rescue vehicles were provided.', 'kind' => 'factual', 'outcome' => 'refuted', 'relationships' => []],
            ['id' => 'c2', 'text' => 'Residents were advised to evacuate.', 'kind' => 'factual', 'outcome' => 'needs_context',
                'requires_context' => true, 'context_reason' => 'The notice does not identify who issued the evacuation advice.', 'relationships' => []]];
        $sources = [['id' => 's1', 'url' => 'https://example.gov.ph/original-programme', 'name' => 'Municipal archive',
            'title' => 'Original vehicle programme', 'type' => 'official_government', 'published_at' => '2024-05-01',
            'used_for' => $row['explanation'], 'relationships' => [$row]]];
        if ($withSupport) {
            $claims[] = ['id' => 'c3', 'text' => 'The library reopened in 2024.', 'kind' => 'factual', 'outcome' => 'supported', 'relationships' => []];
            $sources[] = ['id' => 's2', 'url' => 'https://example.com/library-record', 'name' => 'Local journal',
                'title' => 'Library opening record', 'type' => 'reputable_news', 'published_at' => null,
                'relationships' => [['claim_id' => 'c3', 'stance' => 'supporting', 'excerpt' => 'The library reopened in May 2024.',
                    'explanation' => 'The dated opening record supports the stated reopening year.']]];
        }

        return Detection::create(['user_id' => $owner->id, 'source_kind' => 'text', 'media_type' => 'text',
            'caption_text' => 'A synthetic multi-claim post.', 'processing_status' => 'completed', 'verdict' => 'review', 'fake_score' => 97,
            'fact_check_result' => ['schema_version' => 4, 'primary_task' => 'claim', 'verdict' => 'MISSING_CONTEXT',
                'summary' => 'A missing detail was incorrectly used as the old overall finding.', 'claims' => $claims,
                'sources' => $sources, 'score' => AssessmentScore::unavailable('No calibrated confidence was recorded.'),
                'diagnostics' => ['pipeline_version' => EvidenceVerificationService::VERSION]]])->fresh();
    }

    public function test_original_answers_and_layout_are_restored_while_download_and_risk_remain(): void
    {
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $record = $this->record($user);
        $before = $record->getRawOriginal();
        $report = FactCheckReportPresenter::build($record);
        $this->assertSame('Missing Context', $report['verdict_label']);
        $this->assertSame(['total' => 2, 'supported' => 0, 'contradicted' => 1, 'unresolved' => 1], $report['counts']);
        $this->assertSame('Context needed', $report['risk']['label']);
        $this->assertSame('Unavailable', $report['confidence']['value']);
        $this->assertSame('100%', $report['risk_percentage']['display']);
        $this->assertSame('Critical Risk', $report['risk_percentage']['caption']);
        $this->assertSame(['total' => 2, 'assessed' => 1, 'flagged' => 1, 'unresolved' => 1], $report['risk_percentage']['counts']);
        $this->mock(GoogleFactCheckFeedService::class)->shouldNotReceive('latest');
        $html = $this->actingAs($user)->get(route('detections.result', $record))->assertOk()
            ->assertSee('Missing Context')->assertSee(ReportAnswer::forPresentation($record->fact_check_result)['summary'])
            ->assertSee('Original vehicle programme')->assertDontSee('Risk assessment')->assertSee('Download report')->assertDontSee('Critical Risk')
            ->assertDontSee('data-risk-explanation')->assertDontSee('Assessed-statement risk index')
            ->assertDontSee('Extracted factual claims')->assertDontSee('Claim-by-claim assessment')
            ->assertDontSee('id="claim-assessments"', false)->assertDontSee('tg-transparent-indicators')->assertDontSee('Model confidence')
            ->assertDontSee('The saved evidence review identifies')
            ->assertDontSee('97%')->getContent();
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($dom);
        $this->assertSame(0, $xpath->query('//div[contains(@class,"tg-verdict-summary")]/*[@data-risk-assessment]')->length);
        $this->assertSame(0, $xpath->query('//*[@data-claim-outcome]')->length);
        $this->assertSame($before, $record->fresh()->getRawOriginal());
        Http::assertNothingSent();
    }

    public function test_mixed_evidence_counts_and_missing_dates_are_preserved(): void
    {
        $report = FactCheckReportPresenter::build($this->record(User::factory()->create(), true));
        $this->assertSame('Missing Context', $report['verdict_label']);
        $this->assertSame(['total' => 3, 'supported' => 1, 'contradicted' => 1, 'unresolved' => 1], $report['counts']);
        $this->assertNull($report['sources'][1]['published_at']);
        $this->assertSame('News reporting', $report['sources'][1]['credibility']);
        $this->assertSame('Official government record', $report['sources'][0]['credibility']);
    }

    public function test_result_page_hides_unavailable_risk_but_keeps_valid_zero_and_nonzero_scores(): void
    {
        Http::preventStrayRequests();
        $this->mock(GoogleFactCheckFeedService::class)->shouldNotReceive('latest');
        $owner = User::factory()->create();
        $record = $this->record($owner);
        $saved = $record->fact_check_result;
        $record->update(['fact_check_result' => ['schema_version' => 4, 'primary_task' => 'claim',
            'verdict' => 'UNVERIFIED', 'summary' => 'No matching source was available.', 'sources' => []]]);
        $this->actingAs($owner)->get(route('detections.result', $record))->assertOk()
            ->assertDontSee('data-risk-assessment', false)->assertDontSee('Risk undetermined')->assertDontSee('Risk assessment');

        foreach ([['FALSE', 'refuted', 'contradicting', '100%'], ['TRUE', 'supported', 'supporting', '0%']] as [$verdict, $outcome, $stance, $percentage]) {
            $result = $saved;
            $result['verdict'] = $verdict;
            $result['claims'] = [$result['claims'][0]];
            $result['claims'][0]['outcome'] = $outcome;
            $result['sources'][0]['relationships'][0]['stance'] = $stance;
            $record->update(['fact_check_result' => $result]);
            $this->get(route('detections.result', $record))->assertOk()
                ->assertSee('data-risk-assessment', false)->assertSee('Risk assessment')->assertSee($percentage);
        }
        Http::assertNothingSent();
    }

    public function test_missing_unsafe_or_superseded_evidence_never_resolves_a_claim(): void
    {
        $record = $this->record(User::factory()->create());
        foreach (['unsafe', 'superseded', 'wrong-claim'] as $case) {
            $saved = $record->fact_check_result;
            if ($case === 'unsafe') $saved['sources'][0]['url'] = 'javascript:alert(1)';
            if ($case === 'superseded') $saved['sources'][0]['relationships'][0]['superseded'] = true;
            if ($case === 'wrong-claim') $saved['sources'][0]['relationships'][0]['claim_id'] = 'different';
            $copy = clone $record;
            $copy->fact_check_result = $saved;
            $report = FactCheckReportPresenter::build($copy);
            $this->assertSame(0, $report['counts']['contradicted']);
            $this->assertNotSame('Likely Fake', $report['verdict_label']);
            $this->assertNull($report['risk_percentage']['value']);
        }
    }

    public function test_no_sources_is_not_a_fake_finding_and_legacy_claims_are_not_invented(): void
    {
        $record = $this->record(User::factory()->create());
        $record->fact_check_result = ['schema_version' => 4, 'primary_task' => 'claim', 'verdict' => 'UNVERIFIED', 'summary' => 'No matching evidence was recorded.'];
        $report = FactCheckReportPresenter::build($record);
        $this->assertSame('Unverified', $report['verdict_label']);
        $this->assertSame('Undetermined', $report['risk']['label']);
        $this->assertSame([], $report['claims']);
        $record->fact_check_result = ['schema_version' => 1, 'verdict' => 'FALSE', 'summary' => 'Old screening result.'];
        $this->assertSame([], FactCheckReportPresenter::build($record)['claims']);
    }

    public function test_media_model_output_is_separate_from_confidence_and_factual_risk(): void
    {
        $record = $this->record(User::factory()->create());
        $record->fact_check_result = MediaAnalysisReport::result(['status' => 'completed', 'media_type' => 'image',
            'label' => 'fake', 'fake_probability' => .98, 'label_mapping' => ['real', 'fake'],
            'output_contract' => ['activation' => 'sigmoid', 'positive_class' => 'fake', 'binary_exclusive' => true]], []);
        $report = FactCheckReportPresenter::build($record);
        $this->assertFalse($report['show_claims']);
        $this->assertSame('Model score: 98%', $report['model_score']['value']);
        $this->assertSame('Unavailable', $report['confidence']['value']);
        $this->assertSame('Not assessed', $report['risk']['label']);
        $this->assertSame('Likely AI-Generated', $report['verdict_label']);
    }

    public function test_conflicting_passages_are_unresolved_and_neither_side_is_hidden(): void
    {
        $record = $this->record(User::factory()->create());
        $saved = $record->fact_check_result;
        $saved['sources'][] = ['id' => 's2', 'url' => 'https://example.com/another-programme', 'name' => 'Another record',
            'title' => 'Conflicting programme', 'type' => 'reputable_news', 'relationships' => [['claim_id' => 'c1',
                'stance' => 'supporting', 'excerpt' => 'A second programme lists six rescue vehicles.',
                'explanation' => 'This second programme supports the claimed quantity.']]];
        $record->fact_check_result = $saved;
        $report = FactCheckReportPresenter::build($record);
        $this->assertSame('Missing Context', $report['verdict_label']);
        $this->assertSame(2, $report['counts']['unresolved']);
        $this->assertCount(1, $report['claims'][0]['evidence']['supporting']);
        $this->assertCount(1, $report['claims'][0]['evidence']['contradicting']);
        $this->assertSame('Context needed', $report['risk']['label']);
        $this->assertNull($report['risk_percentage']['value']);
    }

    public function test_only_a_valid_calibrated_confidence_can_be_displayed_as_confidence(): void
    {
        $record = $this->record(User::factory()->create());
        foreach (['calibrated', 'uncalibrated'] as $validation) {
            $saved = $record->fact_check_result;
            $saved['score'] = ['type' => 'confidence', 'value' => .72, 'label' => 'Confidence',
                'validation' => $validation, 'method_version' => 'synthetic-calibration-fixture',
                'explanation' => 'Synthetic validation fixture for saved model agreement; not a live calibration claim.'];
            $record->fact_check_result = $saved;
            $this->assertSame($validation === 'calibrated' ? 'Confidence: 72%' : 'Unavailable', FactCheckReportPresenter::build($record)['confidence']['value']);
        }
    }

    public function test_download_is_owner_scoped_and_does_not_rewrite_or_reanalyze_the_report(): void
    {
        $owner = User::factory()->create();
        $record = $this->record($owner, true);
        $before = $record->getRawOriginal();
        $this->actingAs(User::factory()->create())->get(route('detections.download-report', $record))->assertNotFound();
        $response = $this->actingAs($owner)->get(route('detections.download-report', $record))->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename="truthguard-report-'.$record->id.'.pdf"');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertStringContainsString('%%EOF', $response->getContent());
        $this->assertGreaterThan(1000, strlen($response->getContent()));
        $html = view('detections.download-report', ['selectedDetection' => $record,
            'transparentReport' => FactCheckReportPresenter::build($record)])->render();
        foreach (['Missing Context', 'Original vehicle programme', 'Publication date not recorded', 'Recommended action'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        foreach (['Extracted factual claims', 'Claim-by-claim assessment', 'id="claim-assessments"', 'Assessed-statement risk index', '97%'] as $text) {
            $this->assertStringNotContainsString($text, $html);
        }
        $this->assertSame($before, $record->fresh()->getRawOriginal());
        Http::assertNothingSent();
    }

    public function test_risk_percentage_uses_the_recorded_synthetic_output_not_real_class_confidence(): void
    {
        $owner = User::factory()->create();
        $record = $this->record($owner);
        foreach ([.98, .02, 0, 1] as $raw) {
            $record->fact_check_result = MediaAnalysisReport::result(['status' => 'completed', 'media_type' => 'image',
                'label' => $raw >= .5 ? 'fake' : 'real', 'fake_probability' => $raw, 'label_mapping' => ['real', 'fake'],
                'output_contract' => ['activation' => 'sigmoid', 'positive_class' => 'fake', 'binary_exclusive' => true]], []);
            $this->assertSame(round($raw * 100).'%', FactCheckReportPresenter::riskPercentage($record)['display']);
        }
        $record->save();
        $this->actingAs($owner)->get(route('detections.result', $record))->assertOk()
            ->assertSee('100%')->assertSee('AI-origin screening index')->assertSee('Download report')
            ->assertDontSee('Claim-by-claim assessment');
    }

    public function test_missing_risk_is_not_converted_from_evidence_strength_or_confidence(): void
    {
        $record = $this->record(User::factory()->create());
        $saved = $record->fact_check_result;
        $saved['sources'] = [];
        $record->fact_check_result = $saved;
        $this->assertSame('Unavailable', FactCheckReportPresenter::riskPercentage($record)['display']);
        $saved = $record->fact_check_result;
        $saved['score'] = ['type' => 'confidence', 'value' => .72, 'validation' => 'calibrated'];
        $record->fact_check_result = $saved;
        $this->assertSame('Unavailable', FactCheckReportPresenter::riskPercentage($record)['display']);
        $record->fact_check_result = null;
        $record->fake_score = 42;
        $this->assertSame('42%', FactCheckReportPresenter::riskPercentage($record)['display']);
        $record->processing_status = 'processing';
        $this->assertSame('Unavailable', FactCheckReportPresenter::riskPercentage($record)['display']);
    }

    public function test_risk_levels_use_the_requested_boundaries(): void
    {
        $colors = ['Very Low Risk' => 'green', 'Low Risk' => 'teal', 'Moderate Risk' => 'amber', 'High Risk' => 'orange', 'Critical Risk' => 'red'];
        foreach ([0 => 'Very Low Risk', 20 => 'Very Low Risk', 21 => 'Low Risk', 40 => 'Low Risk',
            41 => 'Moderate Risk', 60 => 'Moderate Risk', 61 => 'High Risk', 80 => 'High Risk',
            81 => 'Critical Risk', 100 => 'Critical Risk'] as $score => $label) {
            $this->assertSame($label, FactCheckReportPresenter::riskLevel($score));
            $this->assertSame($colors[$label], FactCheckReportPresenter::riskColor($score));
        }
        $this->assertSame('neutral', FactCheckReportPresenter::riskColor(null));
    }

    public function test_factual_risk_uses_assessed_statements_and_is_independent_of_confidence(): void
    {
        $record = $this->record(User::factory()->create(), true);
        $risk = FactCheckReportPresenter::riskPercentage($record);
        $this->assertSame('50%', $risk['display']);
        $this->assertSame('Moderate Risk', $risk['caption']);
        $this->assertSame(['total' => 3, 'assessed' => 2, 'flagged' => 1, 'unresolved' => 1], $risk['counts']);
        $saved = $record->fact_check_result;
        $saved['score'] = ['type' => 'confidence', 'value' => .72, 'label' => 'Confidence', 'validation' => 'calibrated',
            'method_version' => 'synthetic-fixture', 'explanation' => 'Synthetic calibrated confidence fixture.'];
        $record->fact_check_result = $saved;
        $this->assertSame('50%', FactCheckReportPresenter::riskPercentage($record)['display']);
        $this->assertSame('Confidence: 72%', FactCheckReportPresenter::build($record)['confidence']['value']);
    }

    public function test_all_supported_statements_can_have_zero_recorded_risk_but_missing_evidence_cannot(): void
    {
        $record = $this->record(User::factory()->create(), true);
        $saved = $record->fact_check_result;
        $saved['claims'] = [$saved['claims'][2]];
        $saved['sources'] = [$saved['sources'][1]];
        $saved['verdict'] = 'TRUE';
        $record->fact_check_result = $saved;
        $this->assertSame('0%', FactCheckReportPresenter::riskPercentage($record)['display']);
        $this->assertSame('Very Low Risk', FactCheckReportPresenter::riskPercentage($record)['caption']);
        $saved['sources'][0]['relationships'] = [];
        $saved['verdict'] = 'UNVERIFIED';
        $record->fact_check_result = $saved;
        $this->assertNull(FactCheckReportPresenter::riskPercentage($record)['value']);
        $this->assertSame('Risk undetermined', FactCheckReportPresenter::riskPercentage($record)['caption']);
    }

    public function test_incomplete_and_failed_reports_do_not_reuse_a_previous_risk_percentage(): void
    {
        $record = $this->record(User::factory()->create());
        foreach (['queued', 'processing', 'failed'] as $status) {
            $record->processing_status = $status;
            $this->assertNull(FactCheckReportPresenter::riskPercentage($record)['value']);
        }
    }

    public function test_video_risk_needs_usable_sampled_frames_and_preserves_its_recorded_index(): void
    {
        $record = $this->record(User::factory()->create());
        $saved = ['schema_version' => 4, 'primary_task' => 'media', 'verdict' => 'INCONCLUSIVE',
            'summary' => 'The sampled-frame result is not a whole-video authenticity finding.',
            'media_assessment' => ['status' => 'completed', 'media_type' => 'video', 'label' => 'fake',
                'fake_probability' => .73, 'screening_score' => 73, 'frames_used' => 8,
                'video_scope' => ['face_crop_required' => true, 'face_frames' => 8]]];
        $record->fact_check_result = $saved;
        $this->assertSame('73%', FactCheckReportPresenter::riskPercentage($record)['display']);
        $this->assertSame('High Risk', FactCheckReportPresenter::riskPercentage($record)['caption']);
        $saved['media_assessment']['video_scope']['face_frames'] = 0;
        $record->fact_check_result = $saved;
        $this->assertNull(FactCheckReportPresenter::riskPercentage($record)['value']);
        $saved['media_assessment']['frames_used'] = 0;
        $record->fact_check_result = $saved;
        $this->assertNull(FactCheckReportPresenter::riskPercentage($record)['value']);
    }

    public function test_rejected_claim_relationships_do_not_turn_into_numeric_risk(): void
    {
        $record = $this->record(User::factory()->create());
        foreach ([['basis' => 'background'], ['matches' => ['event' => false]], ['claim_id' => 'another-claim'],
            ['validation_limitation' => 'The saved passage does not address this assertion.']] as $change) {
            $saved = $record->fact_check_result;
            $row = $saved['sources'][0]['relationships'][0] + ['source_id' => 's1', 'basis' => 'primary_record'];
            $saved['claims'][0]['relationships'] = [array_replace($row, $change)];
            $record->fact_check_result = $saved;
            $this->assertNull(FactCheckReportPresenter::riskPercentage($record)['value']);
        }
    }
}
