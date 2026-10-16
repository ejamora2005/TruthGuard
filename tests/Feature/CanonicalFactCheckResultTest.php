<?php

namespace Tests\Feature;

use App\Models\CanonicalFactCheckResult;
use App\Models\Detection;
use App\Models\User;
use App\Services\Detections\FactCheckEvidencePolicy;
use App\Services\Detections\FactCheckResultService;
use App\Services\Detections\GoogleFactCheckFeedService;
use App\Services\Detections\LiveVerificationEvidenceService;
use App\Services\Detections\TrustedFactCheckVerdictResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CanonicalFactCheckResultTest extends TestCase
{
    use RefreshDatabase;

    private string $claim = 'Classes are suspended in Manila on October 13, 2026.';

    private array $context = [
        'evidence_cutoff' => '2026-10-13T09:00:00+00:00',
        'source_snapshot_id' => 'snapshot-a',
        'locale' => 'en',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.openai.key', 'test-key');
        config()->set('services.openai.analysis_enabled', true);
        config()->set('services.openai.claim_extraction_enabled', false);
        config()->set('services.live_verification.refresh_on_view', false);
        Http::preventStrayRequests();
    }

    public function test_risk_scores_homepages_social_posts_and_snippets_cannot_become_evidence(): void
    {
        $sources = [
            array_replace($this->source(), ['url' => 'https://reddit.com/r/news/123']),
            array_replace($this->source(), ['url' => 'https://doh.gov.ph.evil.example/123']),
            array_replace($this->source(), ['url' => 'https://doh.gov.ph/', 'evidence_verified' => false]),
            array_replace($this->source(), ['evidence_kind' => 'search_snippet']),
            array_replace($this->source(), ['relevant' => false]),
            array_replace($this->source(), ['url' => 'javascript:alert(1)']),
        ];
        $result = $this->generate($sources);

        $this->assertSame('UNVERIFIABLE', $result['verdict']);
        $this->assertNull($result['confidence']);
        $this->assertSame([], $result['sources']);
        $this->assertSame('gray', $result['ui']['verdict_color']);
        $this->assertTrue($result['ui']['show_evidence_warning']);
        Http::assertNothingSent();
    }

    public function test_model_receives_only_approved_records_and_ui_is_enforced_by_the_server(): void
    {
        $report = $this->report();
        $report['ui']['verdict_color'] = 'red';
        $report['ui']['show_official_verification_notice'] = false;
        $report['key_findings'] = ['The proclamation names Manila.', 'The proclamation names Manila.'];
        $this->fake($report);
        $result = $this->generate([$this->source(), ['source_type' => 'social_context', 'url' => 'https://reddit.com/example']]);

        $this->assertSame('TRUE', $result['verdict']);
        $this->assertSame('green', $result['ui']['verdict_color']);
        $this->assertTrue($result['ui']['show_official_verification_notice']);
        $this->assertTrue($result['ui']['highlight_evidence_cutoff']);
        $this->assertCount(1, $result['key_findings']);
        Http::assertSent(function ($request) {
            $basis = json_decode($request['input'][1]['content'], true);

            return $request['text']['format']['strict'] === true
                && ! isset($request['tools']) && ! isset($basis['fake_score'])
                && count($basis['source_records']) === 1
                && $basis['claim'] === $this->claim;
        });
    }

    public function test_identical_normalized_identity_returns_the_exact_saved_result_without_another_model_call(): void
    {
        $this->fake($this->report());
        $first = $this->generate([$this->source()]);
        $second = $this->generate([$this->source()], ['caption_text' => '  CLASSES  are suspended in Manila on October 13, 2026. ']);

        $this->assertSame($first, $second);
        $this->assertSame(1, CanonicalFactCheckResult::count());
        Http::assertSentCount(1);
    }

    public function test_locale_cutoff_snapshot_and_claim_changes_create_distinct_canonical_results(): void
    {
        $this->generate([]);
        foreach ([['locale' => 'fil'], ['evidence_cutoff' => '2026-10-14T09:00:00+00:00'], ['source_snapshot_id' => 'snapshot-b'], ['caption_text' => 'A different claim.']] as $context) {
            $this->generate([], $context);
        }
        $this->assertSame(5, CanonicalFactCheckResult::count());
    }

    public function test_a_changed_evidence_record_changes_the_automatic_snapshot(): void
    {
        config()->set('services.openai.analysis_enabled', false);
        $first = $this->generate([$this->source()], ['source_snapshot_id' => null]);
        $second = $this->generate([array_replace($this->source(), ['evidence_text' => 'An updated proclamation reverses the suspension.'])], ['source_snapshot_id' => null]);

        $this->assertNotSame($first['source_snapshot_id'], $second['source_snapshot_id']);
        $this->assertSame(2, CanonicalFactCheckResult::count());
    }

    public function test_invented_citations_and_changed_metadata_fail_closed(): void
    {
        foreach (['url' => 'https://www.officialgazette.gov.ph/invented', 'title' => 'Invented title'] as $field => $value) {
            $report = $this->report();
            $report['sources'][0][$field] = $value;
            $report['source_snapshot_id'] = $field;
            $this->fake($report);
            $result = $this->generate([$this->source()], ['source_snapshot_id' => $field]);
            $this->assertSame('ANALYSIS_UNAVAILABLE', $result['verdict']);
            $this->assertSame([], $result['sources']);
        }
    }

    public function test_conflicting_exact_claim_ratings_are_not_hidden_by_selecting_only_one_source(): void
    {
        $first = array_replace($this->source(), ['type' => 'fact_check', 'name' => 'Reuters', 'url' => 'https://reuters.com/fact-check/classes']);
        $second = array_replace($first, ['name' => 'AFP', 'url' => 'https://factcheck.afp.com/classes', 'rating' => 'False']);
        $this->fake($this->report([$first]));
        $result = $this->generate([$first, $second]);

        $this->assertSame('UNVERIFIABLE', $result['verdict']);
        $this->assertTrue($result['ui']['show_source_conflict']);
        $this->assertTrue($result['ui']['show_evidence_warning']);
        $this->assertCount(2, $result['sources']);
    }

    public function test_official_evidence_precedes_fact_checks_and_news(): void
    {
        $fact = array_replace($this->source(), ['type' => 'fact_check', 'name' => 'Reuters', 'url' => 'https://reuters.com/fact-check/classes']);
        $news = array_replace($fact, ['type' => 'reputable_news', 'url' => 'https://reuters.com/news/classes']);
        $this->fake($this->report([$news, $fact, $this->source()]));
        $result = $this->generate([$news, $fact, $this->source()]);

        $this->assertSame(['official_government', 'fact_check', 'reputable_news'], array_column($result['sources'], 'type'));
    }

    public function test_news_alone_cannot_support_a_true_verdict(): void
    {
        $news = array_replace($this->source(), ['type' => 'reputable_news', 'url' => 'https://reuters.com/news/classes']);
        $this->fake($this->report([$news]));
        $this->assertSame('UNVERIFIABLE', $this->generate([$news])['verdict']);
    }

    public function test_an_official_rating_resolves_conflicting_fact_checks_but_the_conflict_is_disclosed(): void
    {
        $fact = array_replace($this->source(), ['type' => 'fact_check', 'name' => 'Reuters', 'url' => 'https://reuters.com/fact-check/classes', 'rating' => 'False']);
        $this->fake($this->report([$this->source()]));
        $result = $this->generate([$this->source(), $fact]);

        $this->assertSame('TRUE', $result['verdict']);
        $this->assertTrue($result['ui']['show_source_conflict']);
        $this->assertTrue($result['ui']['show_evidence_warning']);
        $this->assertSame('official_government', $result['sources'][0]['type']);
        $this->assertCount(2, $result['sources']);
    }

    public function test_low_confidence_and_missing_publication_dates_always_show_a_warning(): void
    {
        $source = array_replace($this->source(), ['published_at' => null]);
        $report = $this->report([$source]);
        $report['confidence'] = 95;
        $this->fake($report);
        $result = $this->generate([$source]);

        $this->assertLessThan(70, $result['confidence']);
        $this->assertTrue($result['ui']['show_evidence_warning']);
        $this->assertNotEmpty($result['limitations']);
        $this->assertNull($result['sources'][0]['published_at']);
    }

    public function test_google_adapter_preserves_the_exact_claim_raw_rating_and_review_date(): void
    {
        $method = new \ReflectionMethod(LiveVerificationEvidenceService::class, 'buildFactCheckSources');
        $records = $method->invoke(app(LiveVerificationEvidenceService::class), [[
            'text' => $this->claim, 'claimDate' => '2025-01-01T00:00:00Z',
            'claimReview' => [[
                'url' => 'https://reuters.com/fact-check/classes', 'publisher' => ['name' => 'Reuters'],
                'title' => 'An earlier class suspension notice is outdated', 'textualRating' => 'Outdated',
                'reviewDate' => '2026-10-13T08:00:00Z',
            ]],
        ]]);
        $sources = app(FactCheckEvidencePolicy::class)->sources($records, $this->claim, $this->context['evidence_cutoff']);

        $this->assertCount(1, $sources);
        $this->assertSame('Outdated', $sources[0]['rating']);
        $this->assertSame($this->claim, $sources[0]['claim_text']);
        $this->assertSame('2026-10-13T08:00:00+00:00', $sources[0]['published_at']);
    }

    public function test_result_view_escapes_claims_and_generated_prose(): void
    {
        $result = $this->generate([]);
        $result['claim'] = '<script>alert("claim")</script>';
        $result['summary'] = '<img src=x onerror=alert("summary")>';
        $html = view('detections.partials.canonical-result-card', ['result' => $result, 'selectedDetection' => new Detection])->render();

        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_pagasa_is_excluded_for_non_weather_claims_and_future_records_are_excluded(): void
    {
        $sources = app(FactCheckEvidencePolicy::class)->sources([
            array_replace($this->source(), ['url' => 'https://pagasa.dost.gov.ph/bulletin/123']),
            array_replace($this->source(), ['published_at' => '2026-10-14T09:00:00+00:00']),
        ], 'The election ballot has changed.', $this->context['evidence_cutoff']);

        $this->assertSame([], $sources);
    }

    public function test_all_six_verdict_colors_are_enforced_and_missing_context_is_preserved(): void
    {
        foreach (['TRUE' => 'green', 'FALSE' => 'red', 'MISLEADING' => 'orange', 'PARTLY_TRUE' => 'amber', 'UNVERIFIABLE' => 'gray', 'OUTDATED' => 'blue'] as $verdict => $color) {
            $source = array_replace($this->source(), ['rating' => '']);
            $report = $this->report([$source]);
            $report['verdict'] = $verdict;
            $report['source_snapshot_id'] = $verdict;
            $report['ui']['missing_context'] = 'The proclamation applies only to the named date.';
            $this->fake($report);
            $result = $this->generate([$source], ['source_snapshot_id' => $verdict]);

            $this->assertSame($verdict, $result['verdict']);
            $this->assertSame($color, $result['ui']['verdict_color']);
            $this->assertTrue($result['ui']['show_missing_context']);
        }
    }

    public function test_incomplete_response_or_invalid_schema_never_produces_a_factual_verdict(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['status' => 'incomplete'], 200)]);
        $this->assertSame('ANALYSIS_UNAVAILABLE', $this->generate([$this->source()])['verdict']);
        $report = $this->report();
        $report['confidence'] = 101;
        $this->fake($report);
        $this->assertSame('ANALYSIS_UNAVAILABLE', $this->generate([$this->source()], ['source_snapshot_id' => 'invalid'])['verdict']);
    }

    public function test_malformed_fenced_double_encoded_refused_and_failed_api_output_is_not_answer_text(): void
    {
        $rawResponses = [
            '{"claim":',
            "```json\n".json_encode($this->report())."\n```",
            json_encode(json_encode($this->report())),
            '',
        ];
        foreach ($rawResponses as $index => $raw) {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            Http::fake(['api.openai.com/*' => Http::response([
                'status' => 'completed', 'output' => $raw === '' ? [
                    ['type' => 'message', 'content' => [['type' => 'refusal', 'refusal' => 'dangerous_secret_marker']]],
                ] : [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => $raw]]]],
            ])]);
            $result = $this->generate([$this->source()], ['source_snapshot_id' => 'bad-output-'.$index]);
            $this->assertSame('ANALYSIS_UNAVAILABLE', $result['verdict']);
            $this->assertStringContainsString('could not finish', $result['summary']);
            $this->assertStringNotContainsString('dangerous_secret_marker', json_encode($result));
            $this->assertSame('Score unavailable', $result['score_display']);
        }

        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'dangerous_secret_marker']], 503)]);
        $failed = $this->generate([$this->source()], ['source_snapshot_id' => 'failed-api']);
        $this->assertSame('ANALYSIS_UNAVAILABLE', $failed['verdict']);
        $this->assertStringNotContainsString('dangerous_secret_marker', json_encode($failed));
    }

    public function test_result_page_and_json_use_the_saved_result_and_keep_owner_authorization(): void
    {
        $user = User::factory()->create();
        $result = $this->generate([]);
        $detection = Detection::create([
            'user_id' => $user->id, 'source_kind' => 'link', 'media_type' => 'text',
            'caption_text' => $this->claim, 'verdict' => 'review', 'fake_score' => 10,
            'processing_status' => 'completed', 'analyzed_at' => now(), 'fact_check_result' => $result,
            'signals' => ['verification_policy' => [['version' => TrustedFactCheckVerdictResolver::POLICY_VERSION]]],
        ]);
        $this->mock(GoogleFactCheckFeedService::class)->shouldReceive('latest')->andReturn([]);
        $public = $this->actingAs($user)->getJson(route('detections.result', $detection))->assertOk();
        $public->assertJsonPath('verdict', 'UNVERIFIED')
            ->assertJsonPath('historical_assessment.verdict', $result['verdict'])
            ->assertJsonMissingPath('source_snapshot_id')
            ->assertJsonMissingPath('legacy_model_estimate');
        $this->actingAs($user)->get(route('detections.result', $detection))->assertOk()
            ->assertSee('Historical: Unverified')->assertSee('Evidence insufficient')->assertSee('Evidence cutoff')
            ->assertSee('Recheck the submission')->assertDontSee('LOW-RISK CLAIM');
        $this->actingAs(User::factory()->create())->getJson(route('detections.result', $detection))->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_completed_get_is_read_only_even_with_old_refresh_on_view_enabled(): void
    {
        config()->set('services.live_verification.refresh_on_view', true);
        $this->mock(GoogleFactCheckFeedService::class)->shouldReceive('latest')->andReturn([]);
        $owner = User::factory()->create();
        $canonical = Detection::create([
            'user_id' => $owner->id, 'source_kind' => 'link', 'media_type' => 'text',
            'caption_text' => $this->claim, 'verdict' => 'review', 'fake_score' => 0,
            'processing_status' => 'completed', 'analyzed_at' => now(),
            'fact_check_result' => $this->generate([]),
        ]);
        $legacy = Detection::create([
            'user_id' => $owner->id, 'source_kind' => 'link', 'media_type' => 'text',
            'caption_text' => 'A historical saved statement.', 'verdict' => 'fake', 'fake_score' => 91,
            'processing_status' => 'completed', 'analyzed_at' => now(), 'fact_check_result' => null,
        ]);
        $savedJson = $canonical->getRawOriginal('fact_check_result');

        foreach ([$canonical, $legacy] as $detection) {
            $this->actingAs($owner)->getJson(route('detections.result', $detection))->assertOk();
            $this->actingAs($owner)->get(route('detections.result', $detection))->assertOk();
        }

        $this->assertSame($savedJson, $canonical->fresh()->getRawOriginal('fact_check_result'));
        $this->assertNull($legacy->fresh()->getRawOriginal('fact_check_result'));
        $this->assertSame('fake', $legacy->fresh()->verdict);
        Http::assertNothingSent();
    }

    public function test_report_reference_is_numbered_per_owner_with_a_tg_prefix(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $result = $this->generate([]);
        $attributes = [
            'source_kind' => 'link', 'media_type' => 'text',
            'caption_text' => $this->claim, 'verdict' => 'review', 'fake_score' => 10,
            'processing_status' => 'completed', 'analyzed_at' => now(), 'fact_check_result' => $result,
            'signals' => ['verification_policy' => [['version' => TrustedFactCheckVerdictResolver::POLICY_VERSION]]],
        ];
        $otherFirst = Detection::create(['user_id' => $otherUser->id] + $attributes);
        $ownerFirst = Detection::create(['user_id' => $owner->id, 'archived_at' => now()] + $attributes);
        Detection::create(['user_id' => $otherUser->id] + $attributes);
        $ownerSecond = Detection::create(['user_id' => $owner->id] + $attributes);
        $ownerThird = Detection::create(['user_id' => $owner->id] + $attributes);
        $this->mock(GoogleFactCheckFeedService::class)->shouldReceive('latest')->andReturn([]);

        foreach ([[$owner, $ownerFirst, 1], [$owner, $ownerSecond, 2], [$owner, $ownerThird, 3], [$otherUser, $otherFirst, 1]] as [$viewer, $detection, $number]) {
            $this->actingAs($viewer)->get(route('detections.result', $detection))
                ->assertOk()
                ->assertViewHas('userReportNumber', $number)
                ->assertSee('<strong>TG-'.str_pad((string) $number, 2, '0', STR_PAD_LEFT).'</strong>', false)
                ->assertDontSee('<strong>#', false);
        }

        // An administrator sees the report owner's sequence, not their own or the global ID.
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get(route('detections.result', $ownerSecond))
            ->assertOk()
            ->assertViewHas('userReportNumber', 2)
            ->assertSee('<strong>TG-02</strong>', false);
        $this->actingAs($owner)->getJson(route('detections.result', $ownerSecond))
            ->assertOk()->assertJsonPath('verdict', 'UNVERIFIED')
            ->assertJsonPath('historical_assessment.verdict', $result['verdict'])
            ->assertJsonMissingPath('source_snapshot_id');
        Http::assertNothingSent();
    }

    public function test_original_submission_is_first_for_each_input_type_and_never_uses_a_source_thumbnail(): void
    {
        $cases = [
            [['media_type' => 'image', 'media_path' => 'detections/original.png'], 'src="/storage/detections/original.png"'],
            [['media_type' => 'video', 'media_path' => 'detections/original.mp4'], 'aria-label="Submitted video"'],
            [['media_type' => 'document', 'media_path' => 'detections/original.pdf'], 'Open PDF'],
            [['media_type' => 'text', 'source_url' => 'https://example.com/original'], 'href="https://example.com/original"'],
            [['media_type' => 'text', 'caption_text' => 'Original submitted wording'], 'Original submitted wording'],
            [['media_type' => 'image', 'source_url' => 'https://example.com/unrelated.png'], 'The original image is not available for preview.'],
        ];

        foreach ($cases as [$attributes, $expectedContent]) {
            $html = view('detections.partials.canonical-result-card', [
                'result' => $this->report(), 'selectedDetection' => new Detection($attributes),
            ])->render();
            $dom = new \DOMDocument;
            @$dom->loadHTML($html);
            $xpath = new \DOMXPath($dom);
            $firstSection = $xpath->query('//article/section[1]')->item(0);

            $this->assertSame('submitted-evidence', $firstSection->getAttribute('aria-labelledby'));
            $this->assertStringContainsString($expectedContent, $dom->saveHTML($firstSection));
            $assessment = $xpath->query('//article/section[2]')->item(0);
            $this->assertSame('fact-check-verdict', $assessment->getAttribute('aria-labelledby'));
            $this->assertSame(0, $xpath->query('.//*[@id="submitted-claim" and not(ancestor::details)]', $assessment)->length);
            $this->assertSame(1, $xpath->query('//details[contains(@class, "tg-result-all-details")]//*[@id="submitted-claim"]')->length);
            $this->assertSame(1, $xpath->query('.//*[@id="fact-check-verdict"]', $assessment)->length);
            $this->assertStringNotContainsString('src="https://example.com/unrelated.png"', $html);
        }
    }

    public function test_result_presentation_retains_warning_conditions_full_prose_and_source_links(): void
    {
        $result = $this->report();
        $result['summary'] = str_repeat('A detailed explanation with supporting context. ', 80);
        $result['limitations'] = ['The available publication has a limited geographic scope.'];
        $notices = [
            ['show_official_verification_notice', 'official_verification_notice', 'Verify with the named official authority.'],
            ['show_evidence_warning', 'evidence_warning', 'Only indirect evidence is available.'],
            ['show_missing_context', 'missing_context', 'The exact location is missing.'],
            ['show_source_conflict', 'source_conflict_message', 'The publications disagree about the date.'],
        ];
        foreach ([false, true] as $visible) {
            foreach ($notices as [$flag, $message, $text]) {
                $result['ui'][$flag] = $visible;
                $result['ui'][$message] = $text;
            }
            $html = view('detections.partials.canonical-result-card', [
                'result' => $result, 'selectedDetection' => new Detection(['media_type' => 'text']),
            ])->render();
            foreach ($notices as [$flag, $message, $text]) {
                $visible ? $this->assertStringContainsString($text, $html) : $this->assertStringNotContainsString($text, $html);
            }
            foreach ([$result['summary'], $result['limitations'][0], $result['sources'][0]['used_for'], 'Confidence not available', 'Read result aloud', 'Evidence cutoff:'] as $content) {
                $this->assertStringContainsString($content, $html);
            }
            $this->assertStringContainsString('href="'.$result['sources'][0]['url'].'"', $html);
            $this->assertStringNotContainsString('Stored legacy evidence score: 90/100', $html);
            $this->assertStringNotContainsString('role="meter"', $html);
        }
    }

    public function test_original_submission_text_is_escaped_and_unsafe_links_are_not_clickable(): void
    {
        $html = view('detections.partials.canonical-result-card', [
            'result' => $this->report(),
            'selectedDetection' => new Detection(['media_type' => 'text', 'caption_text' => '<script>alert("claim")</script>', 'source_url' => 'javascript:alert(1)']),
        ])->render();

        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringNotContainsString('href="javascript:', $html);
    }

    private function generate(array $sources, array $context = []): array
    {
        return app(FactCheckResultService::class)->generate(['verification_sources' => $sources, 'verdict' => 'real', 'fake_score' => 0],
            array_replace($this->context, ['caption_text' => $this->claim], $context));
    }

    private function source(): array
    {
        return [
            'name' => 'Official Gazette', 'title' => 'Manila class suspension proclamation',
            'url' => 'https://www.officialgazette.gov.ph/2026/10/13/proclamation-123',
            'type' => 'official_government', 'published_at' => '2026-10-13T08:00:00+00:00',
            'evidence_verified' => true, 'evidence_text' => 'Classes in Manila are suspended on October 13, 2026.',
            'claim_text' => $this->claim, 'rating' => 'True',
        ];
    }

    private function report(?array $sources = null): array
    {
        return [
            'claim' => $this->claim, 'normalized_claim' => strtolower($this->claim),
            'verdict' => 'TRUE', 'confidence' => 90,
            'summary' => 'The supplied proclamation suspends classes in Manila on the stated date.',
            'key_findings' => ['The proclamation names Manila and October 13, 2026.'],
            'sources' => array_map(fn (array $source) => Arr::only($source, ['name', 'title', 'url', 'type', 'published_at']) + ['used_for' => 'Confirms the date and location of the suspension.'], $sources ?? [$this->source()]),
            'limitations' => [], 'evidence_cutoff' => $this->context['evidence_cutoff'], 'source_snapshot_id' => $this->context['source_snapshot_id'],
            'ui' => [
                'verdict_color' => 'green', 'show_evidence_warning' => false, 'evidence_warning' => null,
                'show_missing_context' => false, 'missing_context' => null,
                'show_source_conflict' => false, 'source_conflict_message' => null,
                'show_official_verification_notice' => false, 'official_verification_notice' => null,
                'highlight_evidence_cutoff' => false, 'source_display_order' => FactCheckEvidencePolicy::SOURCE_ORDER,
            ],
        ];
    }

    private function fake(array $report): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/*' => Http::response([
            'status' => 'completed',
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($report)]]]],
        ])]);
    }
}
