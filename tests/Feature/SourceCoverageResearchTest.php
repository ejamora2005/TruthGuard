<?php

namespace Tests\Feature;

use App\Models\Detection;
use App\Models\User;
use App\Services\Detections\Evidence\ArticleSearch;
use App\Services\Detections\Evidence\ClaimPlanner;
use App\Services\Detections\EvidenceVerificationService;
use App\Services\Detections\GoogleFactCheckFeedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SourceCoverageResearchTest extends TestCase
{
    use RefreshDatabase;

    private const CLAIM = 'The fictional library opened on May 1, 2024.';

    public static function availability(): array
    {
        return ['many sources' => [12, 12, true], 'fewer available' => [3, 3, false], 'one source' => [1, 1, false],
            'exact published review still expands' => [12, 12, true, true],
            'deadline retains completed findings' => [12, 1, false, false, true]];
    }

    #[DataProvider('availability')]
    public function test_resolution_does_not_skip_available_source_coverage(int $available, int $expected, bool $met, bool $exact = false, bool $expireAfterReview = false): void
    {
        config()->set(['fact_check.min_relevant_sources' => 10, 'fact_check.progressive_research' => true,
            'fact_check.unrestricted_research' => true, 'fact_check.web_search.enabled' => false,
            'services.openai.analysis_enabled' => true, 'services.openai.key' => 'fixture',
            'services.filipino_news_classifier.enabled' => false]);
        $claim = ['id' => 'c1', 'text' => self::CLAIM, 'kind' => 'factual', 'requires_context' => false,
            'context_reason' => '', 'references' => [], 'searches' => ['fictional library opening']];
        $planner = $this->mock(ClaimPlanner::class);
        $planner->shouldReceive('plan')->andReturn(['status' => 'completed', 'original_claim_date' => null, 'claims' => [$claim]]);
        $planner->shouldReceive('queries')->andReturn(['fictional library opening']);
        $records = [];
        foreach (range(1, $available) as $index) {
            $records[] = ['id' => 's'.$index, 'name' => 'Municipal archive '.$index, 'title' => 'Library opening record '.$index,
                'url' => 'https://archive'.$index.'.gov.ph/library', 'retrieval_status' => 'retrieved',
                'source_type' => 'official_government', 'text' => self::CLAIM.' Signed entry from archive '.$index.'.',
                'content_hash' => 'record-'.$index, 'published_at' => '2024-05-02T00:00:00Z',
                'updated_at' => null, 'retrieved_at' => now()->utc()->toIso8601String(), 'text_truncated' => false];
        }
        if ($exact) {
            $records[0] = array_replace($records[0], ['url' => 'https://rappler.com/fact-check/fictional-library',
                'name' => 'Rappler', 'source_type' => 'fact_check', 'reviewed_claim' => self::CLAIM, 'review_rating' => 'True']);
        }
        $calls = 0;
        $search = $this->partialMock(ArticleSearch::class);
        $search->shouldReceive('prefetchExactReviews')->andReturn([]);
        $search->shouldReceive('retrieve')->andReturnUsing(function ($queries, $budget, $cutoff, $existing, $reserve, $ceiling, $discovery, $onBatch) use (&$calls, $records) {
            $calls++;
            $rows = $calls === 1 ? [$records[0]] : $records;
            $decision = $onBatch($rows);
            if ($decision === 'resolved') $budget->discoveryStop = 'evidence_resolved';
            return $rows;
        });
        $reviewed = [];
        Http::fake(['api.openai.com/*' => function ($request) use (&$reviewed, $expireAfterReview) {
            $input = json_decode($request['input'][1]['content'], true);
            $rows = [];
            foreach ($input['sources'] as $source) {
                $this->assertNotContains($source['id'], $reviewed, 'Previously reviewed source text must not be sent again.');
                $reviewed[] = $source['id'];
                $rows[] = ['claim_id' => 'c1', 'source_id' => $source['id'], 'stance' => 'supporting',
                    'excerpt' => self::CLAIM, 'explanation' => self::CLAIM, 'basis' => 'primary_record',
                    'matches' => array_fill_keys(['event', 'entities', 'action', 'date', 'location', 'quantities', 'qualifiers'], true),
                    'supersedes_source_ids' => [], 'supersession_excerpt' => ''];
            }
            if ($expireAfterReview) {
                $budget = app(\App\Services\Detections\Evidence\ReportApiBudget::class);
                (new \ReflectionProperty($budget, 'startedAt'))->setValue($budget, microtime(true) - 121);
            }
            return Http::response(['status' => 'completed',
                'output' => [['type' => 'message', 'content' => [['type' => 'output_text',
                    'text' => json_encode(['relationships' => $rows, 'context_findings' => [], 'wording' => [], 'scope_resolutions' => []])]]]],
                'usage' => ['input_tokens' => 100, 'output_tokens' => 100]]);
        }]);
        $result = app(EvidenceVerificationService::class)->apply(['signals' => []], [
            'analysis_task' => 'claim', 'media_type' => 'text', 'caption_text' => self::CLAIM,
        ])['fact_check_result'];
        $this->assertSame('TRUE', $result['verdict']);
        if ($expireAfterReview) {
            $this->assertSame(1, $calls);
            $this->assertTrue($result['diagnostics']['api_budget']['time_budget_exhausted']);
            $this->assertStringContainsString('processing time budget', implode(' ', $result['limitations']));
        } else {
            $this->assertGreaterThan(1, $calls, 'A resolved first source must not end the coverage search.');
        }
        $this->assertCount($expected, $result['sources']);
        $this->assertSame($expected, $result['diagnostics']['source_coverage']['cited']);
        $this->assertSame($met, $result['diagnostics']['source_coverage']['target_met']);
        $this->assertCount($expected, $reviewed);
        $this->mock(GoogleFactCheckFeedService::class)->shouldNotReceive('latest');
        $user = User::factory()->create();
        $detection = Detection::create(['user_id' => $user->id, 'source_kind' => 'text', 'media_type' => 'text',
            'processing_status' => 'completed', 'verdict' => 'real', 'fake_score' => 0,
            'analyzed_at' => now(), 'fact_check_result' => $result]);
        $this->actingAs($user)->get(route('detections.result', $detection))->assertOk()
            ->assertSee($expected.' distinct relevant sources cited.')
            ->assertSee($met ? 'The coverage goal of 10 sources was met.' : 'Fewer than the goal of 10 qualified');
        Http::assertSentCount($available > 1 && ! $exact && ! $expireAfterReview ? 2 : 1);
    }
}
