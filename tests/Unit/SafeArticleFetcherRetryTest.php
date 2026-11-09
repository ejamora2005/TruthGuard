<?php

namespace Tests\Unit;

use App\Services\Detections\Evidence\RetrievalBudget;
use App\Services\Detections\Evidence\SafeArticleFetcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SafeArticleFetcherRetryTest extends TestCase
{
    public function test_compressed_html_is_decoded_before_article_extraction(): void
    {
        config()->set('fact_check.retrieval.respect_robots', false);
        $html = '<article>'.str_repeat('A readable published record with relevant details. ', 8).'</article>';
        Http::fakeSequence()->push(gzencode($html), 200, ['Content-Type' => 'text/html', 'Content-Encoding' => 'gzip']);
        $fetcher = $this->partialMock(SafeArticleFetcher::class);
        $fetcher->shouldReceive('publicAddress')->andReturn('93.184.216.34');

        $result = $fetcher->fetch('https://example.com/compressed-record', new RetrievalBudget);
        $this->assertSame('retrieved', $result['status']);
        $this->assertStringContainsString('A readable published record', $result['text']);
    }

    public function test_a_fresh_retry_rechecks_a_cached_robots_network_failure(): void
    {
        config()->set('fact_check.retrieval.respect_robots', true);
        Cache::put('evidence-robots:v1:'.hash('sha256', 'https://example.com'), ['status' => 'timeout'], 300);
        Http::fakeSequence()->push("User-agent: *\nAllow: /", 200, ['Content-Type' => 'text/plain'])
            ->push('<article>'.str_repeat('Readable published evidence. ', 8).'</article>', 200, ['Content-Type' => 'text/html']);
        $fetcher = $this->partialMock(SafeArticleFetcher::class);
        $fetcher->shouldReceive('publicAddress')->andReturn('93.184.216.34');
        $budget = new RetrievalBudget;
        $budget->freshSources = true;

        $this->assertSame('retrieved', $fetcher->fetch('https://example.com/record', $budget)['status']);
        Http::assertSentCount(2);
    }

    public function test_decompression_rejects_oversized_invalid_and_unsupported_encoded_content(): void
    {
        config()->set(['fact_check.retrieval.respect_robots' => false, 'fact_check.retrieval.max_bytes' => 1024]);
        Http::fakeSequence()
            ->push(gzencode(str_repeat('Large document. ', 1000)), 200, ['Content-Type' => 'text/html', 'Content-Encoding' => 'gzip'])
            ->push('Invalid gzip bytes', 200, ['Content-Type' => 'text/html', 'Content-Encoding' => 'gzip'])
            ->push('Unsupported encoding bytes', 200, ['Content-Type' => 'text/html', 'Content-Encoding' => 'br'])
            ->push(gzcompress('<article>'.str_repeat('Valid deflate text. ', 10).'</article>'), 200,
                ['Content-Type' => 'text/html', 'Content-Encoding' => 'deflate']);
        $fetcher = $this->partialMock(SafeArticleFetcher::class);
        $fetcher->shouldReceive('publicAddress')->andReturn('93.184.216.34');
        foreach (['invalid_or_oversized_content_encoding', 'invalid_or_oversized_content_encoding',
            'unsupported_content_encoding', 'retrieved'] as $index => $status) {
            $result = $fetcher->fetch('https://example.com/encoded-'.$index, new RetrievalBudget);
            $this->assertSame($status, $result['status']);
            $this->assertLessThanOrEqual(1024, strlen($result['text']));
        }
    }

    public function test_fresh_retry_still_obeys_the_current_robots_disallow_rule(): void
    {
        config()->set('fact_check.retrieval.respect_robots', true);
        Http::fakeSequence()->push("User-agent: *\nDisallow: /", 200, ['Content-Type' => 'text/plain']);
        $fetcher = $this->partialMock(SafeArticleFetcher::class);
        $fetcher->shouldReceive('publicAddress')->andReturn('93.184.216.34');
        $budget = new RetrievalBudget;
        $budget->freshSources = true;
        $result = $fetcher->fetch('https://example.com/record', $budget);
        $this->assertSame('robots_restricted_or_unavailable', $result['status']);
        $this->assertSame('robots_disallow', $result['robots_detail']);
        Http::assertSentCount(1);
    }

    public function test_adjacent_table_cells_and_paragraphs_remain_separate_in_evidence_text(): void
    {
        $page = app(SafeArticleFetcher::class)->html('<article><p>Official volcano list.</p><table>'
            .'<tr><th>No.</th><th>Name</th><th>Province</th></tr>'
            .'<tr><td>18</td><td>Mayon</td><td>Albay, Bicol Region in Luzon</td></tr>'
            .'</table><p>Read the record.</p><p>Check its date.</p></article>', 'https://example.gov.ph/volcanoes');
        $this->assertStringContainsString('No. Name Province', $page['text']);
        $this->assertStringContainsString('18 Mayon Albay, Bicol Region in Luzon', $page['text']);
        $this->assertStringContainsString('Read the record. Check its date.', $page['text']);
        $this->assertStringNotContainsString('18Mayon', $page['text']);
    }

    public function test_transient_server_failure_is_retried_once(): void
    {
        config()->set('fact_check.retrieval.respect_robots', false);
        Http::fakeSequence()
            ->push('', 503, ['Content-Type' => 'text/html'])
            ->push('<html><head><title>Recovered</title></head><body><main>'.str_repeat('Readable evidence. ', 10).'</main></body></html>', 200, ['Content-Type' => 'text/html']);
        $fetcher = $this->partialMock(SafeArticleFetcher::class);
        $fetcher->shouldReceive('publicAddress')->andReturn('93.184.216.34');

        $result = $fetcher->fetch('https://example.com/report', new RetrievalBudget);

        $this->assertSame('retrieved', $result['status']);
        $this->assertStringContainsString('Readable evidence', $result['text']);
        Http::assertSentCount(2);
    }

    #[DataProvider('permanentFailures')]
    public function test_permanent_http_failures_are_not_retried(int $status): void
    {
        config()->set('fact_check.retrieval.respect_robots', false);
        Http::fakeSequence()->push('', $status);
        $fetcher = $this->partialMock(SafeArticleFetcher::class);
        $fetcher->shouldReceive('publicAddress')->andReturn('93.184.216.34');
        $this->assertSame('http_'.$status, $fetcher->fetch('https://example.com/deleted', new RetrievalBudget)['status']);
        Http::assertSentCount(1);
    }

    public static function permanentFailures(): array
    {
        return [[401], [403], [404], [410], [415]];
    }

    public function test_temporary_request_timeout_is_bounded_to_two_attempts(): void
    {
        config()->set('fact_check.retrieval.respect_robots', false);
        Http::fakeSequence()->push('', 408)->push('', 408);
        $fetcher = $this->partialMock(SafeArticleFetcher::class);
        $fetcher->shouldReceive('publicAddress')->andReturn('93.184.216.34');
        $this->assertSame('http_408', $fetcher->fetch('https://example.com/timeout', new RetrievalBudget)['status']);
        Http::assertSentCount(2);
    }

    public function test_a_permanently_failed_link_is_not_fetched_again_in_the_same_request(): void
    {
        config()->set('fact_check.retrieval.respect_robots', false);
        Http::fakeSequence()->push('', 404);
        $fetcher = $this->partialMock(SafeArticleFetcher::class);
        $fetcher->shouldReceive('publicAddress')->andReturn('93.184.216.34');
        $budget = new RetrievalBudget;
        $budget->freshSources = true;
        $fetcher->fetch('https://example.com/deleted', $budget);
        $this->assertTrue($fetcher->fetch('https://example.com/deleted', $budget)['cached']);
        Http::assertSentCount(1);
    }

    public function test_claim_review_metadata_for_a_different_page_or_a_numeric_rating_is_not_admitted(): void
    {
        $schema = ['@graph' => [
            ['@type' => 'ClaimReview', 'url' => 'https://rappler.com/different-page',
                'claimReviewed' => 'A different claim.', 'reviewRating' => ['alternateName' => 'False']],
            ['@type' => 'ClaimReview', 'claimReviewed' => 'Another claim.', 'reviewRating' => ['ratingValue' => 1]],
        ]];
        $page = app(SafeArticleFetcher::class)->html('<script type="application/ld+json">'.json_encode($schema).'</script>'
            .'<article>The actual article body is independent of its metadata.</article>', 'https://rappler.com/this-page');
        $this->assertSame([], $page['claim_reviews']);
        $this->assertSame('The actual article body is independent of its metadata.', $page['text']);
    }
}
