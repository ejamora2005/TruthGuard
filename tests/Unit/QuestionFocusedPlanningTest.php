<?php

namespace Tests\Unit;

use App\Services\Detections\Evidence\ClaimPlanner;
use App\Services\Detections\Evidence\EvidenceJudge;
use App\Services\Detections\Evidence\RetrievalBudget;
use App\Services\Detections\Evidence\StructuredAnalysis;
use App\Services\Detections\ReportAnswer;
use Tests\TestCase;

class QuestionFocusedPlanningTest extends TestCase
{
    private function claim(string $quote): array
    {
        return ['quote' => $quote, 'kind' => 'factual', 'entities' => ['Riverside library'], 'dates' => ['2024'],
            'locations' => [], 'quantities' => [], 'qualifiers' => [], 'references' => [], 'searches' => ['Riverside library reopened 2024'],
            'requires_context' => false, 'context_reason' => ''];
    }

    public function test_a_question_is_the_single_target_while_poster_labels_remain_context(): void
    {
        $target = 'the library really reopened';
        $text = $target."\n\nRiverside library\n\n2024\n\nXII\n\nLIBRARY REOPENING: A NEW CHAPTER";
        $this->mock(StructuredAnalysis::class)->shouldReceive('ask')->once()->withArgs(function ($stage, $instructions, $input, $schema) use ($target) {
            $this->assertSame($target, $input['question_target']);
            $this->assertSame([$target], $schema['properties']['claims']['items']['properties']['quote']['enum']);
            $this->assertSame(1, $schema['properties']['claims']['maxItems']);
            $this->assertStringContainsString('QUESTION-FOCUSED PLAN', $instructions);

            return true;
        })->andReturn(['claims' => [array_replace($this->claim($target), ['searches' => ['Riverside library reopened 2024', 'Riverside library 2024 official reopening', 'Riverside library later closure record', 'Riverside library reopening correction']]), $this->claim('2024'), $this->claim('XII')], 'original_claim_date' => null, 'date_quote' => '']);
        $plan = app(ClaimPlanner::class)->plan($text, ['question' => 'Was the library really reopened?', 'media_attached' => true]);
        $this->assertCount(1, $plan['claims']);
        $this->assertSame($target, $plan['claims'][0]['text']);
        $this->assertSame(['2024'], $plan['claims'][0]['dates']);
        $this->assertStringContainsString('Riverside library', $plan['claims'][0]['submission_context']);
        $this->assertFalse($plan['claims'][0]['requires_context']);
        $this->assertSame(['Riverside library reopened 2024', 'Riverside library 2024 official reopening'], app(ClaimPlanner::class)->queries($plan['claims'], 0));
    }

    public function test_a_failed_question_plan_never_replaces_the_target_with_a_standalone_year(): void
    {
        $this->mock(StructuredAnalysis::class)->shouldReceive('ask')->once()->andReturn(null);
        $plan = app(ClaimPlanner::class)->plan("the library reopened\n\n2024\n\nXII", ['question' => 'Was the library reopened?']);
        $this->assertSame('the library reopened', $plan['claims'][0]['text']);
        $this->assertSame('unavailable', $plan['status']);
    }

    public function test_sentence_repair_does_not_append_poster_text_to_a_focused_question(): void
    {
        $target = 'the world will end on november 13 2026';
        $poster = 'BIG BREAKING: World will end in Two weeks. According to an old study published in journal Science.';
        // Missing punctuation or blank lines must not merge the requested
        // proposition with a headline, attribution, or unrelated OCR region.
        $text = $target.' '.$poster;
        $this->mock(StructuredAnalysis::class)->shouldReceive('ask')->once()->andReturn([
            'claims' => [$this->claim($target)], 'original_claim_date' => null, 'date_quote' => '',
        ]);

        $plan = app(ClaimPlanner::class)->plan($text, [
            'question' => 'is it true that the world will end on november 13 2026?', 'media_attached' => true,
        ]);

        $this->assertCount(1, $plan['claims']);
        $this->assertSame($target, $plan['claims'][0]['text']);
        $this->assertSame($target, $plan['claims'][0]['quote']);
        $this->assertStringContainsString($poster, $plan['claims'][0]['submission_context']);
        $this->assertArrayNotHasKey('extracted_fragment', $plan['claims'][0]);
    }

    public function test_an_attribution_in_the_poster_keeps_a_future_question_eligible_for_research(): void
    {
        $target = 'the demonstration city will flood in 2030';
        $text = $target."\n\nAccording to a study published by the demonstration institute, the city will flood in 2030.";
        $this->mock(StructuredAnalysis::class)->shouldReceive('ask')->once()->andReturn([
            'claims' => [array_replace($this->claim($target), ['kind' => 'prediction'])],
            'original_claim_date' => null, 'date_quote' => '',
        ]);
        $plan = app(ClaimPlanner::class)->plan($text, ['question' => 'Is it true that '.$target.'?']);

        $this->assertSame('factual', $plan['claims'][0]['kind']);
        $this->assertTrue($plan['claims'][0]['prediction_basis_check']);
        $this->assertSame($target, $plan['claims'][0]['text']);
        $this->assertNotEmpty(app(ClaimPlanner::class)->queries($plan['claims'], 0));
        $this->assertArrayNotHasKey('verdict', $plan['claims'][0]);
    }

