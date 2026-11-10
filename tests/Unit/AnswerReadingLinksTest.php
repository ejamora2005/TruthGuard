<?php

namespace Tests\Unit;

use App\Services\Detections\FactCheckReportPresenter;
use App\Services\Detections\ResultWording;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AnswerReadingLinksTest extends TestCase
{
    public static function sourceTypes(): array
    {
        return [
            'study' => ['research', 'Science', 'https://www.science.org/doi/example', 'Read the study from Science'],
            'fact-check' => ['fact_check', 'VERA Files', 'https://verafiles.org/articles/example', 'Open the fact-check from VERA Files'],
            'news' => ['reputable_news', 'gmanetwork.com', 'https://www.gmanetwork.com/news/example', 'Read the report from GMA News'],
            'government' => ['official_government', 'PHIVOLCS', 'https://www.phivolcs.dost.gov.ph/example', 'Read the official record from PHIVOLCS'],
            'other source' => ['other', '', 'https://example.org/record', 'Read the source'],
        ];
    }

    #[DataProvider('sourceTypes')]
    public function test_links_use_the_actual_cited_source_and_its_recorded_type(string $type, string $name, string $url, string $label): void
    {
        Http::preventStrayRequests();
        $result = ['sources' => [['id' => 's1', 'name' => $name, 'type' => $type, 'url' => $url, 'title' => 'Recorded title']]];
        $before = $result;

        $this->assertSame([['url' => $url, 'label' => $label, 'title' => 'Recorded title']],
            FactCheckReportPresenter::readingLinks($result, ['source_ids' => ['s1']]));
        $this->assertSame($before, $result);
        Http::assertNothingSent();
    }

    public function test_uncited_invented_unsafe_and_duplicate_links_are_not_shown(): void
    {
        $result = ['sources' => [
            ['id' => 'used', 'name' => 'Records', 'url' => 'https://example.org/record'],
            ['id' => 'unused', 'url' => 'https://example.org/unrelated'],
            ['id' => 'unsafe', 'url' => 'javascript:alert(1)'],
            ['id' => 'duplicate', 'url' => 'https://example.org/record'],
        ]];
        $links = FactCheckReportPresenter::readingLinks($result, ['source_ids' => ['used', 'unsafe', 'invented', 'duplicate']]);
        $this->assertSame(['https://example.org/record'], array_column($links, 'url'));
        $this->assertSame([], FactCheckReportPresenter::readingLinks($result));
        $this->assertSame([], FactCheckReportPresenter::readingLinks($result, ['source_ids' => []]));
    }

    public function test_background_links_stay_distinct_from_evidence_and_segments_supply_legacy_citations(): void
    {
        $result = ['sources' => [], 'contextual_sources' => [
            ['id' => 'context', 'name' => 'GMA News', 'url' => 'https://www.gmanetwork.com/news/background',
                'type' => 'reputable_news', 'context_only' => true],
        ], 'answer_segments' => [['text' => 'Related background only.', 'source_ids' => ['context']]]];
        $links = FactCheckReportPresenter::readingLinks($result);
        $this->assertSame('Read background information from GMA News', $links[0]['label']);
    }

    public function test_both_generation_paths_request_short_grounded_answers_and_server_owned_links(): void
    {
        foreach ([ResultWording::instructions(), ResultWording::evidenceInstructions()] as $instructions) {
            $this->assertStringContainsString('40-90 words and three or four', $instructions);
            $this->assertStringContainsString('What the evidence shows', $instructions);
            $this->assertStringContainsString('direct reading links', $instructions);
            $this->assertStringContainsString('If evidence supports less detail, keep', $instructions);
            $this->assertStringNotContainsString('140-220', $instructions);
        }
    }
}
