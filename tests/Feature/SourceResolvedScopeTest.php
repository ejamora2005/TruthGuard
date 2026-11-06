<?php

namespace Tests\Feature;

use App\Services\Detections\Evidence\ArticleSearch;
use App\Services\Detections\Evidence\ClaimPlanner;
use App\Services\Detections\Evidence\EvidenceJudge;
use App\Services\Detections\Evidence\StructuredAnalysis;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SourceResolvedScopeTest extends TestCase
{
    private const HEADING = 'XYZ CLOSED?!';

    private const OTHER = 'KARMA AND A COURT RULING?!';

    // Synthetic sources exercise grounding; these are not publisher quotations.
    private const SCOPE = 'The viral thumbnail says "XYZ CLOSED?! KARMA AND A COURT RULING?!" and identifies the Xylophone Youth Zone.';

    private const FINDING = 'The Xylophone Youth Zone continues operating. No court ordered its closure in this event.';

    public static function variants(): array
    {
        return [['same_post', 'refuted'], ['heading_only', 'needs_context'],
            ['unrelated_post', 'needs_context'], ['fabricated_excerpt', 'needs_context'],
            ['missing_date', 'needs_context'], ['other_rating', 'insufficient'],
            ['wrong_event_date', 'insufficient'], ['photo_identity', 'needs_context'],
            ['already_named_subject', 'needs_context'], ['malformed_resolution', 'needs_context'],
            ['conflicting_subjects', 'needs_context'], ['malformed_envelope', 'needs_context'],
            ['ocr_fragment', 'refuted'], ['short_fragment', 'needs_context'], ['unclear_topic', 'needs_context']];
    }

    #[DataProvider('variants')]
    public function test_only_grounded_identity_resolution_can_unlock_an_otherwise_valid_relationship(string $variant, string $outcome): void
    {
        $claim = ['id' => 'c1', 'text' => self::HEADING, 'kind' => 'factual', 'requires_context' => true,
            'context_reason' => 'The acronym XYZ does not identify a specific organization.',
            'submission_context' => self::HEADING."\n\n".self::OTHER, 'searches' => ['XYZ court closure']];
        $source = ['id' => 's1', 'url' => 'https://www.rappler.com/newsbreak/fact-check/synthetic-xyz-case/',
            'title' => 'Synthetic review', 'name' => 'Synthetic publisher', 'source_type' => 'fact_check',
            'retrieval_status' => 'retrieved', 'text' => self::SCOPE.' '.self::FINDING,
            'published_at' => '2026-11-01T00:00:00Z', 'reviewed_claim' => 'A different mayor resigned in 2023.'];
        $resolution = ['claim_id' => 'c1', 'source_id' => 's1', 'resolved_subject' => 'Xylophone Youth Zone',
            'excerpts' => [self::SCOPE], 'matched_submission_quotes' => [self::HEADING, self::OTHER]];
        $relationship = ['claim_id' => 'c1', 'source_id' => 's1', 'stance' => 'contradicting',
            'basis' => 'direct_reporting', 'excerpt' => self::FINDING, 'explanation' => self::FINDING,
            'matches' => array_fill_keys(['event', 'entities', 'action', 'date', 'location', 'quantities', 'qualifiers'], true),
            'supersedes_source_ids' => [], 'supersession_excerpt' => ''];
        $request = [];
        if ($variant === 'heading_only') {
            $resolution['matched_submission_quotes'] = [self::HEADING];
        }
        if ($variant === 'unrelated_post') {
            $source['text'] = str_replace(self::OTHER, 'A different event', $source['text']);
        }
        if ($variant === 'fabricated_excerpt') {
            $resolution['excerpts'] = ['Invented article wording about the Xylophone Youth Zone.'];
        }
        if ($variant === 'missing_date') {
            $claim['context_reason'] = 'The organization and date are not established.';
        }
        if ($variant === 'other_rating') {
            $relationship['basis'] = 'fact_check';
        }
        if ($variant === 'wrong_event_date') {
            $relationship['matches']['date'] = false;
        }
        if ($variant === 'photo_identity') {
            $request = ['media_attached' => true, 'question' => 'Is this pictured man the mayor?'];
        }
        if ($variant === 'already_named_subject') {
            $resolution['resolved_subject'] = 'XYZ';
        }
        if ($variant === 'malformed_resolution') {
            $resolution = 'Not a resolution object';
        }
        if (in_array($variant, ['ocr_fragment', 'short_fragment', 'unclear_topic'], true)) {
            $claim['text'] = 'TYPO KARMA AND A COURT RULING?!';
            $claim['kind'] = 'unclear';
            $claim['context_reason'] = 'No subject has been identified.';
            $claim['submission_context'] = self::HEADING."\n\n".$claim['text'];
            $resolution['matched_submission_quotes'] = [self::HEADING,
                $variant === 'short_fragment' ? 'COURT RULING' : 'A COURT RULING'];
            if ($variant === 'unclear_topic') {
                $claim['text'] = str_replace('RULING', 'STORY', $claim['text']);
                $claim['submission_context'] = str_replace('RULING', 'STORY', $claim['submission_context']);
                $resolution['matched_submission_quotes'][1] = 'A COURT STORY';
                $source['text'] = str_replace('RULING', 'STORY', $source['text']);
                $resolution['excerpts'] = [str_replace('RULING', 'STORY', self::SCOPE)];
            }
        }
        $sources = [$source];
        $resolutions = [$resolution];
        if ($variant === 'conflicting_subjects') {
            $second = array_replace($source, ['id' => 's2',
                'text' => str_replace('Xylophone Youth Zone', 'Xylophone Yacht Zone', $source['text'])]);
            $sources[] = $second;
            $resolutions[] = array_replace($resolution, ['source_id' => 's2',
                'resolved_subject' => 'Xylophone Yacht Zone',
                'excerpts' => [str_replace('Xylophone Youth Zone', 'Xylophone Yacht Zone', self::SCOPE)]]);
        }
        $this->mock(ArticleSearch::class)->shouldReceive('isGenericLandingPage')->andReturn(false);
        $this->mock(StructuredAnalysis::class)->shouldReceive('ask')->once()->andReturn([
            'scope_resolutions' => $variant === 'malformed_envelope' ? 'Invalid envelope' : $resolutions,
            'relationships' => [$relationship], 'context_findings' => [], 'wording' => [],
        ]);
        $judge = app(EvidenceJudge::class);
        $rows = $judge->assess([$claim], $sources, '2026-11-06T00:00:00Z', 0, $request);
        $result = $judge->outcomes([$claim], $rows)[0];
        $this->assertSame($outcome, $result['outcome']);
        $this->assertSame($claim['text'], $result['text']);
        if ($outcome === 'refuted') {
            $this->assertSame('Xylophone Youth Zone', $result['scope_resolution']['resolved_subject']);
            $this->assertFalse($result['requires_context']);
            $judge->reset();
            $this->assertSame('needs_context', $judge->outcomes([$claim], $rows)[0]['outcome']);
        }
    }

    public function test_subjectless_subtitles_and_implied_farewells_do_not_become_extra_factual_assertions(): void
    {
        $rows = [];
        foreach ([self::HEADING, self::OTHER, 'GOODBYE ANNA?!'] as $index => $text) {
            $rows[] = ['quote' => $text, 'kind' => $index === 1 ? 'unclear' : 'factual',
                'requires_context' => $index !== 2, 'context_reason' => match ($index) {
                    0 => 'The acronym XYZ does not identify a specific organization.',
                    1 => 'No subject, court or case is identified.',
                    2 => 'The phrase implies a departure but does not specify from what role.',
                }, 'entities' => $index === 2 ? ['ANNA'] : [], 'dates' => [], 'locations' => [], 'quantities' => [], 'qualifiers' => [],
                'searches' => ['XYZ court closure'], 'references' => []];
        }
        $this->mock(StructuredAnalysis::class)->shouldReceive('ask')->once()->andReturn([
            'claims' => $rows, 'date_quote' => '', 'original_claim_date' => null,
        ]);
        $plan = app(ClaimPlanner::class)->plan(implode("\n\n", array_column($rows, 'quote')));
        $this->assertCount(1, $plan['claims']);
        $this->assertSame(self::HEADING, $plan['claims'][0]['text']);
        $this->assertTrue($plan['claims'][0]['requires_context']);
        $this->assertStringContainsString('GOODBYE ANNA', $plan['claims'][0]['submission_context']);
    }

    public function test_explicit_status_assertions_and_farewells_containing_extra_facts_are_preserved(): void
    {
        $rows = [];
        foreach (['Orchid School closed?!', 'COURT RULING CONFIRMED?!', 'GOODBYE ANNA, RESIGNED?!'] as $index => $text) {
            $rows[] = ['quote' => $text, 'kind' => $index === 1 ? 'unclear' : 'factual',
                'requires_context' => true, 'context_reason' => $index === 1
                    ? 'No subject, court or case is identified.' : 'The phrase implies an action but the identity is ambiguous.',
                'entities' => $index === 2 ? ['ANNA'] : [], 'dates' => [], 'locations' => [], 'quantities' => [],
                'qualifiers' => [], 'searches' => ['official event records'], 'references' => []];
        }
        $this->mock(StructuredAnalysis::class)->shouldReceive('ask')->once()->andReturn([
            'claims' => $rows, 'date_quote' => '', 'original_claim_date' => null,
        ]);
        $plan = app(ClaimPlanner::class)->plan(implode("\n\n", array_column($rows, 'quote')));
        $this->assertSame(array_column($rows, 'quote'), array_column($plan['claims'], 'text'));
        $this->assertSame('unclear', $plan['claims'][1]['kind']);
        $this->assertSame('factual', $plan['claims'][2]['kind']);
    }

    public static function captureRequests(): array
    {
        return [[false, false, false], [true, false, false], [false, true, false], [false, false, true]];
    }

    #[DataProvider('captureRequests')]
    public function test_quoted_post_identity_is_resolved_before_review_without_authenticating_a_photograph(bool $photoIdentity, bool $distantPhrase, bool $untrustedPublisher): void
    {
        $text = "XYZ CLOSED?!\n\nZKARMA AND A COURT RULING?!";
        $claims = [];
        foreach (explode("\n\n", $text) as $index => $quote) {
            $claims[] = ['id' => 'c'.($index + 1), 'text' => $quote,
                'kind' => $index === 0 ? 'factual' : 'unclear', 'requires_context' => true,
                'context_reason' => $index === 0 ? 'The acronym XYZ has an ambiguous identity.' : 'No subject is identified.',
                'submission_context' => $text, 'searches' => ['XYZ court closure']];
        }
        $scope = str_replace('Xylophone Youth Zone', 'XYZ Youth Zone', self::SCOPE);
        $finding = str_replace('Xylophone Youth Zone', 'XYZ Youth Zone', self::FINDING);
        $source = ['id' => 's1', 'url' => 'https://www.rappler.com/newsbreak/fact-check/synthetic-xyz-case/',
            'title' => 'Synthetic review', 'source_type' => 'fact_check', 'name' => 'Synthetic publisher',
            'retrieval_status' => 'retrieved', 'text' => $scope.' '.$finding,
            'reviewed_claim' => 'XYZ Youth Zone was ordered to close.', 'published_at' => '2026-11-01T00:00:00Z'];
        if ($distantPhrase) {
            $source['text'] = 'A thumbnail says "XYZ CLOSED?!" about XYZ Youth Zone. '
                .str_repeat('Separate unrelated background details. ', 20)
                .'A different story says "KARMA AND A COURT RULING?!". '.$finding;
        }
        if ($untrustedPublisher) {
            $source['url'] = 'https://untrusted.example.org/fact-check/synthetic-post';
        }
        $blocked = $photoIdentity || $distantPhrase || $untrustedPublisher;
        $rows = array_map(fn ($claim) => ['claim_id' => $claim['id'], 'source_id' => 's1', 'stance' => 'contradicting',
            'basis' => 'direct_reporting', 'excerpt' => $finding, 'explanation' => $finding,
            'matches' => array_fill_keys(['event', 'entities', 'action', 'date', 'location', 'quantities', 'qualifiers'], true),
            'supersedes_source_ids' => [], 'supersession_excerpt' => ''], $claims);
        $this->mock(ArticleSearch::class)->shouldReceive('isGenericLandingPage')->andReturn(false);
        $this->mock(StructuredAnalysis::class)->shouldReceive('ask')->once()->withArgs(function ($stage, $instructions, $input) use ($blocked) {
            $this->assertSame([$blocked, $blocked], array_column($input['claims'], 'requires_context'));
            $this->assertSame(['factual', $blocked ? 'unclear' : 'factual'], array_column($input['claims'], 'kind'));
            $this->assertStringContainsString('ZKARMA', $input['claims'][1]['text']);

            return true;
        })->andReturn(['scope_resolutions' => [], 'relationships' => $rows, 'context_findings' => [], 'wording' => []]);
        $judge = app(EvidenceJudge::class);
        $request = $photoIdentity ? ['media_attached' => true, 'question' => 'Who is this pictured man?'] : [];
        $relationships = $judge->assess($claims, [$source], '2026-11-06T00:00:00Z', 0, $request);
        $expected = $blocked ? 'needs_context' : 'refuted';
        $this->assertSame([$expected, $expected], array_column($judge->outcomes($claims, $relationships), 'outcome'));
        $this->assertCount($blocked ? 0 : 2, $judge->scopeResolutions);
    }
}
