<?php

namespace Tests\Unit;

use App\Services\Detections\Evidence\RetrievalBudget;
use App\Services\Detections\Evidence\SourceCoverage;
use Tests\TestCase;

class SourceCoverageTest extends TestCase
{
    public function test_sources_must_be_admitted_readable_and_distinct(): void
    {
        $make = fn ($id, $changes = []) => array_replace(['id' => $id, 'url' => 'https://example.test/'.$id,
            'retrieval_status' => 'retrieved', 'source_type' => 'research', 'content_hash' => $id], $changes);
        $records = [$make('a'), $make('tracking', ['url' => 'https://www.example.test/a?utm_source=feed']),
            $make('copy', ['content_hash' => 'a']), $make('syndicated', ['possible_copy_of' => 'a']),
            $make('unread', ['retrieval_status' => 'http_403']), $make('snippet', ['retrieval_status' => 'discovered']),
            $make('irrelevant'), $make('b'), $make('c')];
        $ids = SourceCoverage::relevantIds([
            ['source_id' => 'a', 'stance' => 'supporting'], ['source_id' => 'tracking', 'stance' => 'supporting'],
            ['source_id' => 'copy', 'stance' => 'supporting'], ['source_id' => 'syndicated', 'stance' => 'contradicting'],
            ['source_id' => 'unread', 'stance' => 'supporting'], ['source_id' => 'snippet', 'stance' => 'supporting'],
            ['source_id' => 'irrelevant', 'stance' => 'insufficient'], ['source_id' => 'b', 'stance' => 'supporting'],
            ['source_id' => 'c', 'stance' => 'contradicting', 'superseded' => true],
        ]);
        $this->assertSame(2, SourceCoverage::count($records, $ids));
        $this->assertSame(1, SourceCoverage::count($records, ['syndicated', 'a', 'copy']));
    }

    public function test_additional_relevant_sources_count_as_research_progress(): void
    {
        $budget = new RetrievalBudget;
        $outcomes = [['id' => 'c1', 'outcome' => 'supported']];
        $budget->noteEvidence($outcomes);
        $before = $budget->evidenceSignature;
        $budget->coverageSources = 2;
        $budget->noteEvidence($outcomes);
        $this->assertNotSame($before, $budget->evidenceSignature);
        $this->assertSame(0, $budget->searches);
    }
}
