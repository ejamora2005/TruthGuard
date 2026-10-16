<?php

namespace Tests\Unit;

use App\Models\Detection;
use App\Services\Detections\AssessmentScore;
use App\Services\Detections\ReportAnswer;
use Tests\TestCase;

class ResultContractTest extends TestCase
{
    public function test_interpretation_action_omits_only_complete_repeated_summary_sentences(): void
    {
        $summary = 'The wording is incomplete. The visible text does not identify a specific assertion.';
        $recommendation = 'The visible text does not identify a specific assertion. Confirm the unreadable words; they could change its meaning.';
        $this->assertSame('Confirm the unreadable words; they could change its meaning.', ReportAnswer::interpretationAction($summary, $recommendation));
        $qualified = 'The visible text does not identify a specific assertion unless the missing date is recovered.';
        $this->assertSame($qualified, ReportAnswer::interpretationAction($summary, $qualified));
        $this->assertSame($summary, ReportAnswer::interpretationAction($summary, $summary));
    }

    public function test_explicitly_unreviewed_sources_and_their_citations_are_excluded(): void
    {
        $result = ReportAnswer::complete($this->fixture([
            'sources' => [['id' => 'discovered', 'url' => 'https://example.com/discovered', 'reviewed' => false]],
            'answer_segments' => [['text' => 'The claim is true.', 'source_ids' => ['discovered']]],
        ]));
        $this->assertSame([], $result['sources']);
        $this->assertSame('ANALYSIS_UNAVAILABLE', $result['verdict']);
        $this->assertSame([], $result['answer_segments'][0]['source_ids']);
    }

    public function test_structured_or_fenced_answer_never_becomes_public_explanation(): void
    {
        $malformed = [
            ['output' => [['text' => 'False']]],
            '{"status":"completed","output":[{"text":"False"}]}',
            json_encode(json_encode(['status' => 'completed', 'output' => ['False']])),
            "```json\n{\"verdict\":\"FALSE\"}\n```",
            '{"verdict":"FALSE",',
        ];
        foreach ($malformed as $value) {
            $result = ReportAnswer::complete($this->fixture(['summary' => $value]));
            $this->assertSame('ANALYSIS_UNAVAILABLE', $result['verdict']);
            $this->assertStringContainsString('readable explanation', $result['summary']);
            $this->assertSame('Valid independent finding.', $result['key_findings'][0]);
            $this->assertSame('https://example.com/reviewed', $result['sources'][0]['url']);
            $this->assertSame('Score unavailable', $result['score_display']);
            $this->assertContains('invalid_explanation', $result['diagnostics']['result_contract_errors']);
        }
    }

    public function test_valid_cited_segments_can_recover_from_a_bad_summary_without_inventing_sources(): void
    {
        $result = ReportAnswer::complete($this->fixture([
            'summary' => ['raw' => 'provider envelope'],
            'answer_segments' => [['text' => 'The reviewed record addresses the exact date.', 'source_ids' => ['s1']]],
        ]));

        $this->assertSame('TRUE', $result['verdict']);
        $this->assertSame('The reviewed record addresses the exact date.', $result['summary']);
        $this->assertSame(['s1'], $result['answer_segments'][0]['source_ids']);
    }

    public function test_invalid_citation_relationship_fails_closed_but_keeps_the_reviewed_source(): void
    {
        $result = ReportAnswer::complete($this->fixture([
            'answer_segments' => [['text' => 'The claim is true.', 'source_ids' => ['invented']]],
        ]));

        $this->assertSame('ANALYSIS_UNAVAILABLE', $result['verdict']);
        $this->assertSame([], $result['answer_segments'][0]['source_ids']);
        $this->assertCount(1, $result['sources']);
        $this->assertContains('invalid_citation_relationship', $result['diagnostics']['result_contract_errors']);
    }

