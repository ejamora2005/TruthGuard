<?php

namespace Tests\Feature;

use App\Models\Detection;
use App\Models\User;
use App\Services\Detections\GoogleFactCheckFeedService;
use App\Services\Detections\ReportAnswer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReadableEvidenceExplanationTest extends TestCase
{
    use RefreshDatabase;

    public function test_short_saved_quote_gets_a_concise_grounded_explanation_without_reanalysis(): void
    {
        $this->mock(GoogleFactCheckFeedService::class)->shouldNotReceive('latest');
        $user = User::factory()->create();
        $passage = 'The agency said on January 14 that it had no official record of the reported departure.';
        $result = ['schema_version' => 4, 'primary_task' => 'claim', 'verdict' => 'FALSE',
            'claim' => 'A person was spotted at an airport.', 'summary' => 'The cited statement addresses departure records.',
            'sources' => [['id' => 's1', 'name' => 'Fixture publisher', 'url' => 'https://example.test/review',
                'relationships' => [['claim_id' => 'c1', 'stance' => 'contradicting', 'excerpt' => $passage]]]],
            'answer_validation' => ['status' => 'rebuilt_from_validated_passages'],
            'interpretation' => ['ambiguities' => ['The submission does not state when the claim or post appeared.']],
            'extraction' => ['input_scope' => 'visible image text and supplied caption'],
            'generated_wording' => ['version' => 'result-wording-v11', 'verdict' => 'FALSE', 'source_ids' => ['s1'],
                'explanation' => 'Fixture publisher states: “'.$passage.'”',
                'result_summary' => 'The cited statement addresses the claim.', 'recommended_action' => 'Read the dated source.']];
        $detection = Detection::create(['user_id' => $user->id, 'source_kind' => 'upload', 'media_type' => 'image',
            'processing_status' => 'completed', 'verdict' => 'fake', 'fake_score' => 100,
            'analyzed_at' => now(), 'fact_check_result' => $result]);
        $before = $detection->fresh()->getRawOriginal();
        $html = $this->actingAs($user)->get(route('detections.result', $detection))->assertOk()->getContent();
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $paragraph = (new \DOMXPath($document))->query('//div[contains(@class, "tg-verdict-explanation")]/p')->item(0);
        $this->assertLessThan(100, Str::wordCount($paragraph->textContent));
        $this->assertStringContainsString($passage, $paragraph->textContent);
        $this->assertStringContainsString('does not prove that an event never occurred', $paragraph->textContent);
        $this->assertSame($before, $detection->fresh()->getRawOriginal());
        Http::assertNothingSent();
    }

    public function test_saved_raw_table_fallback_renders_reviewed_reasoning_without_requests_or_record_changes(): void
    {
        Http::preventStrayRequests();
        $this->mock(GoogleFactCheckFeedService::class)->shouldNotReceive('latest');
        $user = User::factory()->create();
        $explanation = "The PHIVOLCS volcano list places Mayon in Albay, Bicol Region, not in Batangas. This directly contradicts the claim about Mayon Volcano's location.";
        $row = ['claim_id' => 'c1', 'source_id' => 's1', 'stance' => 'contradicting', 'basis' => 'primary_record',
            'excerpt' => '18Mayon13.25519123.68615Albay, Bicol Region in Luzon', 'explanation' => $explanation,
            'matches' => array_fill_keys(['event', 'entities', 'action', 'date', 'location', 'quantities', 'qualifiers'], true)];
        $result = ReportAnswer::complete(['schema_version' => 4, 'primary_task' => 'claim', 'verdict' => 'FALSE',
            'claim' => 'Ang Bulkang Mayon ay matatagpuan sa Batangas.', 'summary' => $explanation,
            'claims' => [['id' => 'c1', 'kind' => 'factual', 'outcome' => 'refuted', 'text' => 'Ang Bulkang Mayon ay matatagpuan sa Batangas.',
                'relationships' => [$row]]],
            'sources' => [['id' => 's1', 'name' => 'www.phivolcs.dost.gov.ph', 'title' => 'Volcanoes of the Philippines | PHIVOLCS',
                'type' => 'official_government',
                'url' => 'https://www.phivolcs.dost.gov.ph/volcanoes-of-the-philippines/', 'relationships' => [$row]]]]);
        $result['generated_wording'] = ['version' => 'result-wording-v10', 'verdict' => 'FALSE',
            'explanation' => 'phivolcs.dost.gov.ph states: “18Mayon13.25519123.68615Albay, Bicol Region in Luzon”',
            'source_ids' => ['s1'], 'result_summary' => 'The claim conflicts with the strongest available evidence.',
            'recommended_action' => 'Review the original record.'];
        $detection = Detection::create(['user_id' => $user->id, 'source_kind' => 'upload', 'media_type' => 'image',
            'processing_status' => 'completed', 'verdict' => 'fake', 'fake_score' => 100,
            'analyzed_at' => now(), 'fact_check_result' => $result]);
        $snapshot = $detection->fresh()->getRawOriginal();
        $html = $this->actingAs($user)->get(route('detections.result', $detection))->assertOk()->getContent();
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($document);
        $paragraph = $xpath->query('//div[contains(@class, "tg-verdict-explanation")]/p')->item(0);
        $this->assertNotNull($paragraph);
        $this->assertStringStartsWith($explanation, trim($paragraph->textContent));
        $this->assertStringNotContainsString('The specific statement being checked', $paragraph->textContent);
        $this->assertStringNotContainsString('18Mayon13', $paragraph->textContent);
        $links = $xpath->query('//ul[@aria-label="Read the cited sources"]//a');
        $this->assertCount(1, $links);
        $this->assertSame('https://www.phivolcs.dost.gov.ph/volcanoes-of-the-philippines/', $links->item(0)->getAttribute('href'));
        $this->assertStringContainsString('Read the official record from PHIVOLCS', $links->item(0)->textContent);
        $this->assertSame('noopener noreferrer', $links->item(0)->getAttribute('rel'));
        $this->assertSame('FALSE', $detection->fresh()->fact_check_result['verdict']);
        $this->assertSame($snapshot, $detection->fresh()->getRawOriginal());
        Http::assertNothingSent();
    }

    public function test_single_sentence_correction_replaces_raw_tables_bulletins_and_irrelevant_date_padding(): void
    {
        Http::preventStrayRequests();
        $this->mock(GoogleFactCheckFeedService::class)->shouldNotReceive('latest');
        $user = User::factory()->create();
        $claim = 'Taal Volcano is located in Albay province.';
        $explanation = "According to PHIVOLCS' volcano list, Taal is in Batangas, not Albay province.";
        $row = ['claim_id' => 'c1', 'source_id' => 's1', 'stance' => 'contradicting', 'basis' => 'primary_record',
            'excerpt' => '24 Taal 14.01024 120.99812 Batangas in Luzon', 'explanation' => $explanation,
            'matches' => array_fill_keys(['event', 'entities', 'action', 'date', 'location', 'quantities', 'qualifiers'], true)];
        $bulletin = str_repeat('ALERT LEVEL monitoring data and volcano observations. ', 25);
        $extra = array_replace($row, ['source_id' => 's2', 'excerpt' => $bulletin, 'explanation' => 'The source discusses monitoring data.']);
        $result = ['schema_version' => 4, 'primary_task' => 'claim', 'verdict' => 'FALSE', 'claim' => $claim,
            'summary' => $explanation, 'claims' => [['id' => 'c1', 'kind' => 'factual', 'text' => $claim,
                'outcome' => 'refuted', 'relationships' => [$row, $extra]]],
            'sources' => [
                ['id' => 's1', 'name' => 'PHIVOLCS', 'title' => 'Volcanoes of the Philippines', 'type' => 'official_government',
                    'url' => 'https://www.phivolcs.dost.gov.ph/volcanoes-of-the-philippines/', 'relationships' => [$row]],
                ['id' => 's2', 'name' => 'PHIVOLCS', 'title' => 'Volcano activity bulletin', 'type' => 'official_government',
                    'url' => 'https://www.phivolcs.dost.gov.ph/bulletin/example', 'relationships' => [$extra]],
            ],
            'answer_validation' => ['status' => 'rebuilt_from_validated_passages'],
            'interpretation' => ['ambiguities' => ['The submission does not state when the claim or post appeared.']],
            'generated_wording' => ['version' => \App\Services\Detections\ResultWording::VERSION, 'verdict' => 'FALSE',
                'source_ids' => ['s1', 's2'], 'explanation' => 'According to PHIVOLCS, “'.$row['excerpt'].'” '.$bulletin,
                'result_summary' => 'The claim conflicts with the reviewed location record.', 'recommended_action' => 'Read the original record.']];
        $record = Detection::create(['user_id' => $user->id, 'source_kind' => 'text', 'media_type' => 'text',
            'processing_status' => 'completed', 'verdict' => 'fake', 'fake_score' => 100, 'fact_check_result' => $result]);
        $before = $record->fresh()->getRawOriginal();
        $html = $this->actingAs($user)->get(route('detections.result', $record))->assertOk()->getContent();
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($dom);
        $paragraph = $xpath->query('//div[contains(@class,"tg-verdict-explanation")]/p')->item(0);
        $this->assertSame($explanation, trim($paragraph->textContent));
        $this->assertStringNotContainsString('ALERT LEVEL', $paragraph->textContent);
        $this->assertStringNotContainsString('original post date', $paragraph->textContent);
        $this->assertStringContainsString('Read the official record from PHIVOLCS', $html);
        $this->assertSame($before, $record->fresh()->getRawOriginal());
        Http::assertNothingSent();
    }
}
