<?php

namespace Tests\Unit;

use App\Services\Detections\Evidence\ClaimPlanner;
use App\Services\Detections\Evidence\StructuredAnalysis;
use Tests\TestCase;

class ClaimPlannerContextReasonTest extends TestCase
{
    public function test_attributed_prediction_is_checked_instead_of_the_rhetorical_headline(): void
    {
        $headline = 'WORLD ENDS WHEN?';
        $statement = 'The end of the world will be on Friday, November 13, 2026, according to a scientist’s mathematical prediction';
        $text = $headline."\n\n".$statement;
        $this->mock(StructuredAnalysis::class)->shouldReceive('ask')->once()->andReturn([
            'claims' => [
                ['quote' => $headline, 'kind' => 'rhetorical', 'entities' => [], 'dates' => [], 'locations' => [],
                    'quantities' => [], 'qualifiers' => [], 'requires_context' => true,
                    'context_reason' => 'The headline is a question.', 'searches' => ['world end November 2026'], 'references' => []],
                ['quote' => $statement, 'kind' => 'prediction', 'entities' => ['scientist'], 'dates' => ['November 13, 2026'],
                    'locations' => [], 'quantities' => [], 'qualifiers' => [], 'requires_context' => false,
                    'context_reason' => '', 'searches' => ['November 13 2026 prediction'], 'references' => []],
            ],
            'original_claim_date' => null, 'date_quote' => '',
        ]);

        $plan = app(ClaimPlanner::class)->plan($text);

        $this->assertCount(1, $plan['claims']);
        $this->assertSame($statement, $plan['claims'][0]['text']);
        $this->assertSame('factual', $plan['claims'][0]['kind']);
        $this->assertFalse($plan['claims'][0]['requires_context']);
    }

    public function test_a_searchable_topic_heading_does_not_request_a_logo_acronym_as_the_missing_claim(): void
    {
        $headline = '2026 BSKE POSTPONEMENT: A RACE AGAINST TIME AND THE CONSTITUTION TOA';
        $this->mock(StructuredAnalysis::class)->shouldReceive('ask')->once()->andReturn([
            'claims' => [[
                'quote' => $headline, 'kind' => 'unclear', 'entities' => ['BSKE'],
                'dates' => ['2026'], 'locations' => [], 'quantities' => [], 'qualifiers' => [],
                'requires_context' => true,
                'context_reason' => 'Explain what TOA stands for and whether the proposal was enacted.',
                'searches' => ['2026 BSKE postponement status'], 'references' => [],
            ]],
            'original_claim_date' => null, 'date_quote' => '',
        ]);

        $plan = app(ClaimPlanner::class)->plan($headline);

        $this->assertSame($headline, $plan['claims'][0]['text']);
        $this->assertSame('unclear', $plan['claims'][0]['kind']);
        $this->assertTrue($plan['claims'][0]['requires_context']);
        $this->assertStringNotContainsString('TOA', $plan['claims'][0]['context_reason']);
        $this->assertStringContainsString('specific factual outcome', $plan['claims'][0]['context_reason']);
    }

    public function test_an_incomplete_heading_mislabeled_factual_does_not_require_acronym_expansion(): void
    {
        $headline = '2026 BSKE postponement: a race against time';
        $this->mock(StructuredAnalysis::class)->shouldReceive('ask')->once()->andReturn([
            'claims' => [[
                'quote' => $headline, 'kind' => 'factual', 'entities' => ['BSKE'],
                'dates' => ['2026'], 'locations' => [], 'quantities' => [], 'qualifiers' => [],
                'requires_context' => true,
                'context_reason' => 'The text does not state what BSKE expands to or what specific status is being asserted.',
                'searches' => ['2026 BSKE postponement status'], 'references' => [],
            ]],
            'original_claim_date' => null, 'date_quote' => '',
        ]);

        $plan = app(ClaimPlanner::class)->plan($headline);

        $this->assertSame('factual', $plan['claims'][0]['kind']);
        $this->assertTrue($plan['claims'][0]['requires_context']);
        $this->assertStringNotContainsString('expands to', $plan['claims'][0]['context_reason']);
        $this->assertStringContainsString('specific factual outcome', $plan['claims'][0]['context_reason']);
    }

    public function test_acronym_reason_is_normalized_after_a_fragment_expands_to_the_full_heading(): void
    {
        $heading = 'SENATE OF THE PHILIPPINES BSKE 2026 BSKE POSTPONEMENT: A RACE AGAINST TIME';
        $this->mock(StructuredAnalysis::class)->shouldReceive('ask')->once()->andReturn([
            'claims' => [[
                'quote' => 'BSKE POSTPONEMENT', 'kind' => 'factual', 'entities' => ['BSKE'],
                'dates' => [], 'locations' => [], 'quantities' => [], 'qualifiers' => [],
                'requires_context' => true,
                'context_reason' => 'The acronym BSKE is present but what it refers to is not stated.',
                'searches' => ['2026 BSKE postponement'], 'references' => [],
            ]],
            'original_claim_date' => null, 'date_quote' => '',
        ]);

        $plan = app(ClaimPlanner::class)->plan($heading);

        $this->assertSame($heading, $plan['claims'][0]['quote']);
        $this->assertStringNotContainsString('acronym', $plan['claims'][0]['context_reason']);
    }
}