    public function test_presentation_projection_omits_internal_records_and_validates_public_fields(): void
    {
        $saved = $this->fixture([
            'summary' => 'A readable saved explanation.',
            'score' => ['type' => 'model_score', 'value' => ['raw' => .9], 'label' => 'Model score'],
            'key_findings' => ['Valid independent finding.', ['raw' => 'array']],
            'limitations' => ['One readable limitation.', '{"error":"secret"}'],
            'diagnostics' => ['api_payload' => 'private-data'],
            'extraction' => ['visible_text' => 'raw OCR dump'],
            'trained_model' => ['prediction' => ['scores' => [0.1, 0.9]]],
            'candidate_records' => [['url' => 'https://example.com/unreviewed']],
            'official_references' => [
                ['url' => 'javascript:alert(1)', 'title' => 'Unsafe reference'],
                ['url' => 'https://example.org/official', 'title' => 'Agency page'],
            ],
        ]);

        $public = ReportAnswer::forPresentation($saved);
        $encoded = json_encode($public, JSON_THROW_ON_ERROR);
        $this->assertSame('A readable saved explanation.', $public['summary']);
        $this->assertSame(['Valid independent finding.'], $public['key_findings']);
        $this->assertSame(['One readable limitation.'], $public['limitations']);
        $this->assertSame('Score unavailable', $public['score_display']);
        $this->assertCount(1, $public['official_references']);
        foreach (['private-data', 'raw OCR dump', 'candidate_records', 'trained_model', 'api_payload', 'selected_mode', 'resolved_task'] as $private) {
            $this->assertStringNotContainsString($private, $encoded);
        }
        $this->assertSame('private-data', $saved['diagnostics']['api_payload']);
    }

    public function test_legacy_adapter_does_not_reassess_or_promote_a_risk_score(): void
    {
        $legacy = new Detection(['verdict' => 'fake', 'fake_score' => 91,
            'caption_text' => 'A saved historical claim.', 'analysis_summary' => '{"raw":"model"}']);
        $public = ReportAnswer::forLegacyDetection($legacy);

        $this->assertSame('UNVERIFIED', $public['verdict']);
        $this->assertSame('Historical: Likely Fake', $public['assessment_label']);
        $this->assertSame('fake', $public['historical_assessment']['verdict']);
        $this->assertStringContainsString('historical Likely Fake screening label', $public['summary']);
        $this->assertSame('Score unavailable', $public['score_display']);
        $this->assertSame([], $public['sources']);
        $this->assertStringNotContainsString('91', json_encode($public));
    }

    public function test_pre_v4_saved_links_remain_unvalidated_historical_references(): void
    {
        $saved = $this->fixture([
            'schema_version' => 2, 'verdict' => 'FALSE',
            'sources' => [[
                'url' => 'https://example.com/unrelated-review', 'title' => 'An older review of another claim',
                'name' => 'Example publisher', 'published_at' => '2024-05-01',
            ]],
            'answer_segments' => [['text' => 'The old model called this false.', 'source_ids' => ['invented']]],
        ]);
        $public = ReportAnswer::forPresentation($saved);

        $this->assertSame('FALSE', $saved['verdict']);
        $this->assertSame('UNVERIFIED', $public['verdict']);
        $this->assertSame('FALSE', $public['historical_assessment']['verdict']);
        $this->assertSame('Historical: False', $public['assessment_label']);
        $this->assertSame([], $public['sources']);
        $this->assertSame([], $public['contextual_sources']);
        $this->assertSame([], $public['answer_segments'][0]['source_ids']);
        $this->assertSame('An older review of another claim', $public['historical_references'][0]['title']);
        $this->assertSame('2024-05-01', $public['historical_references'][0]['published_at']);
    }

