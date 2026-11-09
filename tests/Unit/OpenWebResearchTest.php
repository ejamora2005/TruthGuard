<?php

namespace Tests\Unit;

use App\Services\Detections\Evidence\ArticleSearch;
use App\Services\Detections\Evidence\ClaimPlanner;
use App\Services\Detections\Evidence\EvidenceJudge;
use App\Services\Detections\Evidence\RetrievalBudget;
use App\Services\Detections\Evidence\SafeArticleFetcher;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenWebResearchTest extends TestCase
{
    public function test_article_links_prioritize_research_and_institutions_without_discarding_other_public_sources(): void
    {
        Http::preventStrayRequests();
        config()->set('fact_check.retrieval.respect_robots', false);
        $html = '<html><body><article><p>'.str_repeat('Synthetic readable reporting. ', 5).'</p>'
            .'<a href="https://specialist.example/research-record">Specialist source</a>'
            .'<a href="https://science.org/doi/10.example/study">Original research</a>'
            .'<a href="https://who.int/publications/evidence-review">Evidence review</a>'
            .'<a href="https://agency.gov/public/record">Public record</a>'
            .'<a href="https://science.org.attacker.example/paper">Other publisher</a>'
            .'<a href="http://127.0.0.1/private">Private</a><a href="javascript:alert(1)">Script</a>'
            .'</article></body></html>';
        Http::fake(['https://reporter.example/article' => Http::response($html, 200, ['Content-Type' => 'text/html'])]);
        $fetcher = $this->partialMock(SafeArticleFetcher::class);
        $fetcher->shouldReceive('publicAddress')->andReturn('93.184.216.34');

        $page = $fetcher->fetch('https://reporter.example/article', new RetrievalBudget);

        $this->assertSame('retrieved', $page['status']);
        $this->assertSame([
            'https://science.org/doi/10.example/study', 'https://who.int/publications/evidence-review',
            'https://agency.gov/public/record', 'https://specialist.example/research-record',
            'https://science.org.attacker.example/paper',
        ], $page['links']);
        Http::assertSentCount(1);
    }

    public function test_a_linked_research_paper_retains_its_research_type(): void
    {
        config()->set(['fact_check.web_search.enabled' => false,
            'services.google_fact_check.key' => '', 'services.gnews.key' => '', 'services.newsapi.key' => '',
            'services.live_verification.enabled' => true]);
        $paper = 'https://science.org/doi/10.example/study';
        $article = 'https://reporter.example/article';
        $this->mock(SafeArticleFetcher::class)->shouldReceive('fetch')->andReturnUsing(fn ($url) => [
            'url' => $url, 'status' => 'retrieved', 'text' => str_repeat('Synthetic research passage. ', 5),
            'title' => 'Synthetic source', 'published_at' => '2026-01-01T00:00:00Z', 'updated_at' => null,
            'retrieved_at' => now()->utc()->toIso8601String(), 'text_truncated' => false,
            'links' => $url === $article ? [$paper] : [],
        ]);
        $records = app(ArticleSearch::class)->retrieve([], new RetrievalBudget, now()->utc()->toIso8601String(), [
            $article => ['id' => 's1', 'url' => $article, 'title' => 'Synthetic report', 'name' => 'Synthetic publisher',
                'source_type' => 'unapproved', 'provider' => 'fixture', 'retrieval_status' => 'discovered'],
        ]);

        $record = collect($records)->firstWhere('url', $paper);
        $this->assertNotNull($record);
        $this->assertSame('research', $record['source_type']);
        $this->assertSame('linked_primary_record', $record['provider']);
    }

    public function test_foreign_and_global_topics_are_not_assigned_a_philippine_authority(): void
    {
        foreach (['The school in Tokyo closed.', 'A global vaccine study reported a benefit.', 'An earthquake occurred in Japan.'] as $text) {
            $claim = ['kind' => 'factual', 'text' => $text, 'entities' => [], 'locations' => [],
                'quantities' => [], 'dates' => [], 'searches' => [$text, 'original record for '.$text]];
            $queries = app(ClaimPlanner::class)->queries([$claim], 0);
            $this->assertStringNotContainsString('site:', implode(' ', $queries));
            $this->assertCount(2, $queries);
        }
    }

    public function test_a_fallback_publisher_cannot_claim_primary_or_trusted_fact_check_status(): void
    {
        $text = 'A named reporter quotes the dated original library opening programme.';
        $claim = ['id' => 'c1', 'text' => 'The library opened in May 2024.', 'kind' => 'factual', 'requires_context' => false];
        $source = ['id' => 's1', 'url' => 'https://specialist.example/report', 'text' => $text, 'source_type' => 'other_web'];
        foreach (['primary_record', 'fact_check'] as $basis) {
            $row = ['claim_id' => 'c1', 'source_id' => 's1', 'stance' => 'supporting', 'basis' => $basis,
                'excerpt' => $text, 'explanation' => $text,
                'matches' => array_fill_keys(['event', 'entities', 'action', 'date', 'location', 'quantities', 'qualifiers'], true),
                'supersedes_source_ids' => [], 'supersession_excerpt' => ''];
            $this->assertSame('insufficient', app(EvidenceJudge::class)->validate([$row], [$claim], [$source])[0]['stance']);
        }
    }
}
