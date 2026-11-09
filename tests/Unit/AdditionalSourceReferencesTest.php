<?php

namespace Tests\Unit;

use App\Services\Detections\AdditionalSourceReferences;
use PHPUnit\Framework\TestCase;

class AdditionalSourceReferencesTest extends TestCase
{
    private function fixtureResult(): array
    {
        return ['primary_task' => 'claim', 'claim' => 'The fictional library opened in 2024.'];
    }

    private function record(string $url, array $changes = []): array
    {
        return array_replace(['id' => md5($url), 'url' => $url, 'title' => 'The fictional library opened in 2024.',
            'name' => 'Fixture publisher', 'retrieval_status' => 'retrieved', 'assessment_role' => 'not_used',
            'source_type' => 'official_government', 'text' => 'The fictional library opened in 2024, according to the dated record.'], $changes);
    }

    public function test_saved_references_are_deduplicated_and_interleaved_without_becoming_evidence(): void
    {
        $service = new AdditionalSourceReferences;
        $rows = $service->select($this->fixtureResult(), [
            $this->record('https://agency.gov.ph/used?utm_source=test'),
            $this->record('https://agency.gov.ph/record'),
            $this->record('https://agency.gov.ph/record#page'),
            $this->record('https://agency.gov.ph/record-2'),
            $this->record('https://research.edu.ph/record', ['source_type' => 'institution']),
        ], [['url' => 'https://agency.gov.ph/used']]);
        $this->assertSame(['agency.gov.ph', 'research.edu.ph', 'agency.gov.ph'], array_column($rows, 'host'));
        $this->assertSame('Government record', $rows[0]['type_label']);
        $this->assertArrayNotHasKey('rating', $rows[0]);
        $this->assertArrayNotHasKey('text', $rows[0]);
        $this->assertSame([], $service->select(['primary_task' => 'media'] + $this->fixtureResult(), [$this->record('https://agency.gov.ph/record')], []));
        $this->assertSame([], $service->select(['primary_task' => 'claim'], [$this->record('https://agency.gov.ph/record')], []));
    }

    public function test_unreadable_unrelated_rejected_and_different_year_records_are_excluded(): void
    {
        $service = new AdditionalSourceReferences;
        foreach ([
            ['retrieval_status' => 'missing_full_text'], ['source_type' => 'unapproved'],
            ['assessment_role' => 'irrelevant'], ['assessment_role' => 'superseded'],
            ['title' => 'The fictional bridge opened in 2024.'],
            ['title' => 'The fictional library opened in 2025.'],
            ['text' => ''], ['title' => 'NoticeNotActiveErrorPage'],
            ['possible_copy_of' => 'other-record'], ['url' => 'http://127.0.0.1/private'],
            ['url' => 'https://agency.gov.ph/'],
            ['assessment_role' => 'background_or_rejected_match'],
        ] as $changes) {
            $this->assertSame([], $service->select($this->fixtureResult(), [$this->record('https://agency.gov.ph/record', $changes)], []), json_encode($changes));
        }
    }

    public function test_partial_but_relevant_review_requires_a_verified_passage_and_event_match(): void
    {
        $service = new AdditionalSourceReferences;
        $assessment = ['stance' => 'insufficient', 'excerpt' => 'The fictional library opened in 2024, according to the dated record.',
            'matches' => array_fill_keys(['event', 'entities', 'action', 'date', 'location'], true) + ['quantities' => false]];
        $record = $this->record('https://agency.gov.ph/record', ['title' => 'Library opening record',
            'assessment_role' => 'background_or_rejected_match', 'relevance_assessments' => [$assessment]]);
        $this->assertCount(1, $service->select($this->fixtureResult(), [$record], []));
        foreach ([['excerpt' => 'Invented quotation that is not present in the retrieved source.'],
            ['validation_limitation' => 'A materially different claim.'], ['superseded' => true],
            ['matches' => array_replace($assessment['matches'], ['event' => false])],
            ['matches' => array_replace($assessment['matches'], ['date' => false])],
        ] as $changes) {
            $record['relevance_assessments'] = [array_replace($assessment, $changes)];
            $this->assertSame([], $service->select($this->fixtureResult(), [$record], []));
        }
    }

    public function test_copied_content_does_not_add_a_publisher_and_assessments_must_belong_to_the_current_claim(): void
    {
        $service = new AdditionalSourceReferences;
        $records = [$this->record('https://agency.gov.ph/record', ['content_hash' => 'same-content']),
            $this->record('https://copy.example/record', ['content_hash' => 'same-content'])];
        $this->assertCount(1, $service->select($this->fixtureResult(), $records, []));
        $this->assertSame([], $service->select($this->fixtureResult(), $records, [['url' => $records[0]['url']]]));
        $assessment = ['claim_id' => 'another-claim', 'excerpt' => $records[0]['text'],
            'matches' => array_fill_keys(['event', 'entities', 'action', 'date', 'location'], true)];
        $record = $this->record('https://agency.gov.ph/record', ['assessment_role' => 'background_or_rejected_match',
            'relevance_assessments' => [$assessment]]);
        $this->assertSame([], $service->select($this->fixtureResult() + ['claims' => [
            ['id' => 'current-claim', 'kind' => 'factual', 'text' => $this->fixtureResult()['claim']],
        ]], [$record], []));
    }
}
