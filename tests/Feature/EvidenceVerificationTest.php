<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeDetectionEvidence;
use App\Models\Detection;
use App\Models\User;
use App\Services\Detections\DetectionPipeline;
use App\Services\Detections\Evidence\ArticleSearch;
use App\Services\Detections\Evidence\EvidenceJudge;
use App\Services\Detections\Evidence\RetrievalBudget;
use App\Services\Detections\Evidence\SafeArticleFetcher;
use App\Services\Detections\EvidenceVerificationService;
use App\Services\Detections\FilipinoNewsClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EvidenceVerificationTest extends TestCase
{
    use RefreshDatabase;

    private array $fixture;

    private string $url = 'https://court.gov.ph/docket/410';

    public static function freshVerdictCases(): array
    {
        // Fictional software fixtures, unrelated to any saved user report.
        return [
            ['factual', 'supporting', false, 'TRUE'],
            ['factual', 'contradicting', false, 'FALSE'],
            ['factual', 'insufficient', false, 'UNVERIFIED'],
            ['factual', 'insufficient', true, 'MISSING_CONTEXT'],
            ['opinion', 'insufficient', false, 'OPINION_NOT_FACT_CHECKABLE'],
        ];
    }

    #[DataProvider('freshVerdictCases')]
    public function test_fresh_factual_and_nonfactual_inputs_use_the_evidence_taxonomy(string $kind, string $stance, bool $missing, string $expected): void
    {
        $claim = 'The demonstration library opened on March 4, 2024.';
        $this->fake($claim, $kind, 'The demonstration library opened on March 4, 2024. This is a fictional software fixture.', $stance, $missing);
        $result = $this->runAnalysis($claim);
        $this->assertSame($expected, $result['verdict']);
        $this->assertSame($kind === 'opinion' || $missing ? 'context' : 'factual', $result['assessment_group']);
        $this->assertNotContains($result['verdict'], ['AI_GENERATED', 'LIKELY_AI_GENERATED', 'LIKELY_NOT_AI_GENERATED']);
        $this->assertNotSame('confidence', $result['score']['type']);
    }

    public static function freshContextCases(): array
    {
        return [
            ['OUT_OF_CONTEXT', 'The demonstration photograph was captured in Lakeside in 2018, rather than Riverside in 2024.'],
            ['MISLEADING', 'The demonstration headline omits that the increase covers only one department and misrepresents the whole library.'],
            ['SATIRE_PARODY', 'This demonstration library story is explicitly labeled satire and was published as fictional parody.'],
        ];
    }

    #[DataProvider('freshContextCases')]
    public function test_fresh_context_findings_keep_citations_and_the_separate_factual_assessment(string $label, string $passage): void
    {
        $claim = 'The demonstration library opened in Riverside in 2024.';
        $this->fake($claim, 'factual', $passage, 'insufficient', contextOutput: fn ($sources) => [[
            'claim_id' => 'c1', 'source_id' => $sources[0]['id'], 'label' => $label, 'excerpt' => $passage,
            'explanation' => 'The simulated passage establishes the specified context for this exact demonstration claim.',
            'submitted_quote' => $claim, 'exact_subject_match' => true, 'presented_as_current' => false, 'valid_until' => null,
        ]]);
        $result = app(EvidenceVerificationService::class)->apply(['signals' => []], [
            'caption_text' => 'Is this misleading? '.$claim, 'media_type' => 'text', 'evidence_cutoff' => $this->fixture['cutoff'],
        ])['fact_check_result'];
        $this->assertSame($label, $result['verdict']);
        $this->assertSame(['context', 'claim'], $result['completed_tasks']);
        $this->assertSame($label === 'MISLEADING' ? 'MISLEADING' : 'UNVERIFIED', $result['secondary_assessments'][0]['verdict']);
        $this->assertSame([$result['sources'][0]['id']], $result['answer_segments'][0]['source_ids']);
        $this->assertNull($result['score']['value']);
    }

    public function test_misleading_can_be_found_during_an_ordinary_fact_check_without_an_ai_detector(): void
    {
        $claim = 'The demonstration library doubled its staff.';
        $passage = 'The demonstration library increased staff only in its archive; describing all staff as doubled is misleading.';
        $this->fake($claim, 'factual', $passage, 'insufficient', contextOutput: fn ($sources) => [[
            'claim_id' => 'c1', 'source_id' => $sources[0]['id'], 'label' => 'MISLEADING', 'excerpt' => $passage,
            'explanation' => 'The claim generalizes an increase in one department to the entire fictional library.',
            'submitted_quote' => $claim, 'exact_subject_match' => true, 'presented_as_current' => false, 'valid_until' => null,
        ]]);
        $result = $this->runAnalysis($claim);
        $this->assertSame('claim', $result['primary_task']);
        $this->assertSame('MISLEADING', $result['verdict']);
        $this->assertSame('factual', $result['assessment_group']);
        $this->assertCount(1, $result['sources']);
        $this->assertNull($result['score']['value']);
        $this->assertSame([$result['sources'][0]['id']], $result['answer_segments'][0]['source_ids']);
    }

    public function test_an_unproven_satire_request_does_not_hide_the_opinion_classification(): void
    {
        $claim = 'I think the demonstration library is the most beautiful building.';
        $this->fake($claim, 'opinion', 'The demonstration library appears in this fictional software fixture.', 'insufficient');
        $result = app(EvidenceVerificationService::class)->apply(['signals' => []], [
            'caption_text' => 'Is this satire? '.$claim, 'media_type' => 'text', 'evidence_cutoff' => $this->fixture['cutoff'],
        ])['fact_check_result'];
        $this->assertSame('context', $result['primary_task']);
        $this->assertSame('UNVERIFIED', $result['verdict']);
        $this->assertSame('OPINION_NOT_FACT_CHECKABLE', $result['secondary_assessments'][0]['verdict']);
    }

    public function test_a_current_status_question_checks_subsequent_records_even_after_first_round_coverage(): void
    {
        $target = 'the library reopening postponed';
        $old = 'The demonstration library reopening will proceed on October 10, 2026.';
        $new = 'The September 28 order postpones the demonstration library reopening to November 10, 2026 and explicitly supersedes the September 1 schedule.';
        $record = fn ($id, $text, $date) => ['id' => $id, 'url' => 'https://example.gov/library/'.$id, 'title' => 'Simulated library schedule',
            'name' => 'Demonstration authority', 'source_type' => 'official_government', 'retrieval_status' => 'retrieved',
            'text' => $text, 'published_at' => $date, 'updated_at' => null, 'retrieved_at' => $this->fixture['cutoff'],
            'text_truncated' => false, 'content_type' => 'text/html'];
        $initial = ['s-old' => $record('s-old', $old, '2026-09-01')];
        $later = $initial + ['s-new' => $record('s-new', $new, '2026-09-28')];
        $this->fake($target, 'factual', '', judgeOutput: function ($sources) use ($new) {
            return array_map(function ($source) use ($new) {
                $row = $this->relationship('c1', $source['id'], $source['id'] === 's-old' ? 'contradicting' : 'supporting', $source['text']);
                if ($source['id'] === 's-new') {
                    $row['supersedes_source_ids'] = ['s-old'];
                    $row['supersession_excerpt'] = $new;
                }

                return $row;
            }, $sources);
        });
        $this->mock(ArticleSearch::class, function ($mock) use ($initial, $later) {
            $mock->shouldReceive('retrieve')->once()->withArgs(fn ($queries, $budget, $cutoff, $records, $reserve, $ceiling) => $reserve === true && $ceiling === 20)->andReturn($initial);
            $mock->shouldReceive('retrieve')->once()->withArgs(fn ($queries, $budget, $cutoff, $records, $reserve, $ceiling) => $reserve === false && $ceiling === null)->andReturn($later);
            $mock->shouldReceive('isGenericLandingPage')->andReturn(false);
        });
        $result = app(EvidenceVerificationService::class)->apply(['signals' => []], [
            'caption_text' => 'Was the library reopening postponed?', 'media_type' => 'text', 'evidence_cutoff' => $this->fixture['cutoff'],
        ])['fact_check_result'];
        $this->assertSame('TRUE', $result['verdict'], json_encode(['claims' => $result['claims'], 'stages' => $result['diagnostics']['model_stages'] ?? []]));
        $this->assertStringStartsWith('Yes.', $result['summary']);
        $this->assertSame('supported', $result['claims'][0]['outcome']);
        $this->assertTrue($result['claims'][0]['relationships'][0]['superseded']);
    }

    public function test_a_context_question_selects_the_relevant_validated_finding_and_keeps_other_findings(): void
    {
        $claim = 'The demonstration video shows a live 2024 event.';
        $passage = 'The demonstration video is a 2010 satirical clip, recaptioned as live 2024 coverage of a different event.';
        $this->fake($claim, 'factual', $passage, 'contradicting', contextOutput: fn ($sources) => array_map(fn ($label) => [
            'claim_id' => 'c1', 'source_id' => $sources[0]['id'], 'label' => $label, 'excerpt' => $passage,
            'explanation' => 'The simulated record establishes this particular contextual finding: '.$label.'.',
            'submitted_quote' => $claim, 'exact_subject_match' => true, 'presented_as_current' => false, 'valid_until' => null,
        ], ['SATIRE_PARODY', 'OUT_OF_CONTEXT']));
        $result = app(EvidenceVerificationService::class)->apply(['signals' => []], [
            'caption_text' => 'Is this out of context? '.$claim, 'media_type' => 'text', 'evidence_cutoff' => $this->fixture['cutoff'],
        ])['fact_check_result'];
        $this->assertSame('OUT_OF_CONTEXT', $result['verdict']);
        $this->assertSame('FALSE', $result['secondary_assessments'][0]['verdict']);
        $this->assertSame(['SATIRE_PARODY', 'OUT_OF_CONTEXT'], array_column($result['context_assessments'], 'label'));
    }

    public static function freshMixedAndConflictCases(): array
    {
        return [[false, 'PARTLY_TRUE'], [true, 'CONFLICTING_EVIDENCE']];
    }

    #[DataProvider('freshMixedAndConflictCases')]
    public function test_fresh_multi_claim_and_conflicting_records_are_not_collapsed_into_false(bool $conflict, string $expected): void
    {
        $first = 'The fictional library opened on May 1, 2024.';
        $second = 'The fictional library has 40 reading rooms.';
        $text = $conflict ? $first : $first.' '.$second;
        $claims = [$this->claim($first)];
        if (! $conflict) {
            $claims[] = array_replace($this->claim($second), ['id' => 'c2']);
        }
        $records = [];
        foreach (['s1' => 'The fictional library opened on May 1, 2024. This is simulated evidence.',
            's2' => $conflict ? 'The fictional library remained closed on May 1, 2024. This is simulated evidence.' : 'The fictional library has 20 reading rooms, not 40. This is simulated evidence.'] as $id => $passage) {
            $records[$id] = ['id' => $id, 'url' => 'https://example.gov/library/'.$id, 'title' => 'Simulated library record '.$id,
                'name' => 'Fictional fixture', 'source_type' => 'official_government', 'retrieval_status' => 'retrieved',
                'text' => $passage, 'published_at' => '2024-05-02T00:00:00Z', 'updated_at' => null,
                'retrieved_at' => $this->fixture['cutoff'], 'text_truncated' => false, 'content_type' => 'text/html'];
        }
        $this->fake($text, 'factual', '', claims: $claims, judgeOutput: fn ($sources) => array_map(fn ($source) => $this->relationship($source['id'] === 's1' || $conflict ? 'c1' : 'c2', $source['id'],
            $source['id'] === 's1' ? 'supporting' : 'contradicting', $source['text']), $sources));
        $this->mock(ArticleSearch::class, function ($mock) use ($records) {
            $mock->shouldReceive('retrieve')->andReturn($records);
            $mock->shouldReceive('isGenericLandingPage')->andReturn(false);
        });
        $result = $this->runAnalysis($text);
        $this->assertSame($expected, $result['verdict']);
        $this->assertCount(2, $result['sources']);
        $this->assertSame($conflict, $result['ui']['show_source_conflict']);
        if ($conflict) {
            $this->assertNull($result['score']['value']);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 13)->setTime(9, 0));
        $this->fixture = json_decode(file_get_contents(base_path('tests/Fixtures/evidence/bske-headline.json')), true);
        config()->set(['fact_check.enhanced_enabled' => true, 'fact_check.web_search.enabled' => false,
            'services.openai.key' => 'test-key', 'services.openai.analysis_enabled' => true,
            'services.openai.claim_extraction_enabled' => true, 'services.filipino_news_classifier.enabled' => false,
            'services.deepfake_cnn.enabled' => false, 'services.live_verification.enabled' => true,
            'services.google_fact_check.key' => 'test-key', 'services.gnews.key' => '', 'services.newsapi.key' => '',
            'fact_check.retrieval.google_pages' => 1, 'fact_check.retrieval.max_search_requests' => 4]);
        $this->app->instance(SafeArticleFetcher::class, new class extends SafeArticleFetcher
        {
            protected function resolve(string $host): array
            {
                return ['93.184.216.34'];
            }
        });
        Http::preventStrayRequests();
        // Most fixtures focus on article semantics. The dedicated robots test enables policy checks.
        config()->set('fact_check.retrieval.respect_robots', false);
    }

    public function test_rhetorical_bske_headline_does_not_inherit_an_unrelated_false_rating(): void
    {
        $this->fake($this->fixture['headline'], 'rhetorical', $this->fixture['unrelated_text'], 'contradicting', true);
        $result = $this->runAnalysis($this->fixture['headline']);
        $this->assertSame('MISSING_CONTEXT', $result['verdict']);
        $this->assertSame('Missing Context', $result['assessment_label']);
        $this->assertTrue($result['ui']['show_missing_context']);
        $this->assertSame([], $result['sources']);
        $this->assertNull($result['confidence']);
        Http::assertSent(fn ($r) => data_get($r->data(), 'text.format.name') === 'evidence_relationships_0');
        $this->assertSame([], $result['contextual_sources']);
    }

    public function test_related_keywords_and_a_false_special_election_review_are_not_decisive(): void
    {
        $claim = 'The regular BSKE was postponed by an enacted law.';
        $this->fake($claim, 'factual', $this->fixture['unrelated_text'], 'contradicting', false, ['action' => false, 'event' => false]);
        $result = $this->runAnalysis($claim);
        $this->assertSame('UNVERIFIED', $result['verdict']);
        $this->assertSame([], $result['sources']);
        $this->assertSame('insufficient', $result['claims'][0]['relationships'][0]['stance']);
    }

    public function test_a_legal_challenge_cannot_be_reported_as_a_final_ruling_even_with_a_bad_model_match(): void
    {
        $claim = 'The court ruled that the BSKE postponement is unconstitutional.';
        $this->fake($claim, 'factual', $this->fixture['petition']);
        $result = $this->runAnalysis($claim);
        $this->assertSame('UNVERIFIED', $result['verdict']);
        $this->assertStringContainsString('final court decision', implode(' ', $result['limitations']));
    }

    public function test_one_matching_primary_record_is_sufficient_without_an_article_count_threshold(): void
    {
        $this->fake($this->fixture['primary_claim'], 'factual', $this->fixture['primary_text']);
        $result = $this->runAnalysis($this->fixture['primary_claim']);
        $this->assertSame('TRUE', $result['verdict']);
        $this->assertSame('True', $result['assessment_label']);
        $this->assertSame($this->fixture['primary_text'], $result['sources'][0]['relationships'][0]['excerpt']);
        $this->assertSame(1, $result['diagnostics']['sources_reviewed']);
        $this->assertSame(1, $result['diagnostics']['sources_used']);
        Http::assertSent(fn ($r) => data_get($r->data(), 'text.format.name') === 'evidence_relationships_0' && $r['max_output_tokens'] <= config('fact_check.model_output_tokens'));
    }

    public function test_canonical_reuse_keeps_each_submissions_current_analysis_identifier(): void
    {
        $this->fake($this->fixture['primary_claim'], 'factual', $this->fixture['primary_text']);
        $context = ['caption_text' => $this->fixture['primary_claim'], 'media_type' => 'text',
            'evidence_cutoff' => $this->fixture['cutoff']];
        $first = app(EvidenceVerificationService::class)->apply(['signals' => []], $context + ['analysis_id' => 'first-run'])['fact_check_result'];
        $second = app(EvidenceVerificationService::class)->apply(['signals' => []], $context + ['analysis_id' => 'second-run'])['fact_check_result'];

        $this->assertSame('first-run', $first['diagnostics']['analysis_id']);
        $this->assertSame('second-run', $second['diagnostics']['analysis_id']);
        $this->assertFalse($first['diagnostics']['canonical_cache_hit']);
        $this->assertTrue($second['diagnostics']['canonical_cache_hit']);
        $this->assertSame($first['sources'], $second['sources']);
    }

    public function test_fabricated_source_ids_and_non_matching_excerpts_are_rejected(): void
    {
        $judge = app(EvidenceJudge::class);
        $claim = $this->claim('The council adopted the policy.');
        $source = ['id' => 's1', 'text' => 'The council is considering a proposed policy.', 'published_at' => '2026-10-01T00:00:00Z'];
        $row = $this->relationship('c1', 'invented', 'supporting', $source['text']);
        $this->assertSame([], $judge->validate([$row], [$claim], [$source]));
        $row['source_id'] = 's1';
        $row['excerpt'] = 'The council adopted the policy.';
        $validated = $judge->validate([$row], [$claim], [$source]);
        $this->assertSame('insufficient', $validated[0]['stance']);
        $this->assertSame('', $validated[0]['excerpt']);
    }

    public function test_conflicts_abstain_and_mixed_requires_different_claim_outcomes(): void
    {
        $judge = app(EvidenceJudge::class);
        $claims = [$this->claim('First claim.'), array_replace($this->claim('Second claim.'), ['id' => 'c2'])];
        $rows = [$this->relationship('c1', 's1', 'supporting', 'First claim.'), $this->relationship('c1', 's2', 'contradicting', 'First claim is false.')];
        $this->assertSame('conflicting', $judge->outcomes($claims, $rows)[0]['outcome']);
        $rows[1]['claim_id'] = 'c2';
        $this->assertSame(['supported', 'refuted'], array_column($judge->outcomes($claims, $rows), 'outcome'));
    }

    public function test_only_explicit_dated_supersession_resolves_old_evidence(): void
    {
        $judge = app(EvidenceJudge::class);
        $claim = $this->claim('The district office is open.');
        $sources = [['id' => 's1', 'text' => 'The district office is open.', 'published_at' => '2026-10-01T00:00:00Z'],
            ['id' => 's2', 'text' => 'This correction replaces our October 1 notice. The office is closed.', 'published_at' => '2026-10-02T00:00:00Z']];
        $old = $this->relationship('c1', 's1', 'supporting', $sources[0]['text']);
        $new = $this->relationship('c1', 's2', 'contradicting', $sources[1]['text']);
        $this->assertSame('conflicting', $judge->outcomes([$claim], [$old, $new])[0]['outcome']);
        $new['supersedes_source_ids'] = ['s1'];
        $new['supersession_excerpt'] = $sources[1]['text'];
        $rows = $judge->validate([$old, $new], [$claim], $sources);
        $this->assertSame('refuted', $judge->outcomes([$claim], $rows)[0]['outcome']);
        $this->assertTrue($judge->outcomes([$claim], $rows)[0]['relationships'][0]['superseded']);
        $this->assertFalse($judge->outcomes([$claim], $rows)[0]['relationships'][1]['superseded']);
    }

    public function test_mixed_report_requires_distinct_supported_and_refuted_claims(): void
    {
        $first = 'The district office opened on October 1.';
        $second = 'The district office opened on October 2.';
        $text = 'The district office opened on October 1. It did not open on October 2. This notice is the original administrative record.';
        $claims = [$this->claim($first), array_replace($this->claim($second), ['id' => 'c2'])];
        $this->fake($first.' '.$second, 'factual', $text, claims: $claims, judgeOutput: function ($sources) use ($text) {
            return [$this->relationship('c1', $sources[0]['id'], 'supporting', $text), $this->relationship('c2', $sources[0]['id'], 'contradicting', $text)];
        });
        $result = $this->runAnalysis($first.' '.$second);
        $this->assertSame('Partly True', $result['assessment_label']);
        $this->assertSame('PARTLY_TRUE', $result['verdict']);
        $this->assertSame(['supported', 'refuted'], array_column($result['claims'], 'outcome'));
        $this->assertSame(1, $result['diagnostics']['sources_used']);
        config()->set('fact_check.max_claims', 1);
        $limited = $this->runAnalysis($first.' '.$second);
        $this->assertCount(1, $limited['claims']);
        $this->assertSame('Missing Context', $limited['assessment_label']);
    }

    public function test_first_round_preserves_search_and_page_capacity_for_unresolved_claims(): void
    {
        $this->fake($this->fixture['primary_claim'], 'factual', $this->fixture['primary_text']);
        $budget = new RetrievalBudget;
        app(ArticleSearch::class)->retrieve(['one', 'two', 'three', 'four'], $budget, $this->fixture['cutoff'], reserveNextRound: true);
        $this->assertSame(2, $budget->searches);
        $this->assertLessThan((int) config('fact_check.retrieval.max_pages'), $budget->pages);
    }

    public function test_long_articles_share_the_model_budget_and_partial_reading_is_explicit(): void
    {
        $claim = $this->fixture['primary_claim'];
        $text = $this->fixture['primary_text'].str_repeat(' Additional background material.', 1000);
        config()->set('fact_check.max_model_input_bytes', 9000);
        $this->fake($claim, 'factual', $text, judgeOutput: fn ($sources) => [$this->relationship('c1', $sources[0]['id'], 'supporting', $this->fixture['primary_text'])]);
        $result = $this->runAnalysis($claim);
        $this->assertSame('TRUE', $result['verdict']);
        $this->assertTrue($result['diagnostics']['candidate_records'][0]['review_text_truncated']);
        $this->assertStringContainsString('remaining content was not assessed', implode(' ', $result['limitations']));
        Http::assertSent(fn ($r) => data_get($r->data(), 'text.format.name') === 'evidence_relationships_0' && strlen($r['input'][1]['content']) < 9000);
    }

    public function test_future_or_modified_after_cutoff_and_undated_historical_pages_are_excluded(): void
    {
        $claim = $this->fixture['primary_claim'];
        foreach (['2026-10-14T00:00:00Z', null] as $date) {
            Cache::flush();
            $this->fake($claim, 'factual', $this->fixture['primary_text'], published: $date);
            $result = $this->runAnalysis($claim, '2026-10-12T09:00:00+00:00');
            $this->assertSame('UNVERIFIED', $result['verdict']);
            $this->assertSame([], $result['sources']);
        }
        $page = ['url' => $this->url, 'status' => 'retrieved', 'text' => $this->fixture['primary_text'], 'published_at' => '2026-10-01T00:00:00Z', 'updated_at' => '2026-10-14T00:00:00Z', 'retrieved_at' => now()->toIso8601String()];
        $this->assertSame('after_cutoff', app(ArticleSearch::class)->reviewedSubmission($page, $this->fixture['cutoff'])['retrieval_status']);
    }

    public function test_missing_full_text_and_outages_never_turn_discovery_into_verification(): void
    {
        $claim = $this->fixture['primary_claim'];
        $this->fake($claim, 'factual', 'Please sign in.');
        $result = $this->runAnalysis($claim);
        $this->assertSame('UNVERIFIED', $result['verdict']);
        $this->assertSame(0, $result['diagnostics']['sources_reviewed']);
        Cache::flush();
        Http::swap(new Factory);
        Http::fake(['*' => Http::response([], 503)]);
        $result = $this->runAnalysis($claim);
        $this->assertSame('ANALYSIS_UNAVAILABLE', $result['verdict']);
        $this->assertSame('http_503_incomplete', $result['diagnostics']['model_stages']['claim_decomposition']);
    }

    public function test_copied_articles_and_tracking_urls_are_grouped(): void
    {
        $search = app(ArticleSearch::class);
        $this->assertSame($search->canonicalUrl('https://site.example/story?utm_source=feed'), $search->canonicalUrl('https://site.example/story'));
        $text = str_repeat('An identical syndicated article with a single original source. ', 10);
        $record = ['id' => 's1', 'text' => $text, 'content_hash' => hash('sha256', $text), 'retrieval_status' => 'retrieved'];
        $rows = $search->deduplicate([$record, array_replace($record, ['id' => 's2'])]);
        $this->assertSame('duplicate', $rows[1]['retrieval_status']);
        $this->assertSame('s1', $rows[1]['duplicate_of']);
        $original = 'The office is open. '.implode(' ', range(1, 40));
        $revision = 'The office is not open. '.implode(' ', range(1, 40));
        $rows = $search->deduplicate([
            array_replace($record, ['text' => $original, 'content_hash' => hash('sha256', $original)]),
            array_replace($record, ['id' => 's2', 'text' => $revision, 'content_hash' => hash('sha256', $revision)]),
        ]);
        $this->assertSame('retrieved', $rows[1]['retrieval_status']);
        $this->assertSame('s1', $rows[1]['possible_copy_of']);
    }

    public function test_ssrf_redirects_and_oversized_or_non_document_responses_are_blocked(): void
    {
        $fetcher = app(SafeArticleFetcher::class);
        foreach (['http://127.0.0.1/a', 'http://[::1]/', 'http://169.254.169.254/latest', 'http://10.0.0.2/', 'http://100.100.1.1/', 'file:///etc/passwd', 'https://user:pass@example.com/'] as $url) {
            $this->assertNull($fetcher->publicAddress($url));
        }
        Http::fake([$this->url => Http::response('', 302, ['Location' => 'http://127.0.0.1/secrets'])]);
        $this->assertSame('blocked_url', $fetcher->fetch($this->url, new RetrievalBudget)['status']);
        Http::assertSentCount(1);
        Http::swap(new Factory);
        Http::fake([$this->url => Http::response(str_repeat('x', 3000), 200, ['Content-Type' => 'text/html'])]);
        config()->set('fact_check.retrieval.max_bytes', 1024);
        $this->assertSame('too_large', $fetcher->fetch($this->url, new RetrievalBudget)['status']);
        Http::swap(new Factory);
        Http::fake([$this->url => Http::response('binary', 200, ['Content-Type' => 'application/octet-stream'])]);
        $this->assertSame('unsupported_content_type', $fetcher->fetch($this->url, new RetrievalBudget)['status']);
    }

    public function test_google_pagination_preserves_filters_and_rate_limits_are_bounded(): void
    {
        config()->set(['fact_check.retrieval.google_pages' => 2, 'fact_check.retrieval.max_pages' => 0]);
        Http::fake(['factchecktools.googleapis.com/*' => Http::sequence()->push(['claims' => [], 'nextPageToken' => 'next'])->push(['claims' => []])]);
        app(ArticleSearch::class)->retrieve(['specific claim'], new RetrievalBudget, $this->fixture['cutoff']);
        $requests = Http::recorded()->map(fn ($pair) => $pair[0]->data())->all();
        $this->assertSame('next', $requests[1]['pageToken']);
        unset($requests[1]['pageToken']);
        $this->assertSame($requests[0], $requests[1]);
        Cache::flush();
        Http::swap(new Factory);
        Http::fake(['*' => Http::response([], 429, ['Retry-After' => '120'])]);
        $budget = new RetrievalBudget;
        app(ArticleSearch::class)->retrieve(['one', 'two'], $budget, $this->fixture['cutoff']);
        Http::assertSentCount(1);
        $this->assertSame('rate_limited', $budget->providers['google_fact_check']);
    }

    public function test_robots_restrictions_are_respected_before_article_download(): void
    {
        config()->set('fact_check.retrieval.respect_robots', true);
        Http::fake(['https://court.gov.ph/robots.txt' => Http::response("User-agent: *\nDisallow: /docket/\n", 200, ['Content-Type' => 'text/plain'])]);
        $fetcher = app(SafeArticleFetcher::class);
        $blocked = $fetcher->fetch($this->url, new RetrievalBudget);
        $this->assertSame('robots_restricted_or_unavailable', $blocked['status']);
        $this->assertSame('robots_disallow', $blocked['robots_detail']);
        Http::assertSentCount(1);
        $this->assertTrue($fetcher->robotsPermit("User-agent: *\nDisallow: /\nAllow: /public/\n", '/public/record'));
        $this->assertFalse($fetcher->robotsPermit("User-agent: *\nDisallow: /*?private=", '/article?private=1'));
    }

    public function test_gnews_queries_quote_punctuation_without_splitting_a_phrase_or_operator(): void
    {
        $search = app(ArticleSearch::class);
        $this->assertSame('"BSKE" "2026" "postponement:" "a" "race" "against" "time" "and" "the" "Constitution."', $search->gnewsQuery($this->fixture['headline']));
        $this->assertSame('"BSKE 2026" "postponement"', $search->gnewsQuery('"BSKE 2026" postponement'));
        $long = $search->gnewsQuery(str_repeat('lengthy headline? ', 50));
        $this->assertLessThanOrEqual(200, mb_strlen($long));
        $this->assertSame(0, substr_count($long, '"') % 2);
    }

    public function test_incorrect_ocr_is_preserved_and_inferred_assertions_are_not_substituted(): void
    {
        Storage::fake('public');
        config()->set('services.openai.extraction_model', 'fixture-ocr-model');
        $visible = 'BSKE [unclear] postponement: a race against time.';
        $this->fake($visible, 'rhetorical', $this->fixture['unrelated_text'], needsContext: true, ocr: $visible);
        $file = UploadedFile::fake()->image('claim.jpg');
        $result = app(EvidenceVerificationService::class)->apply(['signals' => []], ['media_type' => 'image', 'analysis_task' => 'claim'], $file)['fact_check_result'];
        $this->assertSame($visible, $result['extraction']['visible_text']);
        $this->assertSame('fixture-ocr-model', $result['extraction']['configured_model']);
        $this->assertNull($result['extraction']['response_model']);
        $this->assertSame('An unsupported inference.', $result['extraction']['inferred_claim']);
        $this->assertSame($visible, $result['claim']);
        $this->assertSame('Missing Context', $result['assessment_label']);
    }

    public function test_unavailable_and_long_input_models_do_not_produce_fabricated_predictions(): void
    {
        config()->set('services.filipino_news_classifier.enabled', true);
        Http::fake(['*' => Http::response([], 503)]);
        $model = app(FilipinoNewsClassifier::class)->inspect(str_repeat('sample article text ', 20), 'submitted article');
        $this->assertSame('unavailable', $model['status']);
        $this->assertSame('provider_unavailable', $model['failure_reason']);
        $this->assertSame(503, $model['metadata']['http_status']);
        $this->assertNull($model['prediction']);
        Http::swap(new Factory);
        Http::fake(['*' => Http::response(['status' => 'unsuitable_long_input', 'model_version' => 'fixture-version', 'input_tokens' => 600, 'max_length' => 256])]);
        $model = app(FilipinoNewsClassifier::class)->inspect(str_repeat('sample article text ', 1000), 'submitted article');
        $this->assertSame('unsuitable_long_input', $model['status']);
        $this->assertSame('input_exceeds_model_window', $model['failure_reason']);
        $this->assertNull($model['prediction']);
        $this->assertSame('fixture-version', $model['metadata']['model_version']);
        $this->assertSame(600, $model['metadata']['input_tokens']);
    }

    public function test_classifier_records_specific_http_and_payload_failures_without_a_prediction(): void
    {
        config()->set('services.filipino_news_classifier.enabled', true);
        $text = str_repeat('public article text ', 20);
        foreach ([401 => 'authentication_failed', 429 => 'rate_limited', 503 => 'provider_unavailable'] as $status => $reason) {
            Http::swap(new Factory);
            Http::fake(['*' => Http::response([], $status)]);
            $model = app(FilipinoNewsClassifier::class)->inspect($text, 'submitted article');
            $this->assertSame('unavailable', $model['status']);
            $this->assertSame($reason, $model['failure_reason']);
            $this->assertSame($status, $model['metadata']['http_status']);
            $this->assertNull($model['prediction']);
        }
        Http::swap(new Factory);
        Http::fake(['*' => Http::response(['status' => 'completed', 'verdict' => 'Likely Fake News'], 200)]);
        $model = app(FilipinoNewsClassifier::class)->inspect($text, 'submitted article');
        $this->assertSame('invalid_response', $model['status']);
        $this->assertSame('invalid_prediction_payload', $model['failure_reason']);
        $this->assertSame(200, $model['metadata']['http_status']);
        $this->assertNull($model['prediction']);

        Http::swap(new Factory);
        Http::fake(fn () => throw new ConnectionException('Operation timed out'));
        $model = app(FilipinoNewsClassifier::class)->inspect($text, 'submitted article');
        $this->assertSame('unavailable', $model['status']);
        $this->assertSame('timeout', $model['failure_reason']);
        $this->assertNull($model['prediction']);
    }

    public function test_complete_submission_preserves_report_ownership_and_new_evidence_ui(): void
    {
        Storage::fake('public');
        $this->fake($this->fixture['primary_claim'], 'factual', $this->fixture['primary_text']);
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('detections.store'), ['caption_text' => $this->fixture['primary_claim']])->assertRedirect();
        $detection = Detection::firstOrFail();
        $this->assertSame($user->id, $detection->user_id);
        $this->assertSame(4, $detection->fact_check_result['schema_version']);
        $html = view('detections.partials.canonical-result-card', ['selectedDetection' => $detection, 'result' => $detection->fact_check_result, 'userReportNumber' => 1])->render();
        $this->assertStringContainsString('Supports the claim', $html);
        $this->assertStringContainsString('Sources to verify', $html);
        $this->assertStringNotContainsString('Unique articles reviewed', $html);
        $this->assertStringNotContainsString('role="meter"', $html);
        $this->assertStringNotContainsString('Claim Assessment', $html);
        $this->actingAs(User::factory()->create())->getJson(route('detections.result', $detection))->assertNotFound();
    }

    private function runAnalysis(string $claim, ?string $cutoff = null): array
    {
        return app(EvidenceVerificationService::class)->apply(['signals' => []], ['caption_text' => $claim, 'media_type' => 'text', 'evidence_cutoff' => $cutoff ?? $this->fixture['cutoff']])['fact_check_result'];
    }

    public function test_queued_analysis_shows_real_status_then_finishes_the_same_owned_report(): void
    {
        Queue::fake();
        config()->set('fact_check.queue_enabled', true);
        $this->fake($this->fixture['primary_claim'], 'factual', $this->fixture['primary_text']);
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('detections.store'), ['caption_text' => $this->fixture['primary_claim']])->assertRedirect();
        $detection = Detection::firstOrFail();
        $this->assertSame('queued', $detection->processing_status);
        $this->actingAs($user)->getJson(route('detections.result', $detection))->assertJsonPath('processing_status', 'queued');
        Http::assertNothingSent();
        Queue::assertPushed(AnalyzeDetectionEvidence::class, function ($job) use ($detection) {
            $this->assertSame($detection->id, $job->detectionId);
            $job->handle(app(DetectionPipeline::class));

            return true;
        });
        $this->assertSame(1, Detection::count());
        $this->assertSame('completed', $detection->fresh()->processing_status);
        $this->assertSame('TRUE', $detection->fresh()->fact_check_result['verdict']);
        $this->actingAs($user)->getJson(route('detections.result', $detection))->assertJsonPath('assessment_label', 'True');
    }

    public function test_failed_queue_job_does_not_fabricate_a_report_or_refresh_on_read(): void
    {
        $user = User::factory()->create();
        $detection = Detection::create(['user_id' => $user->id, 'source_kind' => 'link', 'media_type' => 'text', 'processing_status' => 'processing', 'verdict' => 'review', 'fake_score' => 0]);
        (new AnalyzeDetectionEvidence($detection->id, []))->failed(new \RuntimeException('private error'));
        $this->actingAs($user)->getJson(route('detections.result', $detection))->assertJsonPath('processing_status', 'failed');
        $this->assertNull($detection->fresh()->fact_check_result);
        Http::assertNothingSent();
    }

    public function test_model_disagreement_is_retained_without_overriding_direct_evidence(): void
    {
        config()->set('services.filipino_news_classifier.enabled', true);
        $this->fake($this->fixture['primary_claim'], 'factual', $this->fixture['primary_text']);
        $this->app->instance(FilipinoNewsClassifier::class, new class extends FilipinoNewsClassifier
        {
            public function inspect(string $text, string $scope): array
            {
                return ['status' => 'completed', 'task' => 'article_style_classification', 'input_scope' => $scope, 'contribution' => 'Diagnostic only', 'limitation' => 'Uncalibrated output',
                    'prediction' => ['verdict' => 'fake', 'raw_verdict' => 'Likely Fake News', 'confidence' => .99, 'truncation_reported' => true]];
            }
        });
        $result = $this->runAnalysis($this->fixture['primary_claim']);
        $this->assertSame('TRUE', $result['verdict']);
        $this->assertTrue($result['trained_model']['disagrees_with_evidence']);
        $this->assertSame(.99, $result['trained_model']['prediction']['confidence']);
    }

    public function test_context_request_preserves_factual_claims_and_requires_reviewed_expiry(): void
    {
        $claim = 'The warning is in effect today.';
        $text = 'This demonstration warning is valid until October 12, 2026. The issuing authority states that it then expires.';
        $this->fake($claim, 'factual', $text, 'contradicting', contextOutput: fn ($sources) => [[
            'claim_id' => 'c1', 'source_id' => $sources[0]['id'], 'label' => 'OUTDATED', 'excerpt' => $text,
            'explanation' => 'The quoted validity period ended before the evaluation cutoff.', 'submitted_quote' => $claim,
            'exact_subject_match' => true, 'presented_as_current' => true, 'valid_until' => 'October 12, 2026',
        ]]);
        $result = app(EvidenceVerificationService::class)->apply(['signals' => []], [
            'caption_text' => 'Is this warning current? '.$claim, 'media_type' => 'text', 'evidence_cutoff' => $this->fixture['cutoff'],
        ])['fact_check_result'];
        $this->assertSame('context', $result['primary_task']);
        $this->assertSame('OUTDATED', $result['verdict']);
        $this->assertSame('refuted', $result['claims'][0]['outcome']);
        $this->assertNull($result['score']['value']);
        $this->assertCount(1, $result['sources']);
        $this->assertSame([$result['sources'][0]['id']], $result['answer_segments'][0]['source_ids']);
        $this->assertSame('FALSE', $result['secondary_assessments'][0]['verdict']);
    }

    public function test_context_request_does_not_turn_factual_support_into_proof_of_original_context(): void
    {
        $this->fake($this->fixture['primary_claim'], 'factual', $this->fixture['primary_text']);
        $result = app(EvidenceVerificationService::class)->apply(['signals' => []], [
            'caption_text' => 'Is this caption misleading? '.$this->fixture['primary_claim'],
            'media_type' => 'text', 'evidence_cutoff' => $this->fixture['cutoff'],
        ])['fact_check_result'];

        $this->assertSame('context', $result['primary_task']);
        $this->assertSame('UNVERIFIED', $result['verdict']);
        $this->assertSame([], $result['answer_segments'][0]['source_ids']);
        $this->assertSame('Score unavailable', $result['score_display']);
        $this->assertCount(1, $result['sources']);
        $this->assertSame('TRUE', $result['secondary_assessments'][0]['verdict']);
        $this->assertSame([$result['sources'][0]['id']], $result['secondary_assessments'][0]['answer_segments'][0]['source_ids']);
    }

    private function claim(string $text, string $kind = 'factual', bool $needsContext = false): array
    {
        return ['id' => 'c1', 'text' => $text, 'quote' => $text, 'kind' => $kind, 'entities' => ['BSKE'], 'dates' => [], 'locations' => [], 'quantities' => [], 'qualifiers' => [],
            'requires_context' => $needsContext, 'context_reason' => $needsContext ? 'Only a rhetorical headline is available.' : '', 'references' => [], 'searches' => ['BSKE filing record', 'BSKE primary docket']];
    }

    private function relationship(string $claim, string $source, string $stance, string $excerpt): array
    {
        return ['claim_id' => $claim, 'source_id' => $source, 'stance' => $stance, 'excerpt' => $excerpt, 'explanation' => 'This docket records the specific filing.',
            'matches' => array_fill_keys(['event', 'entities', 'action', 'date', 'location', 'quantities', 'qualifiers'], true),
            'basis' => 'primary_record', 'supersedes_source_ids' => [], 'supersession_excerpt' => ''];
    }

    private function fake(string $claim, string $kind, string $text, string $stance = 'supporting', bool $needsContext = false, array $matches = [], ?string $published = '2026-10-01T00:00:00Z', ?string $ocr = null, array $claims = [], ?\Closure $judgeOutput = null, ?\Closure $contextOutput = null): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($claim, $kind, $text, $stance, $needsContext, $matches, $published, $ocr, $claims, $judgeOutput, $contextOutput) {
            if (str_contains($request->url(), 'api.openai.com/')) {
                $stage = $request['text']['format']['name'];
                if ($stage === 'truthguard_claim_extraction') {
                    $output = ['claim_text' => 'An unsupported inference.', 'visible_text' => $ocr ?? '', 'synthetic_media_note' => '', 'confidence' => 40];
                } elseif ($stage === 'claim_decomposition') {
                    $planned = $claims ?: [$this->claim($claim, $kind, $needsContext)];
                    foreach ($planned as &$row) {
                        unset($row['id'], $row['text']);
                    }
                    unset($row);
                    $output = ['claims' => $planned, 'original_claim_date' => null, 'date_quote' => ''];
                } else {
                    $input = json_decode($request['input'][1]['content'], true);
                    $rows = [];
                    foreach ($input['sources'] as $source) {
                        $row = $this->relationship('c1', $source['id'], $stance, $text);
                        $row['matches'] = array_replace($row['matches'], $matches);
                        $rows[] = $row;
                    }
                    $output = ['context_findings' => $contextOutput ? $contextOutput($input['sources']) : [], 'relationships' => $judgeOutput ? $judgeOutput($input['sources']) : $rows];
                }

                return Http::response(['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($output)]]]], 'usage' => ['input_tokens' => 40, 'output_tokens' => 40, 'total_tokens' => 80]]);
            }
            if (str_contains($request->url(), 'factchecktools.googleapis.com/')) {
                return Http::response(['claims' => [['text' => $this->fixture['unrelated_claim'], 'claimReview' => [['url' => $this->url, 'title' => 'A dated docket record', 'publisher' => ['name' => 'Court'], 'textualRating' => 'False', 'reviewDate' => $published]]]]]);
            }
            if ($request->url() === $this->url) {
                return Http::response('<html><head><title>A dated docket record</title>'.($published ? '<meta property="article:published_time" content="'.$published.'">' : '').'</head><body><article>'.$text.'</article></body></html>', 200, ['Content-Type' => 'text/html']);
            }

            return Http::response([], 503);
        });
    }
}
