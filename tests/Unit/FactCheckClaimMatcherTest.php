<?php

namespace Tests\Unit;

use App\Services\Detections\FactCheckClaimMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FactCheckClaimMatcherTest extends TestCase
{
    #[DataProvider('exactClaims')]
    public function test_semantically_equivalent_claims_are_exact(string $submitted, string $reviewed): void
    {
        $match = (new FactCheckClaimMatcher)->assess($submitted, $reviewed);

        $this->assertSame('exact', $match['level']);
        $this->assertTrue($match['entity_match']);
        $this->assertTrue($match['action_match']);
        $this->assertNotEmpty($match['canonical_key']);
    }

    public static function exactClaims(): array
    {
        return [
            ['GMA SARADO NA?! HATOL SA KORTE?!', 'No court order for GMA Network shutdown'],
            ['Suan filed a shoot-to-kill resolution against Bato dela Rosa', 'Rep. Suan did not file a shoot-to-kill resolution vs Bato dela Rosa'],
        ];
    }

    public function test_shared_publisher_or_generic_words_do_not_create_a_match(): void
    {
        $match = (new FactCheckClaimMatcher)->assess(
            'GMA Network was ordered shut down by a court',
            'Rappler fact check: earthquake advisory in Cebu',
        );

        $this->assertSame('unrelated', $match['level']);
    }

    public function test_conflicting_material_years_are_not_exact(): void
    {
        $match = (new FactCheckClaimMatcher)->assess(
            'Toby Tiangco said Marcos will be impeached in 2027',
            'Toby Tiangco statement about impeachment in 2025',
        );

        $this->assertSame('unrelated', $match['level']);
        $this->assertFalse($match['date_match']);
    }
}
