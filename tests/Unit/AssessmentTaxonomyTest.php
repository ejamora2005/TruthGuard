<?php

namespace Tests\Unit;

use App\Models\Detection;
use App\Notifications\FactCheckResultReady;
use App\Services\Detections\AssessmentVerdict;
use App\Services\Detections\DetectionVerdictPresenter;
use App\Services\Detections\Evidence\EvidenceJudge;
use App\Services\Detections\ReportAnswer;
use App\Services\Detections\SubmissionTask;
use Tests\TestCase;

class AssessmentTaxonomyTest extends TestCase
{
    public function test_history_and_notification_labels_preserve_a_fresh_context_finding(): void
    {
        $result = ReportAnswer::complete(['schema_version' => 4, 'primary_task' => 'context', 'verdict' => 'SATIRE_PARODY',
            'summary' => 'This demonstration story has explicitly documented satire provenance.',
            'sources' => [['id' => 's1', 'url' => 'https://example.gov/fictional-satire', 'used_for' => 'Synthetic satire provenance.']],
            'answer_segments' => [['text' => 'This demonstration story has documented satire provenance.', 'source_ids' => ['s1']]]]);
        $detection = new Detection(['verdict' => 'review', 'fact_check_result' => $result]);
        $detection->id = 1;
        $presentation = DetectionVerdictPresenter::forDetection($detection);
        $this->assertSame('Satire / Parody', $presentation['history_label']);
        $this->assertSame('satire_parody', $presentation['category_key']);
        $notification = (new FactCheckResultReady($detection))->toArray(new \stdClass);
        $this->assertStringContainsString('Context check result ready: Satire / Parody', $notification['title']);
        $this->assertStringNotContainsString('AI-generated', $notification['message']);
        $this->assertNull($notification['confidence']);
    }

    public function test_every_requested_category_has_a_stable_group_and_label(): void
    {
        foreach (['factual' => AssessmentVerdict::FACTUAL, 'context' => AssessmentVerdict::CONTEXT, 'media' => AssessmentVerdict::MEDIA] as $group => $labels) {
            foreach ($labels as $label) {
                $this->assertSame($group, AssessmentVerdict::group($label));
                $this->assertArrayHasKey($label, AssessmentVerdict::LABELS);
            }
        }
    }

    public function test_partly_true_does_not_describe_its_first_refuted_component_as_supported(): void
    {
        $sources = array_map(fn ($i) => ['id' => 's'.$i, 'url' => 'https://example.gov/demonstration/'.$i,
            'used_for' => 'A simulated source establishes one component of the demonstration.'], [1, 2]);
        $result = ReportAnswer::complete(['verdict' => 'PARTLY_TRUE', 'primary_task' => 'claim',
            'summary' => 'The two components have different outcomes.', 'sources' => $sources,
            'claims' => [['kind' => 'factual', 'text' => 'The first statement is refuted.', 'outcome' => 'refuted'],
                ['kind' => 'factual', 'text' => 'The second statement is supported.', 'outcome' => 'supported']]]);
        $this->assertSame('The submission contains both supported and refuted factual claims.', $result['answer_segments'][0]['text']);
        $this->assertSame(['s1', 's2'], $result['answer_segments'][0]['source_ids']);
    }

    public function test_context_verdicts_cannot_bypass_the_decisive_source_gate(): void
    {
        foreach (['OUTDATED', 'OUT_OF_CONTEXT', 'SATIRE_PARODY', 'CONFLICTING_EVIDENCE'] as $verdict) {
            $result = ReportAnswer::complete(['primary_task' => 'context', 'verdict' => $verdict, 'summary' => 'A simulated finding without any reviewed source.']);
            $this->assertSame('ANALYSIS_UNAVAILABLE', $result['verdict']);
            $this->assertSame('processing', $result['assessment_group']);
            $this->assertNull($result['score']['value']);
        }
    }

    public function test_old_person_does_not_route_a_factual_event_to_reuse_context(): void
    {
        $this->assertSame(['claim'], SubmissionTask::tasks(['caption_text' => 'Did the firefighter rescue that old man?']));
        $this->assertSame(['context', 'claim'], SubmissionTask::tasks(['caption_text' => 'Is this an old photo?']));
        $this->assertSame(['context', 'claim'], SubmissionTask::tasks(['caption_text' => 'Is this satire?']));
    }

    public function test_satire_needs_explicit_provenance_and_context_cannot_use_future_sources(): void
    {
        $claim = ['id' => 'c1', 'kind' => 'factual', 'text' => 'The demonstration library story is current.'];
        $source = ['id' => 's1', 'url' => 'https://example.gov/library', 'text' => 'The demonstration library story has a surprising and funny tone.', 'published_at' => '2026-01-01'];
        $row = ['claim_id' => 'c1', 'source_id' => 's1', 'label' => 'SATIRE_PARODY', 'exact_subject_match' => true,
            'submitted_quote' => $claim['text'], 'excerpt' => $source['text'], 'explanation' => 'A simulated interpretation.', 'presented_as_current' => true, 'valid_until' => null];
        $judge = app(EvidenceJudge::class);
        $this->assertSame([], $judge->validateContext([$row], [$claim], [$source], '2026-02-01'));
        $source['text'] = $row['excerpt'] = 'The demonstration library story was explicitly published as satire and fictional parody.';
        $this->assertCount(1, $judge->validateContext([$row], [$claim], [$source], '2026-02-01'));
        $source['published_at'] = '2026-03-01';
        $this->assertSame([], $judge->validateContext([$row], [$claim], [$source], '2026-02-01'));
    }
}
