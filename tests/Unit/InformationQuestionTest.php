<?php

namespace Tests\Unit;

use App\Services\Detections\Evidence\ClaimPlanner;
use App\Services\Detections\Evidence\StructuredAnalysis;
use App\Services\Detections\ReportAnswer;
use App\Services\Detections\ResultWording;
use App\Services\Detections\ReviewedClaimLookup;
use App\Services\Detections\SubmissionTask;
use Tests\TestCase;

class InformationQuestionTest extends TestCase
{
    public function test_open_questions_retain_the_exact_requested_information(): void
    {
        foreach (['How much did each vehicle actually cost?', 'Magkano talaga ang bawat sasakyan?',
            'Who bought the vehicles?', 'Kailan binili ang mga sasakyan?',
            'Where were the vehicles purchased?', 'How many units were delivered?'] as $question) {
            $context = ['caption_text' => $question, 'media_type' => 'image'];
            $this->assertSame($question, SubmissionTask::question($context));
            $this->assertSame(rtrim($question, '?'), SubmissionTask::questionClaim($question));
            $this->assertSame(rtrim($question, '?'), SubmissionTask::claimText($question));
            $this->assertSame('claim', SubmissionTask::primary($context));
        }
    }

    public function test_a_price_question_is_not_replaced_with_a_caption_price_allegation(): void
    {
        $question = 'How much did each vehicle actually cost?';
        $target = rtrim($question, '?');
        $this->mock(StructuredAnalysis::class)->shouldReceive('ask')->once()
            ->withArgs(function ($stage, $instructions, $input, $schema) use ($target) {
                $this->assertSame($target, $input['question_target']);
                $this->assertSame([$target], $schema['properties']['claims']['items']['properties']['quote']['enum']);
                $this->assertStringContainsString('A request for an actual price is not answered merely by refuting a different alleged price', $instructions);

                return true;
            })->andReturn(['claims' => [['quote' => $target, 'kind' => 'question', 'entities' => [], 'dates' => [],
                'locations' => [], 'quantities' => [], 'qualifiers' => [], 'references' => [],
                'requires_context' => false, 'context_reason' => '', 'searches' => ['rescue vehicles procurement unit price']]],
                'original_claim_date' => null, 'date_quote' => '']);
        $plan = app(ClaimPlanner::class)->plan($target."\n\n10 million pesos bawat isa?", [
            'question' => $question, 'media_attached' => true, 'visual_context' => 'Three-wheeled rescue vehicles.',
        ]);
        $this->assertCount(1, $plan['claims']);
        $this->assertSame($target, $plan['claims'][0]['text']);
        $this->assertSame('factual', $plan['claims'][0]['kind']);
        $this->assertSame([], $plan['claims'][0]['quantities']);
    }

    public function test_an_information_answer_does_not_get_an_automatic_yes_or_no(): void
    {
        $target = 'How much did each vehicle actually cost';
        $result = ReportAnswer::complete(['primary_task' => 'claim', 'verdict' => 'TRUE', 'summary' => 'The contract lists the total price.',
            'request' => ['question' => $target.'?'],
            'claims' => [['id' => 'c1', 'text' => $target, 'kind' => 'factual', 'outcome' => 'supported']],
            'sources' => [['id' => 's1', 'url' => 'https://example.gov.ph/contract', 'used_for' => 'The contract lists the total price.']],
            'answer_segments' => [['text' => 'The contract lists the total price.', 'source_ids' => ['s1']]]]);
        $this->assertStringNotContainsString('Yes.', $result['summary']);
        $this->assertStringNotContainsString('No.', $result['summary']);
    }

    public function test_reviewed_claim_matching_preserves_currency_and_percent_scope(): void
    {
        $peso = ReviewedClaimLookup::normalize('The unit costs ₱10 million.');
        $this->assertNotSame($peso, ReviewedClaimLookup::normalize('The unit costs $10 million.'));
        $this->assertNotSame($peso, ReviewedClaimLookup::normalize('The unit costs 10 million.'));
        $this->assertNotSame(ReviewedClaimLookup::normalize('The rate is 10%.'), ReviewedClaimLookup::normalize('The rate is 10.'));
        $this->assertSame($peso, ReviewedClaimLookup::normalize(' THE UNIT COSTS ₱10 MILLION!'));
    }

    public function test_an_unresolved_price_question_does_not_become_a_claim_merging_question_and_ocr(): void
    {
        $caption = '10 million pesos bawat isa. Tansa ko nito baka 500k lang ito';
        $result = ReportAnswer::complete(['primary_task' => 'claim', 'verdict' => 'UNVERIFIED',
            'request' => ['question' => 'How much did each pictured vehicle actually cost?'],
            'claim' => 'How much did each pictured vehicle actually cost '.$caption,
            'summary' => 'The claim remains unverified.', 'sources' => [],
            'extraction' => ['visible_text' => $caption]]);
        $copy = ResultWording::ensure($result)['generated_wording'];
        $this->assertSame('The actual per-unit purchase price could not be established from the reviewed records.', $copy['explanation']);
        $this->assertSame($copy['explanation'], $result['summary']);
        $this->assertStringNotContainsString('500k', $copy['explanation']);
        $this->assertSame('UNVERIFIED', $copy['verdict']);
        $this->assertSame($caption, $result['extraction']['visible_text']);
    }
}
