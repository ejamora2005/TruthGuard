<?php

namespace Tests\Feature;

use App\Models\Detection;
use App\Models\User;
use App\Services\Detections\DeepfakeCnnClassifier;
use App\Services\Detections\Evidence\ClaimPlanner;
use App\Services\Detections\Evidence\EvidenceJudge;
use App\Services\Detections\Evidence\SafeArticleFetcher;
use App\Services\Detections\Evidence\StructuredAnalysis;
use App\Services\Detections\EvidenceVerificationService;
use App\Services\Detections\SubmissionTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TaskRoutingAndHeadlineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config()->set([
            'fact_check.enhanced_enabled' => true,
            'fact_check.queue_enabled' => false,
            'fact_check.web_search.enabled' => false,
            'fact_check.retrieval.max_search_requests' => 4,
            'fact_check.retrieval.google_pages' => 1,
            'services.openai.key' => 'fixture',
            'services.openai.analysis_enabled' => true,
            'services.openai.claim_extraction_enabled' => false,
            'services.filipino_news_classifier.enabled' => false,
            'services.google_fact_check.key' => '',
            'services.gnews.key' => '',
            'services.newsapi.key' => '',
        ]);
    }

    public function test_short_announcement_is_a_factual_claim_without_a_full_sentence_requirement(): void
    {
        $headline = 'Riverside bridge reopened May 2026';
        $this->mock(StructuredAnalysis::class)->shouldReceive('ask')->once()->andReturn([
            'claims' => [$this->claim($headline, 'factual', false)],
            'original_claim_date' => null, 'date_quote' => '',
        ]);

        $plan = app(ClaimPlanner::class)->plan($headline);

        $this->assertSame('factual', $plan['claims'][0]['kind']);
        $this->assertFalse($plan['claims'][0]['requires_context']);
        $this->assertSame($headline, $plan['claims'][0]['quote']);
        $this->assertNotEmpty(app(ClaimPlanner::class)->queries($plan['claims'], 0));
    }

    public function test_unclear_named_headline_is_searched_before_missing_context(): void
    {
        $headline = 'BSKE 2026 postponement: a race against time and the Constitution.';
        config()->set('services.google_fact_check.key', 'fixture');
        Http::fake(function ($request) use ($headline) {
            if (str_contains($request->url(), 'factchecktools.googleapis.com')) {
                return Http::response(['claims' => []], 200);
            }
            if (str_contains($request->url(), 'api.openai.com')) {
                return Http::response(['status' => 'completed', 'output' => [[
                    'type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode([
                        'claims' => [$this->claim($headline, 'rhetorical', true)],
                        'original_claim_date' => null, 'date_quote' => '',
                    ])]],
                ]]], 200);
            }

            return Http::response([], 503);
        });

        $result = app(EvidenceVerificationService::class)->apply(['signals' => []], [
            'media_type' => 'text', 'caption_text' => $headline,
        ])['fact_check_result'];

        $this->assertSame('MISSING_CONTEXT', $result['verdict']);
        $this->assertSame(4, $result['diagnostics']['search_requests']);
        $this->assertSame('completed_no_results', $result['diagnostics']['retrieval_status']);
        $this->assertStringContainsString('I searched for records', $result['summary']);
        $this->assertSame([], $result['sources']);
    }

    public function test_source_outage_does_not_turn_an_unclear_headline_into_a_successful_context_assessment(): void
    {
        $headline = 'BSKE 2026 postponement: a race against time and the Constitution.';
        config()->set('services.google_fact_check.key', 'fixture');
        Http::fake(function ($request) use ($headline) {
            if (str_contains($request->url(), 'factchecktools.googleapis.com')) {
                return Http::response(['error' => ['message' => 'Service unavailable']], 503);
            }
            if (str_contains($request->url(), 'api.openai.com')) {
                return Http::response(['status' => 'completed', 'output' => [[
                    'type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode([
                        'claims' => [$this->claim($headline, 'rhetorical', true)],
                        'original_claim_date' => null, 'date_quote' => '',
                    ])]],
                ]]], 200);
            }

            return Http::response([], 503);
        });

        $result = app(EvidenceVerificationService::class)->apply(['signals' => []], [
            'media_type' => 'text', 'caption_text' => $headline,
        ])['fact_check_result'];

        $this->assertSame('ANALYSIS_UNAVAILABLE', $result['verdict']);
        $this->assertSame('api_failure', $result['diagnostics']['outcome_reason']);
        $this->assertStringContainsString('source-search services failed', $result['summary']);
        $this->assertSame([], $result['sources']);
    }

    public function test_searchable_headline_reviews_dated_status_without_inventing_a_verdict(): void
    {
        $headline = '2026 BSKE POSTPONEMENT: A RACE AGAINST TIME AND THE CONSTITUTION TOA';
        $excerpt = 'As of September 22, 2026, the bill to postpone the 2026 BSKE had not yet been signed into law.';
        $article = $excerpt.' The article describes the bill filing and says a proposal is different from a law in effect.';
        $url = 'https://www.rappler.com/newsbreak/fact-check/synthetic-bske-status/';
        $kind = 'rhetorical';
        config()->set([
            'services.google_fact_check.key' => 'fixture',
            'fact_check.retrieval.respect_robots' => false,
        ]);
        $this->app->instance(SafeArticleFetcher::class, new class extends SafeArticleFetcher
        {
            protected function resolve(string $host): array
            {
                return ['93.184.216.34'];
            }
        });
        Http::fake(function ($request) use ($headline, $excerpt, $article, $url, &$kind) {
            if (str_contains($request->url(), 'factchecktools.googleapis.com')) {
                return Http::response(['claims' => [[
                    'text' => 'The 2026 BSKE postponement bill was signed into law.',
                    'claimReview' => [['url' => $url, 'title' => 'Dated BSKE status article',
                        'publisher' => ['name' => 'Example publisher'], 'reviewDate' => '2026-09-22T00:00:00Z']],
                ]]], 200);
            }
            if ($request->url() === $url) {
                return Http::response('<html><head><title>Dated BSKE status article</title><meta property="article:published_time" content="2026-09-22T00:00:00Z"></head><body><article>'.$article.'</article></body></html>',
                    200, ['Content-Type' => 'text/html']);
            }
            if (str_contains($request->url(), 'api.openai.com')) {
                $stage = data_get($request->data(), 'text.format.name');
                if ($stage === 'claim_decomposition') {
                    $output = ['claims' => [$this->claim($headline, $kind, true)],
                        'original_claim_date' => null, 'date_quote' => ''];
                } else {
                    $sourceId = data_get(json_decode($request['input'][1]['content'], true), 'sources.0.id');
                    $output = ['context_findings' => [[
                        'claim_id' => 'c1', 'source_id' => $sourceId, 'label' => 'RELATED_STATUS',
                        'excerpt' => $excerpt, 'explanation' => 'The dated article says the bill had not yet become law on September 22.',
                        'submitted_quote' => $headline, 'exact_subject_match' => true,
                        'presented_as_current' => false, 'valid_until' => null,
                    ]], 'relationships' => $kind === 'factual' ? [[
                        'claim_id' => 'c1', 'source_id' => $sourceId, 'stance' => 'supporting',
                        'excerpt' => $excerpt, 'explanation' => 'A deliberately overconfident model relationship.',
                        'matches' => array_fill_keys(['event', 'entities', 'action', 'date', 'location', 'quantities', 'qualifiers'], true),
                        'basis' => 'fact_check', 'supersedes_source_ids' => [], 'supersession_excerpt' => '',
                    ]] : []];
                }

                return Http::response(['status' => 'completed', 'output' => [[
                    'type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($output)]],
                ]]], 200);
            }

            return Http::response([], 503);
        });

        $result = app(EvidenceVerificationService::class)->apply(['signals' => []], [
            'media_type' => 'text', 'caption_text' => $headline,
            'evidence_cutoff' => '2026-10-14T10:03:00Z',
        ])['fact_check_result'];

        $this->assertSame('MISSING_CONTEXT', $result['verdict']);
        $this->assertSame([], $result['sources']);
        $this->assertSame(0, $result['diagnostics']['sources_used']);
        $this->assertSame(1, $result['diagnostics']['sources_reviewed']);
        $this->assertSame($url, $result['contextual_sources'][0]['url']);
        $this->assertSame($excerpt, $result['contextual_sources'][0]['excerpt']);
        $this->assertSame([$result['contextual_sources'][0]['id']], $result['answer_segments'][1]['source_ids']);
        $this->assertSame('related_context', collect($result['diagnostics']['candidate_records'])->firstWhere('url', $url)['assessment_role']);
        $this->assertStringContainsString('September 22, 2026', $result['summary']);
        Http::assertSent(fn ($request) => data_get($request->data(), 'text.format.name') === 'evidence_relationships_0');

        $kind = 'factual';
        $factualHeading = app(EvidenceVerificationService::class)->apply(['signals' => []], [
            'media_type' => 'text', 'caption_text' => $headline,
            'evidence_cutoff' => '2026-10-14T10:03:00Z',
        ])['fact_check_result'];

        $this->assertSame('MISSING_CONTEXT', $factualHeading['verdict']);
        $this->assertSame('needs_context', $factualHeading['claims'][0]['outcome']);
        $this->assertSame('insufficient', $factualHeading['claims'][0]['relationships'][0]['stance']);
        $this->assertSame([], $factualHeading['sources']);
        $this->assertSame(0, $factualHeading['diagnostics']['sources_used']);
        $this->assertSame(1, $factualHeading['diagnostics']['sources_reviewed']);
        $this->assertSame($url, $factualHeading['contextual_sources'][0]['url']);
        $this->assertSame([$factualHeading['contextual_sources'][0]['id']], $factualHeading['answer_segments'][1]['source_ids']);
    }

    public function test_related_status_rejects_a_different_event_or_post_cutoff_record(): void
    {
        $headline = '2026 BSKE postponement and the Constitution';
        $unrelated = 'A special 2026 BSKE election on November 28 was discussed in a separate article about a different event.';
        $claim = ['id' => 'c1', 'text' => $headline, 'kind' => 'rhetorical', 'searches' => ['BSKE postponement status'],
            'submission_context' => $headline];
        $source = ['id' => 's1', 'text' => $unrelated, 'published_at' => '2026-09-22T00:00:00Z', 'updated_at' => null];
        $finding = ['claim_id' => 'c1', 'source_id' => 's1', 'label' => 'RELATED_STATUS', 'excerpt' => $unrelated,
            'explanation' => 'This is a different event.', 'submitted_quote' => $headline,
            'exact_subject_match' => true, 'presented_as_current' => false, 'valid_until' => null];
        $judge = app(EvidenceJudge::class);

        $this->assertSame([], $judge->validateContext([$finding], [$claim], [$source], '2026-10-14T10:03:00Z'));
        $source['text'] = 'A court record about the 2023 BSKE postponement discusses the enacted law for those earlier polls.';
        $finding['excerpt'] = $source['text'];
        $this->assertSame([], $judge->validateContext([$finding], [$claim], [$source], '2026-10-14T10:03:00Z'));
        $source['text'] = 'The 2026 BSKE postponement bill had not been signed into law by the date of this article.';
        $source['published_at'] = '2026-10-15T00:00:00Z';
        $finding['excerpt'] = $source['text'];
        $this->assertSame([], $judge->validateContext([$finding], [$claim], [$source], '2026-10-14T10:03:00Z'));
    }

    public function test_related_status_matches_a_spelled_out_acronym_only_with_the_same_event(): void
    {
        $headline = '2026 BSKE postponement and the Constitution';
        $excerpt = 'As of September 22, 2026, a bill to postpone the 2026 barangay and Sangguniang Kabataan elections had not yet become law.';
        $claim = ['id' => 'c1', 'text' => $headline, 'kind' => 'factual', 'requires_context' => true,
            'searches' => ['BSKE postponement status'], 'submission_context' => $headline];
        $source = ['id' => 's1', 'text' => $excerpt, 'published_at' => '2026-09-22T00:00:00Z', 'updated_at' => null];
        $finding = ['claim_id' => 'c1', 'source_id' => 's1', 'label' => 'RELATED_STATUS', 'excerpt' => $excerpt,
            'explanation' => 'The bill was not yet law on September 22.', 'submitted_quote' => $headline,
            'exact_subject_match' => true, 'presented_as_current' => false, 'valid_until' => null];
        $judge = app(EvidenceJudge::class);

        $this->assertCount(1, $judge->validateContext([$finding], [$claim], [$source], '2026-10-14T10:03:00Z'));
        $unrelated = 'As of September 22, 2026, the 2026 barangay and Sangguniang Kabataan elections had a new polling venue.';
        $source['text'] = $finding['excerpt'] = $unrelated;
        $this->assertSame([], $judge->validateContext([$finding], [$claim], [$source], '2026-10-14T10:03:00Z'));

        $claim['text'] = $claim['submission_context'] = '2026 RBC reopening';
        $source['text'] = $finding['excerpt'] = 'In 2026, the River Bridge Council announced that the crossing would reopen in June.';
        $finding['submitted_quote'] = $claim['text'];
        $this->assertCount(1, $judge->validateContext([$finding], [$claim], [$source], '2026-10-14T10:03:00Z'));
    }

    public function test_unclear_heading_does_not_overrule_a_separate_checkable_body(): void
    {
        $heading = 'Bridge update';
        $body = 'The Riverside bridge reopened on May 3, 2026.';
        $this->mock(StructuredAnalysis::class)->shouldReceive('ask')->once()->andReturn([
            'claims' => [
                $this->claim($heading, 'unclear', true),
                $this->claim($body, 'factual', false),
            ],
            'original_claim_date' => null, 'date_quote' => '',
        ]);

        $plan = app(ClaimPlanner::class)->plan($heading."\n\n".$body);

        $this->assertCount(1, $plan['claims']);
        $this->assertSame($body, $plan['claims'][0]['quote']);
    }

    public function test_misleading_context_and_combined_questions_route_to_the_requested_components(): void
    {
        $context = ['media_type' => 'image', 'caption_text' => 'Fact-check this misleading caption and its original event.'];
        $this->assertSame('context', SubmissionTask::primary($context));
        $this->assertSame(['context', 'claim'], SubmissionTask::tasks([
            'media_type' => 'image', 'caption_text' => 'Is this misleading?',
        ]));
        $this->assertSame(['claim'], SubmissionTask::tasks([
            'media_type' => 'image', 'caption_text' => 'Is this claim true?',
        ]));
        $this->assertSame(['media', 'claim'], SubmissionTask::tasks([
            'media_type' => 'image', 'caption_text' => 'Is this AI-generated? Is this statement true?',
        ]));
        $this->assertSame(['media', 'manipulation'], SubmissionTask::tasks([
            'media_type' => 'image', 'caption_text' => 'Does this show signs of AI generation or manipulation?',
        ]));
        $this->assertSame(['manipulation'], SubmissionTask::tasks([
            'media_type' => 'image', 'caption_text' => 'Was this edited or manipulated?',
        ]));
        $this->assertSame(['manipulation'], SubmissionTask::tasks([
            'media_type' => 'image', 'caption_text' => 'Check this image for manipulation',
        ]));
        $this->assertSame('context', SubmissionTask::primary([
            'media_type' => 'text', 'analysis_task' => 'context',
        ]));
        $this->assertSame('manipulation', SubmissionTask::primary([
            'media_type' => 'image', 'caption_text' => 'Was this photo doctored?',
        ]));
    }

    public function test_manipulation_only_question_does_not_run_an_ai_origin_detector(): void
    {
        Storage::fake('public');
        $this->mock(DeepfakeCnnClassifier::class)->shouldNotReceive('classify');

        $this->actingAs(User::factory()->create())->post(route('detections.store'), [
            'caption_text' => 'Was this image edited or manipulated?',
            'media_file' => new UploadedFile(base_path('tests/Fixtures/evidence/media/notice.png'), 'image.png', null, null, true),
        ])->assertSessionHasNoErrors()->assertRedirect();

        $result = Detection::latest('id')->firstOrFail()->fact_check_result;
        $this->assertSame('manipulation', $result['primary_task']);
        $this->assertSame('UNSUPPORTED_ANALYSIS', $result['verdict']);
        $this->assertSame('not_requested', $result['media_assessment']['status']);
        Http::assertNothingSent();
    }

    public function test_explicit_factual_image_request_skips_the_unneeded_media_detector_and_saves_its_status(): void
    {
        Storage::fake('public');
        $this->mock(DeepfakeCnnClassifier::class)->shouldNotReceive('classify');
        Http::fake(['api.openai.com/*' => Http::response(['status' => 'completed', 'output' => [[
            'type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode([
                'claims' => [$this->claim('Riverside bridge reopened May 2026', 'factual', false)],
                'original_claim_date' => null, 'date_quote' => '',
            ])]],
        ]]], 200)]);

        $this->actingAs(User::factory()->create())->post(route('detections.store'), [
            'caption_text' => 'Is this claim true? Riverside bridge reopened May 2026',
            'analysis_task' => 'claim',
            'media_file' => new UploadedFile(base_path('tests/Fixtures/evidence/media/notice.png'), 'bridge.png', null, null, true),
        ])->assertSessionHasNoErrors()->assertRedirect();

        $result = Detection::latest('id')->firstOrFail()->fact_check_result;
        $this->assertSame('claim', $result['primary_task']);
        $this->assertSame('not_requested', $result['media_assessment']['status']);
        $this->assertNull($result['media_assessment']['fake_probability']);
    }

    private function claim(string $quote, string $kind, bool $needsContext): array
    {
        return ['quote' => $quote, 'kind' => $kind, 'entities' => [], 'dates' => [], 'locations' => [],
            'quantities' => [], 'qualifiers' => [], 'requires_context' => $needsContext,
            'context_reason' => $needsContext ? 'This heading does not say whether the proposed change took effect.' : '',
            'searches' => str_contains($quote, 'BSKE')
                ? ['BSKE 2026 postponement status', 'election postponement official record',
                    'election commission schedule resolution', 'court petition election postponement']
                : ['Riverside bridge reopening record', 'Riverside bridge official notice',
                    'Riverside bridge status', 'Riverside bridge construction'], 'references' => []];
    }
}
