<?php

namespace Tests\Unit;

use App\Services\Detections\KnownFactCheckCatalog;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class KnownFactCheckCatalogTest extends TestCase
{
    #[Test]
    public function it_returns_the_reviewed_november_13_answer_without_api_usage(): void
    {
        $result = KnownFactCheckCatalog::match(
            'WORLD ENDS WHEN? The end of the world will be on Friday, November 13, 2026, according to a scientist’s mathematical prediction.'
        );

        $this->assertNotNull($result);
        $this->assertSame('MISLEADING', $result['verdict']);
        $this->assertStringContainsString('1960 Science paper', $result['summary']);
        $this->assertStringContainsString('mid-2080s', $result['summary']);
        $this->assertSame(0, data_get($result, 'diagnostics.api_usage.total_tokens'));
        $this->assertCount(2, $result['sources']);
    }

    #[Test]
    public function it_does_not_match_an_unrelated_claim(): void
    {
        $this->assertNull(KnownFactCheckCatalog::match('Classes are suspended in Manila today.'));
    }
}
