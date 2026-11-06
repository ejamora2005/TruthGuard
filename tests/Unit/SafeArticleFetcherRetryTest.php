<?php

namespace Tests\Unit;

use App\Services\Detections\Evidence\RetrievalBudget;
use App\Services\Detections\Evidence\SafeArticleFetcher;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SafeArticleFetcherRetryTest extends TestCase
{
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
