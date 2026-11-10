<?php

namespace Tests\Unit;

use App\Services\Detections\Evidence\ClaimPlanner;
use App\Services\Detections\Evidence\StructuredAnalysis;
use Tests\TestCase;

class PosterClaimGroupingTest extends TestCase
{
    private function row(string $quote): array
    {
        return ['quote' => $quote, 'kind' => 'factual', 'entities' => [], 'dates' => [],
            'locations' => [], 'quantities' => [], 'qualifiers' => [], 'requires_context' => false,
            'context_reason' => '', 'searches' => ['DILG suspension announcement'], 'references' => []];
    }

    public function test_dependent_date_scope_attribution_and_rating_are_not_six_independent_assertions(): void
    {
        $regions = ['WEDNESDAY, AUGUST 27, 2025', 'WALANG PASOK', 'Alinsunod sa anunsyo ng DILG',
            'FAKE', 'ALL CLASS LEVELS', 'Public and Private Schools'];
        $text = implode("\n\n", $regions);
        $rows = array_map(fn ($quote) => $this->row($quote), $regions);
        $rows[0]['dates'] = ['AUGUST 27, 2025'];
        $rows[2]['entities'] = ['DILG'];
        $this->mock(StructuredAnalysis::class)->shouldReceive('ask')->once()->andReturn([
            'claims' => $rows, 'original_claim_date' => null, 'date_quote' => '',
        ]);

        $plan = app(ClaimPlanner::class)->plan($text, ['media_attached' => true, 'media_type' => 'image']);

        $this->assertCount(1, $plan['claims']);
        $claim = $plan['claims'][0];
        $this->assertSame($text, $claim['quote']);
        $this->assertSame($regions, $claim['merged_fragments']);
        $this->assertSame(['DILG'], $claim['entities']);
        $this->assertSame(['AUGUST 27, 2025'], $claim['dates']);
        $this->assertSame('factual', $claim['kind']);
        $this->assertArrayNotHasKey('verdict', $claim);
        $this->assertSame($text, $claim['submission_context']);
    }

    public function test_independent_assertions_on_an_image_are_kept_separate(): void
    {
        $regions = ['The library opened in 2024.', 'The mayor resigned in 2025.'];
        $this->mock(StructuredAnalysis::class)->shouldReceive('ask')->once()->andReturn([
            'claims' => array_map(fn ($quote) => $this->row($quote), $regions), 'original_claim_date' => null, 'date_quote' => '',
        ]);
        $plan = app(ClaimPlanner::class)->plan(implode("\n\n", $regions), ['media_attached' => true]);
        $this->assertSame($regions, array_column($plan['claims'], 'quote'));
    }

    public function test_text_articles_do_not_get_poster_grouping(): void
    {
        $regions = ['WALANG PASOK', 'ALL CLASS LEVELS'];
        $this->mock(StructuredAnalysis::class)->shouldReceive('ask')->once()->andReturn([
            'claims' => array_map(fn ($quote) => $this->row($quote), $regions), 'original_claim_date' => null, 'date_quote' => '',
        ]);
        $plan = app(ClaimPlanner::class)->plan(implode("\n\n", $regions));
        $this->assertCount(2, $plan['claims']);
    }

    public function test_overlapping_regions_of_one_announcement_share_one_dated_attribution_target(): void
    {
        $text = "WEDNESDAY, AUGUST 27, 2025\n\nWALANG PASOK\n\nAlinsunod sa anunsyo ng DILG\n\nALL CLASS LEVELS\n\nPublic and Private Schools";
        $first = $this->row("WEDNESDAY, AUGUST 27, 2025\n\nWALANG PASOK\n\nAlinsunod sa anunsyo ng DILG");
        $first['dates'] = ['AUGUST 27, 2025'];
        $first['entities'] = ['DILG'];
        $first['requires_context'] = true;
        $first['context_reason'] = 'The jurisdiction and geographic scope are not specified.';
        $second = $this->row("WALANG PASOK\n\nAlinsunod sa anunsyo ng DILG\n\nALL CLASS LEVELS\n\nPublic and Private Schools");
        $this->mock(StructuredAnalysis::class)->shouldReceive('ask')->once()->andReturn([
            'claims' => [$first, $second], 'original_claim_date' => null, 'date_quote' => '',
        ]);
        $plan = app(ClaimPlanner::class)->plan($text, ['media_attached' => true]);
        $this->assertCount(1, $plan['claims']);
        $this->assertSame($text, $plan['claims'][0]['quote']);
        $this->assertFalse($plan['claims'][0]['requires_context']);
        $this->assertSame($first['context_reason'], $plan['claims'][0]['scope_limitation']);
        $this->assertArrayNotHasKey('outcome', $plan['claims'][0]);
    }
}
