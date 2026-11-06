<?php

namespace Tests\Unit;

use App\Services\Detections\Evidence\ArticleSearch;
use App\Services\Detections\Evidence\EvidenceJudge;
use App\Services\Detections\Evidence\EvidencePassages;
use App\Services\Detections\Evidence\StructuredAnalysis;
use Tests\TestCase;

class EvidenceReviewCoverageTest extends TestCase
{
    private const CLAIM = 'The fictional municipal library opened on May 1, 2024.';

    private function claim(): array
    {
        return ['id' => 'c1', 'text' => self::CLAIM, 'kind' => 'factual', 'requires_context' => false];
    }

    private function source(string $id, string $text): array
    {
        return ['id' => $id, 'url' => 'https://example.gov.ph/library/'.$id, 'name' => 'Municipal records',
            'title' => 'Library record', 'text' => $text, 'source_type' => 'official_government',
            'retrieval_status' => 'retrieved', 'published_at' => '2024-05-02T00:00:00Z'];
    }

    private function row(string $id, string $excerpt, string $stance = 'supporting'): array
    {
        return ['claim_id' => 'c1', 'source_id' => $id, 'excerpt' => $excerpt, 'stance' => $stance,
            'explanation' => $excerpt, 'basis' => 'primary_record',
            'matches' => array_fill_keys(['event', 'entities', 'action', 'date', 'location', 'quantities', 'qualifiers'], true),
            'supersedes_source_ids' => [], 'supersession_excerpt' => ''];
    }

    public function test_a_decisive_passage_late_in_a_document_reaches_the_reviewer_within_the_wire_budget(): void
    {
        config()->set('fact_check.max_model_input_bytes', 18000);
        $text = str_repeat('Administrative background unrelated to the assertion. ', 140)
            .self::CLAIM.' '.str_repeat('Other administrative notes. ', 100);
        $sources = [$this->source('s1', $text)];
        foreach (range(2, 6) as $id) {
            $sources[] = $this->source('s'.$id, str_repeat('Unrelated archived administrative notes. ', 250));
        }
        $model = $this->mock(StructuredAnalysis::class);
        $model->shouldReceive('ask')->once()->withArgs(function ($stage, $instructions, $input) {
            $this->assertStringContainsString(self::CLAIM, $input['sources'][0]['text']);
            $this->assertLessThanOrEqual(18000, strlen($instructions) + strlen(json_encode($input, JSON_UNESCAPED_UNICODE)));

            return true;
        })->andReturn(['relationships' => [$this->row('s1', self::CLAIM)], 'context_findings' => []]);
        $judge = new EvidenceJudge($model, app(ArticleSearch::class));
        $rows = $judge->assess([$this->claim()], $sources, '2026-11-06T00:00:00Z', 0);
        $this->assertSame('supported', $judge->outcomes([$this->claim()], $rows)[0]['outcome']);
        $this->assertTrue($judge->reviewScopes['s1']['review_text_truncated']);
    }

    public function test_a_second_round_only_reviews_new_documents_and_preserves_contrary_findings(): void
    {
        $contrary = 'The fictional municipal library remained closed on May 1, 2024.';
        $model = $this->mock(StructuredAnalysis::class);
        $model->shouldReceive('ask')->once()->withArgs(fn ($stage) => $stage === 'evidence_relationships_0')
            ->andReturn(['relationships' => [$this->row('s1', self::CLAIM)], 'context_findings' => []]);
        $model->shouldReceive('ask')->once()->withArgs(function ($stage, $instructions, $input) {
            $this->assertSame('evidence_relationships_1', $stage);
            $this->assertSame(['s2'], array_column($input['sources'], 'id'));
            $this->assertSame(self::CLAIM, $input['previous_findings'][0]['excerpt']);

            return true;
        })->andReturn(['relationships' => [$this->row('s2', $contrary, 'contradicting')], 'context_findings' => []]);
        $judge = new EvidenceJudge($model, app(ArticleSearch::class));
        $source = $this->source('s1', self::CLAIM);
        $first = $judge->assess([$this->claim()], [$source], '2026-11-06T00:00:00Z', 0);
        $second = $judge->assess([$this->claim()], [$source, $this->source('s2', $contrary)], '2026-11-06T00:00:00Z', 1);
        $this->assertSame('conflicting', $judge->outcomes([$this->claim()], $judge->mergeReviews($first, $second))[0]['outcome']);
        $this->assertCount(2, $judge->assess([$this->claim()], [$source, $this->source('s2', $contrary)], '2026-11-06T00:00:00Z', 2));
        $this->assertSame(2, $judge->duplicateReviewsSkipped);
    }

