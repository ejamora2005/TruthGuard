<?php

namespace Tests\Unit;

use App\Services\Detections\AnswerEvidenceValidator;
use App\Services\Detections\Evidence\SubmittedText;
use App\Services\Detections\EvidenceSummary;
use App\Services\Detections\ReportAnswer;
use App\Services\Detections\ResultWording;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class AnswerEvidenceValidatorTest extends TestCase
{
    private const PASSAGE = 'The procurement record lists six rescue vehicles for PHP 4.5 million.';

    private function reportFixture(string $passage = self::PASSAGE): array
    {
        return ['primary_task' => 'claim', 'verdict' => 'TRUE',
            'claim' => 'The procurement record lists six rescue vehicles.',
            'summary' => 'The reviewed record supports the procurement statement.',
            'sources' => [['id' => 's1', 'name' => 'Municipal record', 'url' => 'https://example.gov.ph/award',
                'relationships' => [['claim_id' => 'c1', 'stance' => 'supporting', 'excerpt' => $passage,
                    'explanation' => 'The procurement record lists six rescue vehicles.']]]],
            'claims' => [['id' => 'c1', 'text' => 'The procurement record lists six rescue vehicles.',
                'kind' => 'factual', 'outcome' => 'supported']]];
    }

    private function candidate(string $statement = self::PASSAGE, string $excerpt = self::PASSAGE): array
    {
        return ['verdict' => 'TRUE', 'explanation' => $statement,
            'result_summary' => 'The record supports the stated procurement.',
            'recommended_action' => 'Read the original procurement record.', 'source_ids' => ['s1'],
            'statement_evidence' => [['statement' => $statement, 'claim_id' => 'c1', 'source_id' => 's1', 'excerpt' => $excerpt]]];
    }

    public function test_a_grounded_answer_survives_normalization_and_saved_validation(): void
    {
        Http::preventStrayRequests();
        $attached = ResultWording::attach($this->reportFixture(), [$this->candidate()]);
        $this->assertSame('passed_bounded_checks', $attached['answer_validation']['status']);
        $normalized = ReportAnswer::complete($attached);
        $this->assertSame('c1', $normalized['sources'][0]['relationships'][0]['claim_id']);
        $this->assertNotNull(ResultWording::saved($normalized));
        $this->assertSame('TRUE', $normalized['verdict']);
        Http::assertNothingSent();
    }

    public function test_valid_source_ids_alone_do_not_validate_generated_factual_statements(): void
    {
        $candidate = $this->candidate();
        unset($candidate['statement_evidence']);
        $attached = ResultWording::attach($this->reportFixture(), [$candidate]);
        $this->assertSame('rejected', $attached['answer_validation']['status']);
        $this->assertArrayNotHasKey('generated_wording', $attached);
        $this->assertSame('TRUE', $attached['verdict']);
    }

    public function test_an_invented_per_unit_price_is_removed_without_changing_the_verdict(): void
    {
        Http::preventStrayRequests();
        $candidate = $this->candidate('The procurement record lists each rescue vehicle for PHP 500000.');
        $attached = ResultWording::attach($this->reportFixture(), [$candidate]);
        $this->assertContains('unsupported_number_or_unit', $attached['answer_validation']['issues']);
        $safe = ResultWording::ensure($attached);
        $this->assertStringNotContainsString('500000', $safe['generated_wording']['explanation']);
        $this->assertStringContainsString(self::PASSAGE, $safe['generated_wording']['explanation']);
        $this->assertSame('TRUE', $safe['verdict']);
        $this->assertSame('rebuilt_from_validated_passages', $safe['answer_validation']['status']);
        $this->assertNotNull(ResultWording::saved($safe));
        Http::assertNothingSent();
    }

    public function test_misattributed_quotes_and_added_uncited_sentences_are_rejected(): void
    {
        $candidate = $this->candidate();
        $candidate['statement_evidence'][0]['source_id'] = 's2';
        $this->assertContains('unvalidated_or_misattributed_passage', AnswerEvidenceValidator::review($this->reportFixture(), $candidate)['issues']);
        $candidate = $this->candidate();
        $candidate['explanation'] .= ' The mayor owns the supplier.';
        $this->assertContains('incomplete_statement_coverage', AnswerEvidenceValidator::review($this->reportFixture(), $candidate)['issues']);
        $candidate = $this->candidate('The procurement record states “six luxury cars were delivered”.');
        $this->assertContains('unfaithful_quotation', AnswerEvidenceValidator::review($this->reportFixture(), $candidate)['issues']);
    }

    public function test_currency_magnitude_units_and_estimates_cannot_be_changed(): void
    {
        foreach (['The procurement record lists six rescue vehicles for USD 4.5 million.',
            'The procurement record lists six rescue vehicles for PHP 4.5 billion.'] as $statement) {
            $this->assertContains('unsupported_number_or_unit', AnswerEvidenceValidator::review($this->reportFixture(), $this->candidate($statement))['issues']);
        }
        $estimate = 'The estimated price for each rescue vehicle is PHP 500000.';
        $statement = 'The price for each rescue vehicle is PHP 500000.';
        $this->assertContains('estimate_presented_as_established_value', AnswerEvidenceValidator::review(
            $this->reportFixture($estimate), $this->candidate($statement, $estimate))['issues']);
        $capacity = 'The rescue truck has a 750 gallons capacity.';
        $this->assertContains('unsupported_number_or_unit', AnswerEvidenceValidator::review(
            $this->reportFixture($capacity), $this->candidate('The rescue truck has a 750 liters capacity.', $capacity))['issues']);
    }

    public function test_reversed_negation_and_unrelated_statements_are_rejected(): void
    {
        $negative = 'The procurement record does not list the rescue vehicles.';
        $candidate = $this->candidate('The procurement record does list the rescue vehicles.', $negative);
        $this->assertContains('possible_reversed_negation', AnswerEvidenceValidator::review($this->reportFixture($negative), $candidate)['issues']);
        $this->assertContains('statement_not_anchored_to_passage', AnswerEvidenceValidator::review(
            $this->reportFixture(), $this->candidate('The hospital discovered a cure for diabetes.'))['issues']);
    }

    public function test_a_generated_short_summary_cannot_introduce_an_unchecked_price(): void
    {
        $candidate = $this->candidate();
        $candidate['result_summary'] = 'Each vehicle cost PHP 500000.';
        $candidate['recommended_action'] = 'Tell others the price was PHP 500000.';
        $attached = ResultWording::attach($this->reportFixture(), [$candidate]);
        $this->assertStringNotContainsString('500000', $attached['generated_wording']['result_summary']);
        $this->assertStringNotContainsString('500000', $attached['generated_wording']['recommended_action']);
        $candidate['recommended_action'] = 'Share the mayor\'s conviction with the community.';
        $attached = ResultWording::attach($this->reportFixture(), [$candidate]);
        $this->assertStringNotContainsString('conviction', $attached['generated_wording']['recommended_action']);
    }

    public function test_conflict_fallback_preserves_both_evidence_directions(): void
    {
        $result = $this->reportFixture();
        $result['verdict'] = 'CONFLICTING_EVIDENCE';
        $result['sources'][] = ['id' => 's2', 'name' => 'Second record', 'url' => 'https://example.gov.ph/correction',
            'relationships' => [['claim_id' => 'c1', 'stance' => 'contradicting',
                'excerpt' => 'The signed contract lists four rescue vehicles, not six.']]];
        $fallback = AnswerEvidenceValidator::fallback($result);
        $this->assertSame(['s1', 's2'], $fallback['source_ids']);
        $this->assertStringContainsString('four rescue vehicles', $fallback['explanation']);
        $this->assertStringContainsString('six rescue vehicles', $fallback['explanation']);
    }

    public function test_saved_bindings_cannot_survive_changed_source_passages(): void
    {
        $result = ResultWording::attach($this->reportFixture(), [$this->candidate()]);
        $result['sources'][0]['relationships'][0]['excerpt'] = 'A different procurement record concerns medical supplies.';
        $this->assertNull(ResultWording::saved($result));
    }

    public function test_a_publisher_hostname_does_not_discard_its_verified_quote(): void
    {
        $result = $this->reportFixture();
        $result['sources'][0]['name'] = 'www.example.gov.ph';
        $copy = ResultWording::ensure($result)['generated_wording'];
        $this->assertStringContainsString(self::PASSAGE, $copy['explanation']);
        $this->assertStringStartsWith('example.gov.ph states:', $copy['explanation']);
        $this->assertNotNull(ResultWording::saved(ResultWording::ensure($result)));
    }

    public function test_a_short_saved_fallback_gains_scope_without_new_facts_or_requests(): void
    {
        Http::preventStrayRequests();
        $result = $this->reportFixture('The agency said on January 14 that it had no official record of the reported departure.');
        $result['verdict'] = 'FALSE';
        $result['claim'] = 'A person was spotted at an airport.';
        $result['sources'][0]['relationships'][0]['stance'] = 'contradicting';
        $result['extraction']['input_scope'] = 'visible image text and supplied caption';
        $result['interpretation']['ambiguities'] = ['The submission does not state when the claim or post appeared.'];
        $result['answer_validation'] = ['status' => 'rebuilt_from_validated_passages'];
        $result['generated_wording'] = ['version' => 'result-wording-v11', 'verdict' => 'FALSE',
            'explanation' => 'Municipal record states: “'.$result['sources'][0]['relationships'][0]['excerpt'].'”',
            'source_ids' => ['s1'], 'result_summary' => 'The cited source addresses the claim.',
            'recommended_action' => 'Read the dated source.'];
        $before = $result;
        $copy = ResultWording::saved($result);
        $this->assertGreaterThanOrEqual(100, Str::wordCount($copy['explanation']));
        $this->assertStringContainsString('does not prove that an event never occurred', $copy['explanation']);
        $this->assertStringContainsString('does not give the original post date', $copy['explanation']);
        $this->assertStringContainsString('does not independently determine whether the media was generated', $copy['explanation']);
        $this->assertSame(['s1'], $copy['source_ids']);
        $this->assertSame($before, $result);
        Http::assertNothingSent();
    }

    public function test_fallback_keeps_distinct_admitted_details_and_skips_duplicate_passages(): void
    {
        $result = $this->reportFixture();
        $row = $result['sources'][0]['relationships'][0];
        $result['sources'][0]['relationships'][] = $row;
        $result['sources'][0]['relationships'][] = array_replace($row, ['excerpt' => 'The award record identifies the municipal government as the purchaser.']);
        $copy = AnswerEvidenceValidator::fallback($result);
        $this->assertSame(1, substr_count($copy['explanation'], self::PASSAGE));
        $this->assertStringContainsString('municipal government as the purchaser', $copy['explanation']);
        $this->assertSame(['s1'], $copy['source_ids']);
    }

    private function locationFixture(): array
    {
        $row = ['claim_id' => 'c1', 'source_id' => 's1', 'stance' => 'contradicting', 'basis' => 'primary_record',
            'excerpt' => '18Mayon13.25519123.68615Albay, Bicol Region in Luzon',
            'explanation' => "The PHIVOLCS volcano list places Mayon in Albay, Bicol Region, not in Batangas. This directly contradicts the claim about Mayon Volcano's location.",
            'matches' => array_fill_keys(['event', 'entities', 'action', 'date', 'location', 'quantities', 'qualifiers'], true)];

        return ['schema_version' => 4, 'primary_task' => 'claim', 'verdict' => 'FALSE',
            'claim' => 'Ang Bulkang Mayon ay matatagpuan sa Batangas.', 'summary' => $row['explanation'],
            'claims' => [['id' => 'c1', 'text' => 'Ang Bulkang Mayon ay matatagpuan sa Batangas.',
                'kind' => 'factual', 'outcome' => 'refuted', 'relationships' => [$row]]],
            'sources' => [['id' => 's1', 'name' => 'www.phivolcs.dost.gov.ph',
                'title' => 'Volcanoes of the Philippines | PHIVOLCS',
                'url' => 'https://www.phivolcs.dost.gov.ph/volcanoes-of-the-philippines/', 'relationships' => [$row]]]];
    }

    public function test_table_evidence_keeps_the_reviewed_correction_and_reason_instead_of_a_raw_row(): void
    {
        Http::preventStrayRequests();
        $original = ReportAnswer::complete($this->locationFixture());
        $result = ResultWording::ensure($original);
        $text = $result['generated_wording']['explanation'];
        $this->assertStringStartsWith('The PHIVOLCS volcano list places Mayon in Albay', $text);
        $this->assertStringContainsString('not in Batangas', $text);
        $this->assertStringContainsString('directly contradicts', $text);
        $this->assertStringNotContainsString('18Mayon13', $text);
        $this->assertSame(['s1'], $result['generated_wording']['source_ids']);
        $this->assertSame('FALSE', $result['verdict']);
        $this->assertSame($original['sources'], $result['sources']);
        $this->assertSame($original['claims'], $result['claims']);
        $this->assertNotNull(ResultWording::saved($result));
        Http::assertNothingSent();
    }

    public function test_old_extractive_copy_is_rebuilt_locally_without_research(): void
    {
        Http::preventStrayRequests();
        $result = $this->locationFixture();
        $result['generated_wording'] = ['version' => 'result-wording-v10', 'verdict' => 'FALSE',
            'explanation' => 'phivolcs.dost.gov.ph states: “18Mayon13.25519123.68615Albay, Bicol Region in Luzon”',
            'result_summary' => 'The claim conflicts with the strongest available evidence.',
            'recommended_action' => 'Read the source.', 'source_ids' => ['s1']];
        $this->assertStringContainsString('not in Batangas', ResultWording::ensure($result)['generated_wording']['explanation']);
        Http::assertNothingSent();
    }

    public function test_previous_generated_narrative_is_preserved_when_its_statement_bindings_still_pass(): void
    {
        $result = ResultWording::attach($this->reportFixture(), [$this->candidate()]);
        $result['generated_wording']['version'] = 'result-wording-v10';
        $copy = ResultWording::saved(ResultWording::ensure($result));
        $this->assertNotNull($copy);
        $this->assertSame(self::PASSAGE, $copy['explanation']);
        $this->assertSame('c1', $copy['statement_evidence'][0]['claim_id']);
    }

    public function test_reviewed_prose_cannot_add_a_new_number_place_or_unmatched_relationship(): void
    {
        foreach ([['explanation' => 'The PHIVOLCS volcano list places Mayon in Manila. This contradicts the claim about its location.'],
            ['explanation' => 'The PHIVOLCS volcano list places Mayon in Albay. Its height is 5000 meters.'],
            ['matches' => ['event' => false]], ['superseded' => true], ['basis' => 'background']] as $change) {
            $result = $this->locationFixture();
            $result['claims'][0]['relationships'][0] = array_replace($result['claims'][0]['relationships'][0], $change);
            $fallback = AnswerEvidenceValidator::fallback($result);
            $this->assertStringContainsString('states:', $fallback['explanation']);
            $this->assertStringNotContainsString('Manila', $fallback['explanation']);
            $this->assertStringNotContainsString('5000', $fallback['explanation']);
        }
    }

    public function test_readable_reviewed_explanations_also_work_for_supported_non_geographic_claims(): void
    {
        $result = $this->reportFixture();
        $row = $result['sources'][0]['relationships'][0];
        $row['source_id'] = 's1';
        $row['basis'] = 'primary_record';
        $row['matches'] = array_fill_keys(['event', 'entities', 'action', 'date', 'location', 'quantities', 'qualifiers'], true);
        $row['explanation'] = 'The procurement record lists six rescue vehicles. This directly supports the statement about the vehicles in the post.';
        $result['sources'][0]['relationships'] = [$row];
        $result['claims'][0]['relationships'] = [$row];
        $copy = ResultWording::ensure($result)['generated_wording'];
        $this->assertStringContainsString('the procurement record lists six rescue vehicles.', $copy['explanation']);
        $this->assertStringContainsString('directly supports', $copy['explanation']);
        $this->assertStringNotContainsString('states:', $copy['explanation']);
        $this->assertSame(['s1'], $copy['source_ids']);
    }

    public function test_a_fuller_six_sentence_explanation_keeps_its_citations_and_final_qualification_without_another_call(): void
    {
        Http::preventStrayRequests();
        $sentences = [
            'The town record states that the public library opened on January 3, 2024, and names the same building and street as the claim in the post, with the location and name both given in the same dated entry.',
            'The entry lists the opening date as the start of public service at the site, with books and reading rooms made available to people who visited.',
            'The same record says that staff were on site that day to help people find books, use the reading rooms and sign up for a card.',
            'The date in the post agrees with the date in this entry, and both refer to the public library rather than another office in the town.',
            'The record covers the opening of the library and the start of its public service, so it supports those parts of the claim about the site.',
            'The entry does not give a date for any later works at the building, so it does not by itself show when those works began or ended.',
        ];
        $text = implode(' ', $sentences);
        $this->assertGreaterThan(160, Str::wordCount($text));
        $row = ['claim_id' => 'c1', 'source_id' => 's1', 'stance' => 'supporting', 'basis' => 'primary_record',
            'excerpt' => $text, 'explanation' => $text,
            'matches' => array_fill_keys(['event', 'entities', 'action', 'date', 'location', 'quantities', 'qualifiers'], true)];
        $result = ['schema_version' => 4, 'primary_task' => 'claim', 'verdict' => 'TRUE',
            'claim' => 'The public library opened on January 3, 2024.', 'summary' => $text,
            'claims' => [['id' => 'c1', 'text' => 'The public library opened on January 3, 2024.', 'kind' => 'factual',
                'outcome' => 'supported', 'relationships' => [$row]]],
            'sources' => [['id' => 's1', 'name' => 'Municipal records', 'title' => 'Public library opening record',
                'url' => 'https://example.gov.ph/library', 'relationships' => [$row]]]];
        $candidate = ['verdict' => 'TRUE', 'explanation' => $text, 'result_summary' => 'The record supports the opening date.',
            'recommended_action' => 'Read the original record.', 'source_ids' => ['s1'],
            'statement_evidence' => array_map(fn ($sentence) => ['statement' => $sentence, 'claim_id' => 'c1',
                'source_id' => 's1', 'excerpt' => $sentence], $sentences)];
        $attached = ResultWording::attach($result, [$candidate]);
        $this->assertSame('passed_bounded_checks', $attached['answer_validation']['status']);
        $copy = ResultWording::saved(ReportAnswer::complete($attached));
        $this->assertNotNull($copy);
        $this->assertSame($text, $copy['explanation']);
        $this->assertCount(6, SubmittedText::sentences($copy['explanation']));
        $this->assertSame(['s1'], $copy['source_ids']);
        $this->assertStringContainsString($sentences[5], EvidenceSummary::segments($result)[0]['text']);
        $this->assertStringContainsString($sentences[5], AnswerEvidenceValidator::fallback($result)['explanation']);
        Http::assertNothingSent();
    }
}
