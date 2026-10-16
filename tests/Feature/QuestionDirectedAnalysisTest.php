<?php

namespace Tests\Feature;

use App\Models\Detection;
use App\Models\User;
use App\Services\Detections\AiOriginClassifier;
use App\Services\Detections\DetectionVerdictPresenter;
use App\Services\Detections\Evidence\SafeArticleFetcher;
use App\Services\Detections\Evidence\SubmittedText;
use App\Services\Detections\GoogleFactCheckFeedService;
use App\Services\Detections\ResultContract;
use App\Services\Detections\SubmissionTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class QuestionDirectedAnalysisTest extends TestCase
{
    use RefreshDatabase;

    private array $post;

    private const SOURCE = 'https://phivolcs.dost.gov.ph/fixture-record';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Http::preventStrayRequests();
        $this->post = json_decode(file_get_contents(base_path('tests/Fixtures/evidence/leyte-post.json')), true);
        config()->set(['fact_check.enhanced_enabled' => true, 'fact_check.queue_enabled' => false,
            'fact_check.web_search.enabled' => false,
            'services.ai_origin.enabled' => true, 'services.openai.analysis_enabled' => true, 'services.openai.claim_extraction_enabled' => true, 'services.openai.key' => 'fixture',
            'services.filipino_news_classifier.enabled' => false, 'services.live_verification.enabled' => true,
            'services.google_fact_check.key' => 'fixture', 'services.gnews.key' => '', 'services.newsapi.key' => '',
            'fact_check.retrieval.google_pages' => 1, 'fact_check.retrieval.max_search_requests' => 4,
            'fact_check.retrieval.respect_robots' => false, 'services.live_verification.refresh_on_view' => true,
            'playwright.related_posts_inline' => false]);
        $this->mock(GoogleFactCheckFeedService::class)->shouldReceive('latest')->andReturn([]);
        $this->mock(AiOriginClassifier::class)->shouldReceive('classify')->andReturn([
            'status' => 'completed', 'media_type' => 'image', 'task' => 'AI-generation screening', 'label' => 'real',
            'fake_probability' => .1, 'threshold' => .5, 'screening_score' => 10, 'model_version' => 'test-fixture',
            'label_mapping' => ['real', 'fake'], 'output_contract' => ['activation' => 'sigmoid', 'positive_class' => 'fake', 'binary_exclusive' => true],
        ]);
        $this->app->instance(SafeArticleFetcher::class, new class extends SafeArticleFetcher
        {
            protected function resolve(string $host): array
            {
                return ['93.184.216.34'];
            }
        });
    }

    private function upload(string $question, string $mode = 'auto'): Detection
    {
        $this->actingAs(User::factory()->create())->post(route('detections.store'), [
            'caption_text' => $question, 'analysis_task' => $mode,
            'media_file' => new UploadedFile(base_path('tests/Fixtures/evidence/media/notice.png'), 'post.png', null, null, true),
        ])->assertSessionHasNoErrors()->assertRedirect();

        return Detection::latest('id')->firstOrFail();
    }

    public static function detectorQuestions(): array
    {
        return [['Is this AI-generated?'], ['Is this image AI generated?'], ['Did AI generate this?'],
            ['is this ai or not'], ['Is this made with artificial intelligence'], ['AI ba ito?'],
            ['Gawa ba ito ng AI?'], ['Is this computer generated?'], ['AI-generated ba ito?'],
            ['Do you think this is AI?'], ['Could this be AI'], ['Does this look AI?']];
    }

    #[DataProvider('detectorQuestions')]
    public function test_explicit_ai_request_uses_saved_detector_output_without_text_apis(string $question): void
    {
        $detection = $this->upload($question);
        $result = $detection->fact_check_result;
        $this->assertSame('media', $result['primary_task']);
        $this->assertSame('LIKELY_NOT_AI_GENERATED', $result['verdict']);
        $this->assertNull($result['confidence']);
        $this->assertSame('not_requested', $result['claim_assessment']['status']);
        $this->assertStringContainsString('does not prove', $result['summary']);
        $this->getJson(route('detections.result', $detection))->assertExactJson(ResultContract::forPresentation($result));
        $html = $this->get(route('detections.result', $detection))->assertOk()->assertSee($result['assessment_label'])->assertSee($result['summary'])->getContent();
        $this->assertStringContainsString('id="assessment-details"', $html);
        $this->assertStringContainsString('View details', $html);
        $this->assertSame($result, $detection->fresh()->fact_check_result);
        Http::assertNothingSent();
    }

    public function test_report_213_question_overrides_generic_claim_mode_and_needs_no_ocr(): void
    {
        $result = $this->upload('is this ai or not', 'claim')->fact_check_result;
        $this->assertSame('media', $result['primary_task']);
        $this->assertSame('is this ai or not', $result['request']['question']);
        $this->assertSame(['media'], $result['request']['tasks']);
        $this->assertSame('LIKELY_NOT_AI_GENERATED', $result['verdict']);
        $this->assertStringNotContainsString('factual assertion', $result['summary']);
        Http::assertNothingSent();
    }

    public function test_editing_request_discloses_unsupported_capability(): void
    {
        $detection = $this->upload('Was this edited?');
        $this->assertSame('manipulation', $detection->fact_check_result['primary_task']);
        $this->assertSame('Unsupported Analysis', $detection->fact_check_result['assessment_label']);
        $this->assertStringContainsString('cannot establish', $detection->fact_check_result['summary']);
        $this->get(route('detections.result', $detection))->assertOk()->assertSee('Unsupported Analysis');
        Http::assertNothingSent();
    }

    public function test_ai_origin_and_manipulation_question_keeps_separate_supported_and_unsupported_findings(): void
    {
        $result = $this->upload('Does this show signs of AI generation or manipulation?')->fact_check_result;

        $this->assertSame('media', $result['primary_task']);
        $this->assertSame(['media', 'manipulation'], $result['request']['tasks']);
        $this->assertSame('LIKELY_NOT_AI_GENERATED', $result['verdict']);
        $this->assertSame(['UNSUPPORTED_ANALYSIS'], array_column($result['secondary_assessments'], 'verdict'));
        $this->assertSame('not_requested', $result['claim_assessment']['status']);
        $this->assertSame([], $result['sources']);
        Http::assertNothingSent();
    }

    public function test_generic_real_question_stays_factual_without_readable_text_and_explicit_ai_retry_still_works(): void
    {
        $this->fakeEvidence(ocr: '');
        $detection = $this->upload('Is this real?');
        $this->assertSame('claim', $detection->fact_check_result['primary_task']);
        $this->assertSame('not_requested', $detection->fact_check_result['media_assessment']['status']);
        $this->assertSame(0, substr_count($detection->fact_check_result['summary'], '?'));
        $this->get(route('detections.result', $detection))->assertOk()->assertDontSee('Check AI generation')
            ->assertDontSee('Verify a statement')->assertDontSee('One clarification')->assertDontSee('AI-generation assessment');
        $path = $detection->media_path;
        $this->post(route('detections.retry-media', $detection), ['analysis_task' => 'media'])->assertRedirect();
        $this->assertSame('media', $detection->fresh()->fact_check_result['primary_task']);
        $this->assertSame($path, $detection->fresh()->media_path);
        $this->assertSame(1, Detection::count());
    }

    public function test_leyte_wrapped_ocr_and_fragments_become_one_claim_with_whole_post_reference(): void
    {
        $this->fakeEvidence(fragments: true);
        $detection = $this->upload($this->post['question']);
        $result = $detection->fact_check_result;
        $this->assertSame($this->post['paragraphs'], $result['extraction']['reconstructed_text']);
        $this->assertSame($this->post['ocr'], $result['extraction']['visible_text']);
        $this->assertCount(1, $result['claims']);
        $this->assertSame($this->post['claim'], $result['claims'][0]['text']);
        $this->assertSame('LEYTE', $result['claims'][0]['references'][0]['referent']);
        $this->assertSame('True', $result['assessment_label']); // Fixture evidence, not the live post's truth label.
        $this->assertSame('matched_evidence', $result['diagnostics']['outcome_reason']);
        $this->assertSame($this->post['question'], $result['request']['question']);
        $this->assertStringNotContainsString('is this real', strtolower($result['claim']));
        $this->assertLessThan(180, str_word_count($result['summary']));
        $this->getJson(route('detections.result', $detection))->assertExactJson(ResultContract::forPresentation($result));
        $this->get(route('detections.result', $detection))->assertOk()->assertSeeInOrder([$result['assessment_label'], 'View details', 'Source verification', 'Open source']);
        $this->assertSame($result, $detection->fresh()->fact_check_result);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'factchecktools') && str_contains($r['query'], 'PHIVOLCS') && str_contains($r['query'], 'LEYTE'));
    }

    public function test_fact_check_instruction_is_preserved_as_the_question_but_excluded_from_the_ocr_claim(): void
    {
        $instruction = 'Fact-check this claim against trusted public sources and explain the evidence.';
        $this->fakeEvidence();
        $result = $this->upload($instruction)->fact_check_result;

        $this->assertSame('claim', $result['primary_task']);
        $this->assertSame($instruction, $result['request']['question']);
        $this->assertSame('', $result['extraction']['submitted_text']);
        $this->assertStringContainsString('PHIVOLCS warns', $result['claim']);
        $this->assertStringNotContainsString('Fact-check this claim', $result['claim']);
        Http::assertSent(function ($request) use ($instruction): bool {
            if (data_get($request->data(), 'text.format.name') !== 'claim_decomposition') {
                return false;
            }
            $input = json_decode($request['input'][1]['content'], true);

            return ! str_contains($input['original_text'], $instruction);
        });
    }

    public function test_fact_check_mode_with_an_upload_needs_no_canned_caption(): void
    {
        $this->fakeEvidence();
        $this->actingAs(User::factory()->create())->post(route('detections.store'), [
            'analysis_task' => 'claim',
            'media_file' => new UploadedFile(base_path('tests/Fixtures/evidence/media/notice.png'), 'post.png', null, null, true),
        ])->assertSessionHasNoErrors()->assertRedirect();

        $detection = Detection::latest('id')->firstOrFail();
        $result = $detection->fact_check_result;
        $this->assertNull($detection->caption_text);
        $this->assertSame('', $result['request']['question']);
        $this->assertSame('claim', $result['request']['selected_mode']);
        $this->assertSame('claim', $result['primary_task']);
        $this->assertSame($this->post['claim'], $result['claims'][0]['text']);
        $this->assertSame('True', $result['assessment_label']); // Synthetic fixture evidence.
        $this->assertNotEmpty($result['sources']);
    }

    public function test_wrapped_leyte_headline_stays_together_for_location_resolution(): void
    {
        $ocr = str_replace('BIG ONE IN LEYTE?', "BIG ONE IN\nLEYTE?", $this->post['ocr']);
        $this->fakeEvidence(ocr: $ocr);
        $result = $this->upload('Fact-check this claim against trusted public sources and explain the evidence.')->fact_check_result;

        $this->assertSame($this->post['paragraphs'], $result['extraction']['reconstructed_text']);
        $this->assertSame('LEYTE', $result['claims'][0]['references'][0]['referent']);
        $this->assertSame($this->post['claim'], $result['claims'][0]['text']);
    }

    public static function discoveryOutcomes(): array
    {
        return [
            'empty success' => [200, false, 'no_search_results', 'completed_no_results'],
            'outage' => [503, false, 'api_failure', 'failed'],
            'authentication failure' => [401, false, 'api_failure', 'failed'],
            'rate limit' => [429, false, 'api_failure', 'failed'],
            'unrelated evidence' => [200, true, 'no_matching_evidence', 'completed'],
        ];
    }

    #[DataProvider('discoveryOutcomes')]
    public function test_retrieval_outcomes_are_not_reported_as_missing_context(int $status, bool $unrelated, string $reason, string $retrieval): void
    {
        $this->fakeEvidence(searchStatus: $status, noResults: ! $unrelated, unrelated: $unrelated);
        $result = $this->upload('Is this statement true?')->fact_check_result;
        $this->assertSame('claim', $result['primary_task']);
        $this->assertSame($reason, $result['diagnostics']['outcome_reason']);
        $this->assertSame($retrieval, $result['diagnostics']['retrieval_status']);
        $this->assertSame(4, $result['diagnostics']['search_requests']); // Both rounds run.
        $this->assertFalse($result['ui']['show_missing_context']);
        $this->assertSame([], $result['sources']);
        $this->assertSame($status !== 200 ? 'ANALYSIS_UNAVAILABLE' : 'UNVERIFIED', $result['verdict']);
        if (in_array($status, [401, 429], true)) {
            $this->assertSame($status === 401 ? 'authentication_failed' : 'rate_limited', $result['diagnostics']['search_attempts'][0]['status']);
        }
        if ($reason === 'no_search_results') {
            $this->assertStringContainsString('PHIVOLCS', $result['summary']);
            $this->assertStringContainsString('LEYTE', $result['summary']);
            $this->assertStringContainsString('available coverage', $result['summary']);
        }
    }

    public function test_malformed_success_is_reported_as_a_search_failure_not_no_matches(): void
    {
        $this->fakeEvidence(searchBody: ['unexpected' => 'shape']);
        $result = $this->upload('Is this statement true?')->fact_check_result;

        $this->assertSame('ANALYSIS_UNAVAILABLE', $result['verdict']);
        $this->assertSame('api_failure', $result['diagnostics']['outcome_reason']);
        $this->assertSame('invalid_response', $result['diagnostics']['search_attempts'][0]['status']);
        $this->assertSame(200, $result['diagnostics']['search_attempts'][0]['http_status']);
    }

    public function test_gnews_archive_limit_is_coverage_limitation_not_a_malformed_response_or_false_verdict(): void
    {
        config()->set(['services.google_fact_check.key' => '', 'services.gnews.key' => 'fixture']);
        $this->fakeEvidence(gnewsBody: [
            'information' => ['realTimeArticles' => ['message' => 'Free plan has a 12-hour delay.']],
            'articlesRemovedFromResponse' => ['historicalArticles' => ['message' => '3 articles were removed from the response.']],
            'totalArticles' => 38, 'articles' => [],
        ]);
        $result = $this->upload('Is this statement true?')->fact_check_result;

        $this->assertSame('UNVERIFIED', $result['verdict']);
        $this->assertSame([], $result['sources']);
        $this->assertSame('archive_limited', $result['diagnostics']['outcome_reason']);
        $this->assertSame('completed_access_limited', $result['diagnostics']['retrieval_status']);
        $this->assertSame('archive_limited', $result['diagnostics']['search_attempts'][0]['status']);
        $this->assertStringContainsString('archive limit', $result['summary']);
    }

    public function test_selected_mode_resolves_ambiguous_question_and_plain_statement_is_unchanged(): void
    {
        $context = ['media_type' => 'image', 'caption_text' => 'Is this real?', 'analysis_task' => 'media'];
        $this->assertSame('media', SubmissionTask::primary($context, ['analysis_text' => $this->post['paragraphs']]));
        $this->assertSame('claim', SubmissionTask::primary(array_replace($context, ['analysis_task' => 'claim']), ['analysis_text' => '']));
        $this->assertSame($this->post['claim'], SubmissionTask::claimText('Is this statement true? '.$this->post['claim']));
        $this->assertSame('The bridge is open.', SubmissionTask::claimText('The bridge is open.'));
    }

    public function test_ocr_outage_is_reported_as_an_extraction_failure_not_missing_context(): void
    {
        Http::fake(['*' => Http::response([], 503)]);
        $result = $this->upload('Is this statement true?')->fact_check_result;
        $this->assertSame('extraction_unavailable', $result['diagnostics']['outcome_reason']);
        $this->assertSame('not_run', $result['diagnostics']['retrieval_status']);
        $this->assertFalse($result['ui']['show_missing_context']);
        $this->assertSame('Analysis Unavailable', $result['assessment_label']);
        $this->assertSame([], $result['sources']);
    }

    public static function ocrHttpFailures(): array
    {
        return [
            'authentication' => [401, 'authentication_failed'],
            'rate limit' => [429, 'rate_limited'],
            'server outage' => [503, 'provider_unavailable'],
        ];
    }

    #[DataProvider('ocrHttpFailures')]
    public function test_ocr_http_failures_keep_their_reason_and_do_not_become_empty_searches(int $status, string $reason): void
    {
        Http::fake(['*' => Http::response([], $status)]);
        $result = $this->upload('Is this statement true?')->fact_check_result;

        $this->assertSame('ANALYSIS_UNAVAILABLE', $result['verdict']);
        $this->assertSame('extraction_unavailable', $result['diagnostics']['outcome_reason']);
        $this->assertSame('not_run', $result['diagnostics']['retrieval_status']);
        $this->assertSame($reason, $result['extraction']['failure_reason']);
        $this->assertSame($status, $result['extraction']['http_status']);
    }

    public function test_ocr_timeout_is_separate_from_an_empty_search(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection timed out'));
        $timeout = $this->upload('Is this statement true?')->fact_check_result;
        $this->assertSame('timeout', $timeout['extraction']['failure_reason']);
        $this->assertSame('not_run', $timeout['diagnostics']['retrieval_status']);
    }

    public function test_malformed_ocr_response_is_separate_from_an_empty_search(): void
    {
        Http::fake(['*' => Http::response(['status' => 'completed', 'output' => []], 200)]);
        $malformed = $this->upload('Is this statement true?')->fact_check_result;
        $this->assertSame('unexpected_response_format', $malformed['extraction']['failure_reason']);
        $this->assertSame(200, $malformed['extraction']['http_status']);
        $this->assertSame('not_run', $malformed['diagnostics']['retrieval_status']);
    }

    public function test_successful_ocr_with_no_visible_text_is_not_marked_completed(): void
    {
        Http::fake(['*' => Http::response(['status' => 'completed', 'output' => [[
            'type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode([
                'visible_text' => '', 'claim_text' => '', 'synthetic_media_note' => '', 'confidence' => 0,
            ])]],
        ]]], 200)]);
        $result = $this->upload('Is this statement true?')->fact_check_result;

        $this->assertSame('missing_text', $result['extraction']['status']);
        $this->assertNull($result['extraction']['failure_reason']);
        $this->assertSame('UNREADABLE_CONTENT', $result['verdict']);
        $this->assertSame('not_run', $result['diagnostics']['retrieval_status']);
    }

    public function test_paragraph_repair_also_preserves_non_earthquake_subjects_and_dependent_clauses(): void
    {
        $raw = "MABINI CLINIC\nThe clinic will close\non Friday due to repairs\nwhich begin in the morning.";
        $expected = "MABINI CLINIC\n\nThe clinic will close on Friday due to repairs which begin in the morning.";
        $this->assertSame($expected, SubmittedText::paragraphs($raw));
        $this->assertCount(2, SubmittedText::sentences($expected));
    }

    public function test_uncaptioned_news_screenshot_prioritizes_ocr_claims(): void
    {
        $this->fakeEvidence();
        $result = $this->upload('')->fact_check_result;
        $this->assertSame('claim', $result['primary_task']);
        $this->assertSame('TRUE', $result['verdict']);
        $this->assertSame('evidence_strength', $result['score']['type']);
        $this->assertNull($result['confidence']);
    }

    public function test_same_original_with_different_questions_keeps_independent_saved_assessments(): void
    {
        $media = $this->upload('Is this AI-generated?');
        $this->assertSame('Model score: 90%', $media->fact_check_result['score_display']);
        $this->fakeEvidence();
        $factual = $this->upload('Is this statement true?');
        $this->assertSame($media->media_checksum, $factual->media_checksum);
        $this->assertNotSame($media->request_fingerprint, $factual->request_fingerprint);
        $this->assertSame('TRUE', $factual->fact_check_result['verdict']);
        $this->assertSame('LIKELY_NOT_AI_GENERATED', $media->fresh()->fact_check_result['verdict']);
    }

    public function test_multiple_questions_produce_separate_media_factual_and_unsupported_manipulation_assessments(): void
    {
        $this->fakeEvidence();
        $detection = $this->upload('Is this AI-generated? Is this statement true? Was this edited?');
        $result = $detection->fact_check_result;
        $this->assertSame('LIKELY_NOT_AI_GENERATED', $result['verdict']);
        $this->assertSame(['TRUE', 'UNSUPPORTED_ANALYSIS'], array_column($result['secondary_assessments'], 'verdict'));
        $this->assertSame('evidence_strength', $result['secondary_assessments'][0]['score']['type']);
        $this->assertNull($result['secondary_assessments'][1]['score']['value']);
        $this->assertSame($result['summary'], $detection->analysis_summary);
        $this->getJson(route('detections.result', $detection))->assertExactJson(ResultContract::forPresentation($result));
    }

    public function test_combined_origin_context_and_fact_check_keeps_all_requested_findings(): void
    {
        $this->fakeEvidence();
        $detection = $this->upload('Is this AI-generated? Is this misleading? Is this statement true?');
        $result = $detection->fact_check_result;
        $this->assertSame(['media', 'context', 'claim'], $result['request']['tasks']);
        $this->assertSame(['media', 'context', 'claim'], $result['completed_tasks']);
        $this->assertSame(['UNVERIFIED', 'TRUE'], array_column($result['secondary_assessments'], 'verdict'));
        $this->get(route('detections.result', $detection))->assertOk()
            ->assertSee('Content verification report')->assertSee('Context assessment')->assertSee('Factual claim assessment');
    }

    public function test_source_cards_follow_closed_details_and_citations_point_to_visible_cards(): void
    {
        $this->fakeEvidence();
        $detection = $this->upload('Is this statement true?');
        $html = $this->get(route('detections.result', $detection))->assertOk()->getContent();
        $dom = new \DOMDocument;
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);
        $this->assertSame(0, $xpath->query('//details[@id="assessment-details"]/@open')->length);
        $this->assertSame(0, $xpath->query('//*[@id="source-verification"]/ancestor::details')->length);
        $this->assertSame(1, $xpath->query('//details[@id="assessment-details"]/following-sibling::article[@id="source-verification"]')->length);
        $this->assertSame(1, $xpath->query('//*[@id="source-verification"]')->length);
        $this->assertGreaterThan(0, $xpath->query('//a[@href="#source-verification"]')->length);
        $this->assertSame($detection->fact_check_result['score_display'], DetectionVerdictPresenter::scoreDisplay($detection));
    }

    private function fakeEvidence(?string $ocr = null, bool $fragments = false, int $searchStatus = 200, bool $noResults = false, bool $unrelated = false, ?array $searchBody = null, ?array $gnewsBody = null): void
    {
        $article = $unrelated ? 'This software fixture describes a different region and a different event; it does not address the submitted warning.'
            : 'This simulated source attributes to PHIVOLCS a warning of a possible magnitude 7 earthquake in Leyte due to an active fault ready for movement. This is test data, not a real authority statement.';
        Http::fake(function ($r) use ($ocr, $fragments, $searchStatus, $noResults, $unrelated, $article, $searchBody, $gnewsBody) {
            if (str_contains($r->url(), 'api.openai.com')) {
                $stage = data_get($r->data(), 'text.format.name');
                if ($stage === 'truthguard_claim_extraction') {
                    $output = ['visible_text' => $ocr ?? $this->post['ocr'], 'claim_text' => '', 'synthetic_media_note' => '', 'confidence' => 90];
                } elseif ($stage === 'claim_decomposition') {
                    $input = json_decode($r['input'][1]['content'], true);
                    $this->assertStringNotContainsString('is this real', strtolower($input['original_text']));
                    $quotes = $fragments ? ['PHIVOLCS warns of a possible Magnitude 7 earthquake in the province', 'due to an active faultline', 'which is already ripe for movement.'] : [$this->post['claim']];
                    $rows = array_map(fn ($q) => ['quote' => $q, 'kind' => 'factual', 'entities' => ['PHIVOLCS'], 'dates' => [], 'locations' => ['LEYTE'],
                        'quantities' => ['Magnitude 7'], 'qualifiers' => ['possible'], 'requires_context' => false, 'context_reason' => '',
                        'references' => str_contains($q, 'the province') ? [['phrase' => 'the province', 'referent' => 'LEYTE', 'context_quote' => 'BIG ONE IN LEYTE?']] : [],
                        'searches' => ['PHIVOLCS LEYTE magnitude 7', 'LEYTE active fault', 'Leyte earthquake warning', 'PHIVOLCS warning clarification']], $quotes);
                    $output = ['claims' => $rows, 'original_claim_date' => null, 'date_quote' => ''];
                } else {
                    $input = json_decode($r['input'][1]['content'], true);
                    $this->assertCount(1, $input['claims']);
                    $this->assertStringContainsString('BIG ONE IN LEYTE?', $input['submission_contexts'][0]);
                    $output = ['context_findings' => [], 'relationships' => array_map(fn ($s) => ['claim_id' => 'c1', 'source_id' => $s['id'], 'stance' => $unrelated ? 'insufficient' : 'supporting',
                        'excerpt' => $article, 'explanation' => $unrelated ? 'This fixture addresses a different event.' : 'The simulated record supports the complete warning attributed to PHIVOLCS. It describes a possibility, not a certain earthquake.',
                        'matches' => array_fill_keys(['event', 'entities', 'action', 'date', 'location', 'quantities', 'qualifiers'], ! $unrelated),
                        'basis' => $unrelated ? 'background' : 'primary_record', 'supersedes_source_ids' => [], 'supersession_excerpt' => ''], $input['sources'])];
                }

                return Http::response(['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($output)]]]]]);
            }
            if (str_contains($r->url(), 'factchecktools')) {
                return Http::response($searchBody ?? ($noResults ? [] : ['claims' => [['claimReview' => [['url' => self::SOURCE, 'title' => 'Simulated authority record', 'publisher' => ['name' => 'Test fixture']]]]]]), $searchStatus);
            }
            if (str_contains($r->url(), 'gnews.io')) {
                return Http::response($gnewsBody ?? ['totalArticles' => 0, 'articles' => []]);
            }
            if ($r->url() === self::SOURCE) {
                return Http::response('<article>'.$article.'</article>', 200, ['Content-Type' => 'text/html']);
            }

            return Http::response([], 503);
        });
    }
}