    public function test_public_details_translate_stage_status_and_exclude_developer_notes(): void
    {
        $saved = $this->fixture([
            'verdict' => 'UNVERIFIED',
            'key_findings' => ['A relevant public record was reviewed.', 'Regression test fixture passed.'],
            'limitations' => [
                'Discovery coverage was limited because gnews reported http_503.',
                'Analysis stage evidence_pass was incomplete (failed_validation_or_request).',
                'See C:\\laragon\\www\\TruthGuard\\storage\\app\\report.json',
            ],
            'ui' => ['show_evidence_warning' => true,
                'evidence_warning' => 'Discovery coverage was limited because gnews reported http_503.'],
        ]);

        $public = ReportAnswer::forPresentation($saved);
        $encoded = json_encode($public, JSON_THROW_ON_ERROR);
        $this->assertSame(['A relevant public record was reviewed.'], $public['key_findings']);
        $this->assertContains('Some source searches could not complete, so coverage may be incomplete.', $public['limitations']);
        $this->assertContains('Part of the evidence review could not be completed.', $public['limitations']);
        $this->assertSame('Some source searches could not complete, so coverage may be incomplete.', $public['ui']['evidence_warning']);
        foreach (['Regression test', 'gnews', 'http_503', 'evidence_pass', 'C:\\laragon'] as $internal) {
            $this->assertStringNotContainsString($internal, $encoded);
        }
    }

    public function test_public_answer_keeps_cited_finding_when_a_saved_detail_is_a_developer_note(): void
    {
        $saved = $this->fixture([
            'summary' => 'The reviewed record supports the stated date. This frozen fixture addresses the test case.',
            'answer_segments' => [
                ['text' => 'The reviewed record supports the stated date.', 'source_ids' => ['s1']],
                ['text' => 'This frozen fixture addresses the test case.', 'source_ids' => ['s1']],
            ],
        ]);

        $public = ReportAnswer::forPresentation($saved);

        $this->assertSame('TRUE', $public['verdict']);
        $this->assertSame('The reviewed record supports the stated date.', $public['summary']);
        $this->assertSame([['text' => 'The reviewed record supports the stated date.', 'source_ids' => ['s1']]], $public['answer_segments']);
        $this->assertCount(1, $public['sources']);
        $this->assertStringNotContainsString('fixture', json_encode($public));
    }

    public function test_interpretation_summarizes_the_finding_without_search_narration_or_background_quotes(): void
    {
        $finding = 'The submitted wording still needs clarification before a factual outcome can be judged. The visible text is too fragmentary to identify a specific factual assertion about the Constitution and elections.';
        $original = 'I searched for records about “constitution to, ano, the elections”. '.$finding
            .' A related source dated 2026-10-16 states: “A lengthy transcript provides related background.” This provides context, not a verdict.';
        $saved = ['verdict' => 'MISSING_CONTEXT', 'summary' => $original];
        $before = $saved;
        $this->assertSame($finding, ReportAnswer::interpretationSummary($saved, $original));
        $this->assertSame($before, $saved);
        $this->assertSame('The image origin remains inconclusive. No reliable origin conclusion was established.',
            ReportAnswer::interpretationSummary(['verdict' => 'INCONCLUSIVE'], 'The image origin remains inconclusive. No reliable origin conclusion was established. Review the original file.'));
    }

    private function fixture(array $overrides = []): array
    {
        return array_replace([
            'schema_version' => 4, 'primary_task' => 'claim', 'verdict' => 'TRUE',
            'claim' => 'A saved factual claim.', 'summary' => 'A readable saved explanation.',
            'key_findings' => ['Valid independent finding.'], 'limitations' => [],
            'sources' => [[
                'id' => 's1', 'url' => 'https://example.com/reviewed', 'name' => 'Example agency',
                'title' => 'Reviewed document', 'type' => 'institution', 'published_at' => '2026-10-13',
                'used_for' => 'The reviewed document addresses the stated date.',
            ]],
            'score' => AssessmentScore::unavailable('No validated percentage exists.'),
            'ui' => [], 'diagnostics' => [],
        ], $overrides);
    }
}