    public function test_a_prediction_without_a_checkable_attribution_remains_a_prediction(): void
    {
        $target = 'the demonstration team will win in 2030';
        $this->mock(StructuredAnalysis::class)->shouldReceive('ask')->once()->andReturn([
            'claims' => [array_replace($this->claim($target), ['kind' => 'prediction'])],
            'original_claim_date' => null, 'date_quote' => '',
        ]);
        $plan = app(ClaimPlanner::class)->plan($target, ['question' => 'Is it true that '.$target.'?']);

        $this->assertSame('prediction', $plan['claims'][0]['kind']);
        $this->assertSame([], app(ClaimPlanner::class)->queries($plan['claims'], 0));
    }

    public function test_a_possessive_scientific_attribution_in_image_text_is_researched(): void
    {
        $target = 'the world will end on November 13, 2026';
        foreach (["scientist's", 'scientist’s', "researchers'"] as $attribution) {
            $text = 'is it true that '.$target."?\n\nBREAKING: American ".$attribution
                ." mathematical\nprediction claims the End of the World in 2026. Doomsday: Friday, 13 November, A.D. 2026";
            $this->mock(StructuredAnalysis::class)->shouldReceive('ask')->once()->andReturn([
                'claims' => [array_replace($this->claim($target), ['kind' => 'prediction'])],
                'original_claim_date' => null, 'date_quote' => '',
            ]);
            $plan = app(ClaimPlanner::class)->plan($text, [
                'question' => 'is it true that '.$target.'?', 'media_attached' => true,
            ]);

            $this->assertSame('factual', $plan['claims'][0]['kind']);
            $this->assertTrue($plan['claims'][0]['prediction_basis_check']);
            $this->assertSame($target, $plan['claims'][0]['quote']);
            $this->assertNotEmpty(app(ClaimPlanner::class)->queries($plan['claims'], 0));
            $this->assertArrayNotHasKey('verdict', $plan['claims'][0]);
        }
    }

    public function test_a_resolved_yes_no_question_leads_with_its_cited_answer_without_changing_the_verdict(): void
    {
        foreach (['TRUE' => 'Yes.', 'FALSE' => 'No.'] as $verdict => $prefix) {
            $result = ReportAnswer::complete(['primary_task' => 'claim', 'verdict' => $verdict,
                'request' => ['question' => 'Was the library reopened?', 'tasks' => ['claim']],
                'claims' => [['kind' => 'factual', 'text' => 'the library reopened']],
                'summary' => 'A simulated record establishes the library status.',
                'sources' => [['id' => 's1', 'url' => 'https://example.gov/library',
                    'used_for' => 'The simulated official record establishes the status of the library at the cutoff. The order took effect on April 12, 2024.']]]);
            $this->assertSame($verdict, $result['verdict']);
            $this->assertStringStartsWith($prefix, $result['summary']);
            $this->assertSame(['s1'], $result['answer_segments'][0]['source_ids']);
            $this->assertStringContainsString('April 12, 2024', $result['summary']);
            $this->assertSame($result['summary'], ReportAnswer::complete($result)['summary']);
        }
    }

    public function test_an_older_conditional_status_cannot_refute_a_later_current_status_question(): void
    {
        $text = 'The demonstration event will proceed unless a new order postpones it.';
        $claim = ['id' => 'c1', 'text' => 'the demonstration event postponed', 'kind' => 'factual', 'requires_context' => false, 'current_status_question' => true];
        $source = ['id' => 's1', 'url' => 'https://example.gov/event', 'text' => $text, 'published_at' => '2026-09-01'];
        $row = ['claim_id' => 'c1', 'source_id' => 's1', 'stance' => 'contradicting', 'excerpt' => $text,
            'explanation' => 'The old record was conditional.', 'basis' => 'primary_record',
            'matches' => array_fill_keys(['event', 'entities', 'action', 'date', 'location', 'quantities', 'qualifiers'], true),
            'supersedes_source_ids' => [], 'supersession_excerpt' => ''];
        $judge = app(EvidenceJudge::class);
        $this->assertSame('insufficient', $judge->validate([$row], [$claim], [$source], '2026-10-15')[0]['stance']);
        $claim['current_status_question'] = false;
        $this->assertSame('contradicting', $judge->validate([$row], [$claim], [$source], '2026-10-15')[0]['stance']);
    }

    public function test_evidence_review_time_does_not_consume_the_reserved_retrieval_window(): void
    {
        $budget = new RetrievalBudget;
        $before = $budget->remaining();
        $started = microtime(true);
        usleep(20000);
        $budget->excludeAnalysisTime(microtime(true) - $started);
        $this->assertEqualsWithDelta($before, $budget->remaining(), .01);
        $this->assertSame(0, $budget->searches);
        $this->assertSame(0, $budget->pages);
    }
}
