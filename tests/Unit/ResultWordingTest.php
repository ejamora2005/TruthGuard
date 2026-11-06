<?php

namespace Tests\Unit;

use App\Services\Detections\Evidence\SubmittedText;
use App\Services\Detections\ResultWording;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ResultWordingTest extends TestCase
{
    public function test_fallback_keeps_specific_evidence_when_summary_contains_a_link(): void
    {
        Http::preventStrayRequests();
        $result = ['verdict' => 'TRUE', 'primary_task' => 'claim',
            'summary' => 'The UN 2024 projection places the population peak at about 10.3 billion in the mid-2080s. The report is available at https://www.un.org/example.',
            'sources' => [['id' => 'un-2024']], 'contextual_sources' => []];
        $copy = ResultWording::saved(ResultWording::ensure($result));
        $this->assertNotNull($copy);
        $this->assertStringContainsString('10.3 billion', $copy['explanation']);
        $this->assertStringContainsString('mid-2080s', $copy['explanation']);
        $this->assertStringNotContainsString('https://', $copy['explanation']);
        $this->assertNotSame($copy['explanation'], $copy['result_summary']);
        Http::assertNothingSent();
    }

    public function test_generic_saved_copy_is_rebuilt_from_recorded_evidence_segments(): void
    {
        $generic = 'Review the displayed verdict and its supporting evidence before relying on this result.';
        $result = ['verdict' => 'TRUE', 'summary' => $generic, 'sources' => [['id' => 'un-2024']],
            'answer_segments' => [['text' => 'The UN projects a population peak in the mid-2080s.', 'source_ids' => ['un-2024']]],
            'generated_wording' => ['version' => ResultWording::VERSION, 'explanation' => $generic]];
        $copy = ResultWording::saved(ResultWording::ensure($result));
        $this->assertNotNull($copy);
        $this->assertStringContainsString('mid-2080s', $copy['explanation']);
    }

    public function test_limited_evidence_fallback_is_short_specific_and_saved_without_a_request(): void
    {
        Http::preventStrayRequests();
        $result = ['verdict' => 'MISSING_CONTEXT', 'primary_task' => 'claim', 'claim' => 'Constitution and elections',
            'summary' => 'I searched for records about the topic. The wording does not identify a specific constitutional change. A related source dated 2026-10-16 states: “A long background quotation.”',
            'sources' => [], 'contextual_sources' => []];
        $stored = ResultWording::ensure($result, 'Confirm which constitutional change the post refers to. Then verify its date.');
        $copy = ResultWording::saved($stored);
        $this->assertNotNull($copy);
        $this->assertStringNotContainsString('The submitted claim is:', $copy['explanation']);
        $this->assertStringContainsString('does not identify a specific constitutional change', $copy['explanation']);
        $this->assertStringNotContainsString('I searched', $copy['explanation']);
        $this->assertStringNotContainsString('background quotation', $copy['explanation']);
        $this->assertLessThan(80, Str::wordCount($copy['explanation']));
        $this->assertSame('Confirm which constitutional change the post refers to.', $copy['recommended_action']);
        $this->assertSame($stored, ResultWording::ensure($stored, 'A different action.'));
        Http::assertNothingSent();
    }

    public function test_a_later_missing_candidate_cannot_revive_outdated_review_wording(): void
    {
        $result = ['verdict' => 'UNVERIFIED'];
        $candidate = ['verdict' => 'UNVERIFIED', 'explanation' => 'The available record does not establish the claim.',
            'result_summary' => 'The claim remains unverified.', 'recommended_action' => 'Check the original record.', 'source_ids' => []];
        $this->assertArrayHasKey('generated_wording', ResultWording::attach($result, [$candidate]));
        $this->assertSame($result, ResultWording::attach($result, [$candidate, null]));
    }

    public function test_saved_wording_does_not_survive_a_changed_final_verdict(): void
    {
        $stored = ResultWording::ensure(['verdict' => 'INCONCLUSIVE', 'primary_task' => 'media',
            'summary' => 'The image origin could not be resolved.']);
        $this->assertNotNull(ResultWording::saved($stored));
        $stored['verdict'] = 'LIKELY_AI_GENERATED';
        $this->assertNull(ResultWording::saved($stored));
    }

    public function test_reused_result_gets_fresh_wording_without_changing_its_facts(): void
    {
        Http::preventStrayRequests();
        $result = ResultWording::ensure([
            'verdict' => 'TRUE', 'primary_task' => 'claim',
            'summary' => 'The official record confirms that the election was postponed.',
            'sources' => [['id' => 'official-1']], 'contextual_sources' => [],
        ]);
        $original = ResultWording::saved($result);
        $varied = ResultWording::varyForReuse($result, 'second-submission');
        $copy = ResultWording::saved($varied);

        $this->assertNotSame($original['explanation'], $copy['explanation']);
        $this->assertStringContainsString(lcfirst($original['explanation']), $copy['explanation']);
        $this->assertSame($result['verdict'], $varied['verdict']);
        $this->assertSame($result['summary'], $varied['summary']);
        $this->assertSame($result['sources'], $varied['sources']);
        Http::assertNothingSent();
    }

    public function test_legacy_misleading_copy_is_reconstructed_as_a_focused_answer_and_summary(): void
    {
        Http::preventStrayRequests();
        $result = [
            'verdict' => 'MISLEADING', 'primary_task' => 'claim',
            'summary' => 'No. There is no scientific evidence that the world will end on November 13, 2026. A 1960 Science paper used that date for a mathematical population model, not a literal apocalypse prediction. The calculation assumed population growth would continue unchanged, but it slowed. Current United Nations projections place the population peak around the mid-2080s.',
            'sources' => [['id' => 'science-paper']], 'contextual_sources' => [],
            'generated_wording' => ['version' => 'result-wording-v2'],
        ];

        $copy = ResultWording::saved(ResultWording::ensure($result));

        $this->assertNotNull($copy);
        $this->assertStringStartsWith('There is no scientific evidence', $copy['explanation']);
        $this->assertStringContainsString('scientific evidence', $copy['explanation']);
        $this->assertStringContainsString('mathematical population model', $copy['explanation']);
        $this->assertStringContainsString('United Nations projections', $copy['explanation']);
        $this->assertCount(4, SubmittedText::sentences($copy['explanation']));
        $this->assertStringNotContainsString('Misleading', $copy['explanation']);
        $this->assertSame('The claim presents the information without its proper context, creating an inaccurate overall impression.', $copy['result_summary']);
        $this->assertCount(1, SubmittedText::sentences($copy['result_summary']));
        Http::assertNothingSent();
    }

    public function test_no_search_results_use_fixed_wording_without_a_model_request(): void
    {
        Http::preventStrayRequests();
        $result = ResultWording::ensure([
            'verdict' => 'UNVERIFIED', 'primary_task' => 'claim',
            'summary' => 'Internal search output that should not be displayed.',
            'claim' => 'The fictional bridge reopened in 2026.',
            'sources' => [], 'contextual_sources' => [],
            'diagnostics' => ['retrieval_status' => 'completed_no_results', 'outcome_reason' => 'no_search_results'],
        ]);

        $copy = ResultWording::saved($result);
        $this->assertSame('The claim “The fictional bridge reopened in 2026.” remains unverified. The completed searches returned no usable matching source documents.', $copy['explanation']);
        $this->assertSame('No reliable matching source was available to support a factual conclusion.', $copy['result_summary']);
        $this->assertSame([], $copy['source_ids']);
        Http::assertNothingSent();
    }

    public function test_inaccessible_social_post_uses_fixed_wording_without_a_model_request(): void
    {
        Http::preventStrayRequests();
        $result = ResultWording::ensure([
            'verdict' => 'UNVERIFIED', 'primary_task' => 'claim',
            'sources' => [], 'contextual_sources' => [],
            'extraction' => ['source_record' => [
                'url' => 'https://www.facebook.com/example/posts/123',
                'status' => 'http_403', 'text' => '',
            ]],
            'diagnostics' => ['retrieval_status' => 'completed_no_results'],
        ]);

        $copy = ResultWording::saved($result);
        $this->assertStringStartsWith('TruthGuard could not access the linked social-media post.', $copy['explanation']);
        $this->assertStringContainsString('not publicly accessible', $copy['result_summary']);
        $this->assertStringContainsString('upload a clear screenshot', $copy['recommended_action']);
        Http::assertNothingSent();
    }

    public function test_blocked_link_replaces_generic_saved_missing_context_copy(): void
    {
        $result = ResultWording::ensure(['verdict' => 'MISSING_CONTEXT', 'sources' => [],
            'summary' => 'The submitted wording does not provide enough context for a specific factual assessment.']);
        $result['extraction'] = ['submitted_text' => '', 'source_record' => [
            'url' => 'https://www.facebook.com/share/p/example/', 'status' => 'http_403', 'text' => '',
        ]];
        $copy = ResultWording::saved(ResultWording::ensure($result));
        $this->assertStringStartsWith('TruthGuard could not access the linked social-media post.', $copy['explanation']);
        $result['extraction']['submitted_text'] = 'The bridge reopened yesterday.';
        $this->assertStringNotContainsString('TruthGuard could not access', ResultWording::saved(ResultWording::ensure($result))['explanation']);
    }

    public function test_failed_article_link_requests_one_screenshot_or_visible_claim(): void
    {
        $result = ResultWording::ensure([
            'verdict' => 'UNVERIFIED', 'primary_task' => 'claim',
            'sources' => [], 'contextual_sources' => [],
            'extraction' => ['submitted_text' => '', 'submitted_notes' => '', 'source_record' => [
                'url' => 'https://news.example.test/story', 'status' => 'http_503', 'text' => '',
            ]],
        ]);

        $copy = ResultWording::saved($result);
        $this->assertStringContainsString('could not read the linked page', $copy['explanation']);
        $this->assertStringContainsString('upload a clear screenshot', $copy['recommended_action']);
        $this->assertStringContainsString('provide this only once', $copy['recommended_action']);
    }

    public function test_internal_service_failure_does_not_request_a_screenshot(): void
    {
        $result = ResultWording::ensure(['verdict' => 'ANALYSIS_UNAVAILABLE', 'summary' => 'The analysis service is temporarily unavailable; retry this saved report later.',
            'sources' => [], 'extraction' => ['failure_reason' => 'quota_exhausted', 'source_record' => [
                'url' => 'https://news.example.test/story', 'status' => 'http_403', 'text' => '',
            ]], 'diagnostics' => ['outcome_reason' => 'analysis_failure']], 'Retry this saved report later.');
        $copy = ResultWording::saved($result);
        $this->assertStringNotContainsString('screenshot', $copy['recommended_action']);
        $this->assertStringContainsString('retry', strtolower($copy['explanation']));
    }
}
