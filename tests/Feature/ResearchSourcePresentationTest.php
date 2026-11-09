<?php

namespace Tests\Feature;

use App\Models\Detection;
use App\Models\PublicClaimReview;
use App\Models\User;
use App\Services\GoogleFactCheckFeedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ResearchSourcePresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_citations_and_additional_relevant_publishers_are_shown_separately_without_http_or_reanalysis(): void
    {
        $this->mock(GoogleFactCheckFeedService::class)->shouldNotReceive('latest');
        $user = User::factory()->create(['privacy_policy_accepted_at' => now()]);
        $citations = array_map(fn ($i) => ['id' => 's'.$i, 'url' => 'https://publisher.example/record-'.$i,
            'title' => 'Cited record '.$i, 'name' => 'Fixture publisher', 'type' => 'fact_check',
            'used_for' => 'A dated passage addresses the submitted claim.'], range(1, 11));
        $result = ['schema_version' => 4, 'primary_task' => 'claim', 'verdict' => 'UNVERIFIED',
            'claim' => 'The fictional library opened in 2024.', 'summary' => 'The available records do not establish the entire claim.',
            'sources' => $citations, 'diagnostics' => ['source_coverage' => ['target' => 10, 'cited' => 11]]];
        $records = [];
        foreach (['https://agency.gov.ph/library' => 'Government opening record', 'https://research.edu.ph/library' => 'Institutional library record'] as $url => $title) {
            $records[] = ['url' => $url, 'title' => $title, 'reviewed_claim' => $result['claim'],
                'text' => 'The fictional library opened in 2024, according to a dated record.',
                'source_type' => str_contains($url, '.gov.ph') ? 'official_government' : 'institution',
                'assessment_role' => 'not_used', 'retrieval_status' => 'retrieved'];
        }
        $records[] = ['url' => 'https://agency.gov.ph/unrelated', 'title' => 'Unrelated bridge procurement',
            'text' => 'A different project in another town.', 'source_type' => 'official_government',
            'assessment_role' => 'not_used', 'retrieval_status' => 'retrieved'];
        $detection = Detection::create(['user_id' => $user->id, 'source_kind' => 'text', 'processing_status' => 'completed',
            'verdict' => 'review', 'fake_score' => 0, 'fact_check_result' => $result, 'verification_sources' => $records]);
        $before = $detection->fresh()->getRawOriginal();
        $html = $this->actingAs($user)->get(route('detections.result', $detection))->assertOk()->getContent();
        $this->assertStringContainsString('Cited record 11', $html);
        $this->assertStringContainsString('11 distinct relevant sources cited.', $html);
        $this->assertStringContainsString('Additional relevant sources (2)', $html);
        $this->assertStringContainsString('Government opening record', $html);
        $this->assertStringContainsString('Institutional library record', $html);
        $this->assertStringContainsString('Not used for the verdict', $html);
        $this->assertStringNotContainsString('Unrelated bridge procurement', $html);
        $this->assertSame($before, $detection->fresh()->getRawOriginal());
        Http::assertNothingSent();
        if ($path = getenv('TRUTHGUARD_SOURCE_REFERENCES_BROWSER_FIXTURE')) {
            file_put_contents($path, $html);
        }
    }

    public function test_saved_related_reviews_match_the_claim_instead_of_the_latest_unrelated_reviews(): void
    {
        $this->mock(GoogleFactCheckFeedService::class)->shouldNotReceive('latest');
        $user = User::factory()->create(['privacy_policy_accepted_at' => now()]);
        $claim = 'Suan filed a shoot-to-kill resolution against Bato dela Rosa in 2024';
        foreach (range(1, 9) as $index) {
            PublicClaimReview::create(['feed_item_id' => 'review-'.$index, 'publisher' => 'Fixture publisher',
                'headline' => $index === 1 ? 'Matching older review' : 'Unrelated newest review '.$index,
                'claim' => $index === 1 ? $claim : 'Suan filed a shoot-to-kill resolution against Bato dela Rosa in 2025',
                'url' => 'https://publisher.example/review-'.$index, 'published_at' => now()->subDays(10 - $index), 'last_seen_at' => now()]);
        }
        $detection = Detection::create(['user_id' => $user->id, 'source_kind' => 'text', 'processing_status' => 'completed',
            'verdict' => 'review', 'fake_score' => 0, 'fact_check_result' => ['schema_version' => 4,
                'primary_task' => 'claim', 'claim' => $claim, 'verdict' => 'UNVERIFIED', 'summary' => 'The saved assessment remains unresolved.']]);
        $this->actingAs($user)->get(route('detections.result', $detection))->assertOk()
            ->assertSee('Matching older review')->assertDontSee('Unrelated newest review');
        $detection->update(['fact_check_result' => ['schema_version' => 4, 'primary_task' => 'media',
            'claim' => $claim, 'verdict' => 'INCONCLUSIVE', 'summary' => 'Origin could not be established.']]);
        $this->actingAs($user)->get(route('detections.result', $detection))->assertOk()->assertDontSee('Matching older review');
        Http::assertNothingSent();
    }

    public function test_failed_and_unrelated_fetches_are_not_presented_as_ordinary_evidence_cards(): void
    {
        $user = User::factory()->create(['privacy_policy_accepted_at' => now()]);
        $result = ['primary_task' => 'claim', 'schema_version' => 4, 'verdict' => 'MISSING_CONTEXT',
            'summary' => 'The requested price could not be established.', 'sources' => [],
            'contextual_sources' => [['id' => 'context-1', 'url' => 'https://example.com/fact-check/related',
                'name' => 'Fixture publisher', 'type' => 'fact_check', 'context_only' => true,
                'explanation' => 'The publication provides context, not an authenticated image match.',
                'excerpt' => 'A quoted source passage provides context without establishing the requested price.']],
            'unavailable_fact_checks' => [['url' => 'https://example.com/fact-check/vehicles', 'title' => 'Located fact-check unavailable']]];
        $detection = Detection::create(['user_id' => $user->id, 'source_kind' => 'text',
            'caption_text' => 'A fixture price question', 'processing_status' => 'completed', 'verdict' => 'review', 'fake_score' => 0,
            'fact_check_result' => $result, 'verification_sources' => [
                ['url' => 'https://example.gov/broken-notice', 'title' => 'NoticeNotActiveErrorPage',
                    'retrieval_status' => 'missing_full_text', 'source_type' => 'official_government'],
                ['url' => 'https://example.gov/unrelated', 'title' => 'Unrelated bridge procurement',
                    'retrieval_status' => 'retrieved', 'assessment_role' => 'not_used', 'source_type' => 'official_government'],
            ]]);
        $this->actingAs($user)->get(route('detections.result', $detection))->assertOk()
            ->assertDontSee('NoticeNotActiveErrorPage')->assertDontSee('Unrelated bridge procurement')
            ->assertSee('Reference pages that could not be read')->assertSee('Located fact-check unavailable')
            ->assertSee('Related context')->assertDontSee('Confirmed');
        $this->assertSame($result, $detection->fresh()->fact_check_result);
    }
}
