<?php

namespace Tests\Feature;

use App\Models\Detection;
use App\Models\User;
use App\Services\Detections\AssessmentScore;
use App\Services\Detections\GoogleFactCheckFeedService;
use App\Services\Detections\SubmissionTask;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResultSourceRenderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_result_page_renders_only_the_requested_submissions_saved_sources(): void
    {
        config()->set('services.live_verification.refresh_on_view', false);
        $this->mock(GoogleFactCheckFeedService::class)->shouldReceive('latest')->andReturn([]);
        $owner = User::factory()->create();
        $older = $this->resultFixture();
        $older['sources'] = [$this->source('older', 'https://example.com/older', 'Older submission record')];
        $current = $this->resultFixture();
        $current['sources'] = [$this->source('current', 'https://example.com/current', 'Current submission record')];
        Detection::create($this->detectionAttributes($owner, $older));
        $detection = Detection::create($this->detectionAttributes($owner, $current));

        $html = $this->actingAs($owner)->get(route('detections.result', $detection))->assertOk()->getContent();

        $this->assertStringContainsString('Current submission record', $html);
        $this->assertStringNotContainsString('Older submission record', $html);
        $this->assertSame(0, $this->xpath($html)->query('//*[@id="sources-to-verify"]/ancestor::details')->length);
    }

    public function test_processing_and_failed_submissions_do_not_show_a_stale_saved_report(): void
    {
        config()->set('services.live_verification.refresh_on_view', false);
        $owner = User::factory()->create();
        $result = $this->resultFixture();
        $result['sources'] = [$this->source('stale', 'https://example.com/stale', 'Stale reviewed document')];

        foreach (['queued', 'processing', 'failed'] as $status) {
            $attributes = $this->detectionAttributes($owner, $result);
            $attributes['processing_status'] = $status;
            $detection = Detection::create($attributes);
            $html = $this->actingAs($owner)->get(route('detections.result', $detection))->assertOk()->getContent();

            $this->assertStringContainsString('Assessment status', $html);
            $this->assertStringNotContainsString('Stale reviewed document', $html);
            $this->assertStringNotContainsString('Sources to verify', $html);
        }
    }

    public function test_citations_use_their_own_assessments_source_ids_when_ids_repeat(): void
    {
        $result = $this->resultFixture();
        $result['sources'] = [$this->source('s1', 'https://example.com/first', 'First reviewed document')];
        $result['answer_segments'] = [['text' => 'The first assessment cites its own document.', 'source_ids' => ['s1']]];
        $result['secondary_assessments'] = [[
            'assessment_label' => 'Secondary finding', 'summary' => 'The second assessment cites another document.',
            'score' => AssessmentScore::unavailable('No calibrated score is available.'),
            'sources' => [$this->source('s1', 'https://example.com/second', 'Second reviewed document')],
            'answer_segments' => [['text' => 'The second assessment cites another document.', 'source_ids' => ['s1']]],
        ]];

        $xpath = $this->xpath($this->render($result));

        $this->assertSame('#evidence-source-1', $xpath->query('//div[contains(@class,"tg-result-explanation")]//a[contains(@class,"tg-result-citation")]')->item(0)->getAttribute('href'));
        $this->assertSame('#evidence-source-2', $xpath->query('//section[contains(@class,"tg-result-secondary")]//a[contains(@class,"tg-result-citation")]')->item(0)->getAttribute('href'));
        $this->assertSame(1, $xpath->query('//*[@id="evidence-source-1"]')->length);
        $this->assertSame(1, $xpath->query('//*[@id="evidence-source-2"]')->length);
    }

    public function test_optional_factual_evidence_is_visible_outside_details_and_unsafe_urls_are_not_linked(): void
    {
        $claimResult = $this->resultFixture();
        $claimResult['sources'] = [
            $this->source('s1', 'https://example.com/leyte-bulletin', 'Reviewed Leyte bulletin', '2026-10-13T18:00:00Z')
                + ['retrieved_at' => '2026-10-14T00:20:00Z'],
            $this->source('unsafe', 'javascript:alert(1)', 'Unsafe stored source'),
        ];
        $claimResult['answer_segments'] = [['text' => 'The bulletin addresses the stated location and claim.', 'source_ids' => ['s1', 'unsafe']]];
        $result = $this->resultFixture();
        $result['primary_task'] = 'media';
        $result['verdict'] = 'LIKELY_NOT_AI_GENERATED';
        $result['assessment_label'] = 'Likely Not AI-Generated';
        $result['sources'] = [];
        $result['claim_assessment'] = ['status' => 'completed', 'result' => $claimResult];
        $result['official_references'] = [['title' => 'Authority homepage', 'url' => 'https://phivolcs.dost.gov.ph/']];

        $html = $this->render($result, new Detection(['media_type' => 'text', 'source_url' => 'javascript:alert(2)']));
        $xpath = $this->xpath($html);

        $this->assertSame(0, $xpath->query('//*[@id="sources-to-verify"]/ancestor::details')->length);
        $this->assertSame(1, $xpath->query('//*[@id="evidence-source-1"]')->length);
        $this->assertSame(0, $xpath->query('//*[@id="evidence-source-2"]')->length);
        $this->assertSame(1, $xpath->query('//a[@href="#evidence-source-1"]')->length);
        $this->assertSame(0, $xpath->query('//a[starts-with(@href,"javascript:")]')->length);
        $this->assertStringContainsString('Published / issued: Oct 14, 2026, 2:00 AM PHT (UTC+8)', $html);
        $this->assertStringContainsString('Retrieved: Oct 14, 2026, 8:20 AM PHT (UTC+8)', $html);
        $this->assertStringContainsString('Official references', $html);
        $this->assertSame(1, $xpath->query('//section[contains(@class,"tg-result-verify-sources")]//article[contains(@class,"tg-result-source")]')->length);
    }

    public function test_context_sources_are_cited_and_labeled_without_becoming_verdict_evidence(): void
    {
        $result = $this->resultFixture();
        $result['verdict'] = 'MISSING_CONTEXT';
        $result['assessment_label'] = 'Missing Context';
        $result['contextual_sources'] = [[
            'id' => 's1', 'name' => 'Synthetic publisher', 'title' => 'Related synthetic record',
            'url' => 'https://example.com/related', 'type' => 'official_government',
            'published_at' => '2026-10-13', 'explanation' => 'This record discusses the topic without resolving the headline.',
            'excerpt' => 'The proposal remained under discussion.', 'context_only' => true,
        ]];
        $result['answer_segments'] = [
            ['text' => 'The headline does not assert a final outcome.', 'source_ids' => []],
            ['text' => 'A retrieved record discusses the proposal.', 'source_ids' => ['s1']],
        ];
        $result['context_assessments'] = [[
            'label' => 'OUT_OF_CONTEXT', 'explanation' => 'The older discussion does not establish a final decision.', 'source_id' => 's1',
        ]];

        $html = $this->render($result);
        $xpath = $this->xpath($html);

        $this->assertSame(2, $xpath->query('//a[@href="#evidence-source-1"]')->length);
        $this->assertSame(1, $xpath->query('//*[@id="evidence-source-1"]')->length);
        $this->assertStringContainsString('Related context', $html);
        $this->assertStringContainsString('The proposal remained under discussion.', $html);
        $this->assertStringNotContainsString('Sources Used', $html);
        $this->assertSame(0, $xpath->query('//*[@id="sources-to-verify"]/ancestor::details')->length);
    }

    public function test_reviewed_source_cards_explain_support_contradiction_and_inconclusive_roles(): void
    {
        $result = $this->resultFixture();
        $result['sources'] = [
            $this->source('s1', 'https://example.com/supporting', 'Supporting record')
                + ['relationships' => [['stance' => 'supporting', 'excerpt' => 'The complete source passage supports the stated event.',
                    'explanation' => 'This record supports the dated claim.', 'superseded' => false]]],
            $this->source('s2', 'https://example.com/contradicting', 'Contradicting record')
                + ['relationships' => [['stance' => 'contradicting', 'excerpt' => 'The decision was still pending on the stated date.',
                    'explanation' => 'The claimed final action had not occurred.', 'superseded' => false]]],
            $this->source('s3', 'https://example.com/inconclusive', 'Inconclusive record')
                + ['relationships' => [['stance' => 'insufficient', 'excerpt' => 'The page mentions the bill without giving its final status.',
                    'explanation' => 'The page leaves the outcome unresolved.', 'superseded' => false]]],
        ];

        $html = $this->render($result);

        $this->assertStringContainsString('[1] Supports the claim', $html);
        $this->assertStringContainsString('[2] Contradicts the claim', $html);
        $this->assertStringContainsString('[3] Relevant but inconclusive', $html);
        $this->assertStringContainsString('The decision was still pending on the stated date.', $html);
        $this->assertStringContainsString('The page mentions the bill without giving its final status.', $html);
        $this->assertSame(0, $this->xpath($html)->query('//*[@id="sources-to-verify"]/ancestor::details')->length);
    }

    public function test_historical_links_are_separate_from_reviewed_source_count_and_citations(): void
    {
        $result = $this->resultFixture();
        $result['historical_references'] = [[
            'title' => 'Older saved article about a different claim',
            'name' => 'Saved publisher',
            'url' => 'https://example.com/older-report',
        ]];
        $result['answer_segments'] = [['text' => 'The historical report has no validated evidence link.', 'source_ids' => ['s1']]];

        $html = $this->render($result);
        $xpath = $this->xpath($html);

        $this->assertStringContainsString('Historical links', $html);
        $this->assertStringContainsString('Their relevance and full text were not validated', $html);
        $this->assertStringContainsString('Older saved article about a different claim', $html);
        $this->assertSame('0', trim($xpath->query('//*[@id="sources-to-verify"]/following-sibling::*[contains(@class,"tg-result-count")]')->item(0)?->textContent ?? ''));
        $this->assertSame(0, $xpath->query('//*[@id="evidence-source-1"]')->length);
        $this->assertSame(0, $xpath->query('//a[contains(@class,"tg-result-citation")]')->length);
    }

    public function test_saved_shortcut_instruction_is_not_displayed_as_a_user_question(): void
    {
        $preset = 'Fact-check this claim against trusted public sources and explain the evidence.';
        $result = $this->resultFixture();
        $result['request'] = ['question' => $preset, 'selected_mode' => 'claim', 'resolved_task' => 'claim'];
        $result['diagnostics'] = ['search_requests' => 0, 'page_requests' => 0, 'analysis_elapsed_ms' => 0,
            'providers' => [], 'model_stages' => [], 'candidate_records' => []];
        $result['extraction'] = ['reconstructed_text' => '', 'input_scope' => 'visible image text', 'status' => 'completed',
            'visible_text' => '', 'inferred_claim' => ''];
        $detection = new Detection(['media_type' => 'image', 'media_path' => 'detections/legacy.jpg', 'caption_text' => $preset]);

        $html = $this->render($result, $detection);

        $this->assertTrue(SubmissionTask::isPresetInstruction($preset));
        $this->assertSame($preset, $detection->caption_text);
        $this->assertSame($preset, $result['request']['question']);
        $this->assertStringContainsString('Submitted Evidence', $html);
        $this->assertStringNotContainsString($preset, $html);
        $this->assertStringNotContainsString('Your question', $html);
        $this->assertStringNotContainsString('Selected mode: claim', $html);

        $authored = 'Is the Riverside bridge open?';
        $this->assertFalse(SubmissionTask::isPresetInstruction($preset.' '.$authored));
        $detection->caption_text = $authored;
        $result['request']['question'] = $authored;
        $html = $this->render($result, $detection);
        $this->assertStringContainsString('Your question', $html);
        $this->assertStringContainsString('Question assessed', $html);
        $this->assertStringContainsString($authored, $html);
    }

    public function test_result_does_not_render_internal_records_even_inside_view_details_or_read_aloud(): void
    {
        $result = $this->resultFixture();
        $result['diagnostics'] = ['search_requests' => 1, 'page_requests' => 1,
            'results_found' => 1, 'sources_reviewed' => 0, 'sources_used' => 0,
            'analysis_elapsed_ms' => 350, 'providers' => ['gnews' => 'DEV_PROVIDER_SENTINEL'],
            'model_stages' => [], 'candidate_records' => [['url' => 'https://example.com/DEV_CANDIDATE_SENTINEL']],
            'search_attempts' => [['provider' => 'gnews', 'query' => 'DEV_QUERY_SENTINEL']]];
        $result['extraction'] = ['visible_text' => 'DEV_OCR_SENTINEL', 'reconstructed_text' => 'DEV_PARAGRAPH_SENTINEL'];
        $result['claims'] = [['id' => 'DEV_CLAIM_ID_SENTINEL', 'text' => 'A model claim', 'outcome' => 'needs_context']];
        $result['trained_model'] = ['status' => 'completed', 'prediction' => ['raw_verdict' => 'DEV_MODEL_SENTINEL']];
        $result['score']['debug'] = 'DEV_SCORE_SENTINEL';
        $result['key_findings'] = ['The saved assessment has a readable finding.'];
        $result['limitations'] = ['A material detail was not available.'];

        $html = $this->render($result);

        foreach (['DEV_PROVIDER_SENTINEL', 'DEV_CANDIDATE_SENTINEL', 'DEV_QUERY_SENTINEL',
            'DEV_OCR_SENTINEL', 'DEV_PARAGRAPH_SENTINEL', 'DEV_CLAIM_ID_SENTINEL',
            'DEV_MODEL_SENTINEL', 'DEV_SCORE_SENTINEL', 'Assessment details and diagnostics',
            'Original extraction record', 'Search requests:', 'Raw class score:'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $html);
        }
        $this->assertStringContainsString('The saved assessment has a readable finding.', $html);
        $this->assertStringContainsString('A material detail was not available.', $html);
        $this->assertStringContainsString('Read result aloud', $html);
    }

    private function resultFixture(): array
    {
        return [
            'schema_version' => 4, 'primary_task' => 'claim', 'verdict' => 'UNVERIFIED',
            'assessment_label' => 'Unverified', 'claim' => 'A synthetic fixture claim about Leyte.',
            'summary' => 'The fixture result does not establish this synthetic claim.',
            'score' => AssessmentScore::unavailable('No calibrated score is available.'),
            'evidence_strength' => ['label' => 'Insufficient', 'method' => 'Synthetic view fixture only.'],
            'key_findings' => [], 'limitations' => [], 'sources' => [], 'secondary_assessments' => [],
            'evidence_cutoff' => '2026-10-14T00:00:00Z',
            'ui' => ['show_official_verification_notice' => false, 'official_verification_notice' => null,
                'show_evidence_warning' => false, 'evidence_warning' => null,
                'show_missing_context' => false, 'missing_context' => null,
                'show_source_conflict' => false, 'source_conflict_message' => null,
                'highlight_evidence_cutoff' => false],
        ];
    }

    private function source(string $id, string $url, string $title, string $publishedAt = '2026-10-13'): array
    {
        return ['id' => $id, 'name' => 'Synthetic publisher', 'title' => $title,
            'url' => $url, 'type' => 'official_government', 'published_at' => $publishedAt,
            'used_for' => 'This fixture document addresses its own synthetic claim.'];
    }

    private function detectionAttributes(User $owner, array $result): array
    {
        return ['user_id' => $owner->id, 'source_kind' => 'link', 'media_type' => 'text',
            'caption_text' => 'Synthetic fixture submission.', 'verdict' => 'review', 'fake_score' => 0,
            'processing_status' => 'completed', 'fact_check_result' => $result];
    }

    private function render(array $result, ?Detection $detection = null): string
    {
        return view('detections.partials.canonical-result-card', [
            'result' => $result, 'selectedDetection' => $detection ?? new Detection(['media_type' => 'text']),
        ])->render();
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML($html);

        return new DOMXPath($document);
    }
}
