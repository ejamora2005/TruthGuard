<?php

namespace Tests\Unit;

use App\Services\Detections\Evidence\RetrievalBudget;
use App\Services\Detections\Evidence\SafeArticleFetcher;
use Illuminate\Support\Facades\Http;
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
}