    public function test_failed_reviews_are_not_reused_as_completed_reviews(): void
    {
        $model = $this->mock(StructuredAnalysis::class);
        $model->shouldReceive('ask')->once()->withArgs(fn ($stage) => $stage === 'evidence_relationships_0')->andReturnNull();
        $model->shouldReceive('ask')->once()->withArgs(fn ($stage) => $stage === 'evidence_relationships_1')
            ->andReturn(['relationships' => [$this->row('s1', self::CLAIM)], 'context_findings' => []]);
        $judge = new EvidenceJudge($model, app(ArticleSearch::class));
        $sources = [$this->source('s1', self::CLAIM)];
        $this->assertSame([], $judge->assess([$this->claim()], $sources, '2026-11-06T00:00:00Z', 0));
        $this->assertSame('supporting', $judge->assess([$this->claim()], $sources, '2026-11-06T00:00:00Z', 1)[0]['stance']);
    }

    public function test_a_new_dated_correction_can_supersede_a_cached_earlier_finding(): void
    {
        $correction = 'Correction: the fictional municipal library opened on May 2, 2024, replacing the earlier May 1 announcement.';
        $newRow = array_replace($this->row('s2', $correction, 'contradicting'),
            ['supersedes_source_ids' => ['s1'], 'supersession_excerpt' => $correction]);
        $model = $this->mock(StructuredAnalysis::class);
        $model->shouldReceive('ask')->once()->withArgs(fn ($stage) => $stage === 'evidence_relationships_0')
            ->andReturn(['relationships' => [$this->row('s1', self::CLAIM)], 'context_findings' => []]);
        $model->shouldReceive('ask')->once()->withArgs(function ($stage, $instructions, $input) {
            $this->assertSame(['s2'], array_column($input['sources'], 'id'));
            $this->assertSame('s1', $input['previous_findings'][0]['id']);
            $this->assertSame('2024-05-02T00:00:00Z', $input['previous_findings'][0]['published_at']);

            return $stage === 'evidence_relationships_1';
        })->andReturn(['relationships' => [$newRow], 'context_findings' => []]);
        $judge = new EvidenceJudge($model, app(ArticleSearch::class));
        $old = $this->source('s1', self::CLAIM);
        $judge->assess([$this->claim()], [$old], '2026-11-06T00:00:00Z', 0);
        $new = array_replace($this->source('s2', $correction), ['published_at' => '2024-05-03T00:00:00Z']);
        $rows = $judge->assess([$this->claim()], [$old, $new], '2026-11-06T00:00:00Z', 1);
        $outcome = $judge->outcomes([$this->claim()], $rows)[0];
        $this->assertSame('refuted', $outcome['outcome']);
        $this->assertTrue($outcome['relationships'][0]['superseded']);
        $this->assertSame(['s1'], $outcome['relationships'][1]['supersedes_source_ids']);
    }

    public function test_selected_passages_remain_literal_and_respect_a_multibyte_byte_limit(): void
    {
        $text = str_repeat('Unrelated records. ', 200).'東京 municipal library May 1, 2024. '.str_repeat('記録 ', 100);
        $passages = EvidencePassages::select($text, [$this->claim()], 480);
        $this->assertLessThanOrEqual(480, strlen(implode("\n\n", $passages)));
        $this->assertStringContainsString('municipal library', implode(' ', $passages));
        foreach ($passages as $passage) {
            $this->assertStringContainsString($passage, $text);
            $this->assertTrue(mb_check_encoding($passage, 'UTF-8'));
        }
    }

    public function test_a_quote_joined_across_separate_windows_is_rejected(): void
    {
        $source = $this->source('s1', 'The library was announced. Separate unrelated text. It opened in 2024.');
        $source['reviewed_text'] = "The library was announced.\n\nIt opened in 2024.";
        $row = $this->row('s1', $source['reviewed_text']);
        $reviewed = app(EvidenceJudge::class)->validate([$row], [$this->claim()], [$source]);
        $this->assertSame('insufficient', $reviewed[0]['stance']);
    }
}
