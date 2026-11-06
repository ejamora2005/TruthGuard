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
            ['GMA SARADO NA?! HATOL SA KORTE?!', 'GMA Network shutdown due to court order'],
            ['Suan filed a shoot-to-kill resolution against Bato dela Rosa', 'Rep. Suan filed a shoot-to-kill resolution vs Bato dela Rosa'],
            ['Posts on Rep. Suan’s ‘shoot-to-kill’ resolution vs Bato dela Rosa FAKE', 'Suan filed a shoot-to-kill resolution against Bato dela Rosa'],
        ];
    }

    #[DataProvider('materialDifferences')]
    public function test_material_differences_cannot_be_exact(string $submitted, string $reviewed): void
    {
        $match = (new FactCheckClaimMatcher)->assess($submitted, $reviewed);

        $this->assertNotSame('exact', $match['level']);
    }

    public static function materialDifferences(): array
    {
        return [
            'negation in review' => ['Suan filed a resolution against Bato', 'Suan did not file a resolution against Bato'],
            'negation in submission' => ['Suan did not file a resolution against Bato', 'Suan filed a resolution against Bato'],
            'different target' => ['Suan filed a resolution against Bato', 'Suan filed a resolution against Duterte'],
            'reversed roles' => ['Suan filed a resolution against Bato', 'Bato filed a resolution against Suan'],
            'different action' => ['Suan approved a resolution against Bato', 'Suan filed a resolution against Bato'],
            'different quantity' => ['Suan filed 10 resolutions', 'Suan filed 20 resolutions'],
            'different date' => ['Suan filed a resolution on November 5, 2026', 'Suan filed a resolution on November 6, 2026'],
            'different qualifier' => ['Suan allegedly filed a resolution', 'Suan filed a resolution'],
            'additional entity' => ['Suan filed a resolution', 'Suan and Duterte filed a resolution'],
            'swapped quantities' => ['Suan filed 5 resolutions against 10 senators', 'Suan filed 10 resolutions against 5 senators'],
            'swapped actions' => ['Suan filed a resolution before Bato resigned', 'Suan resigned before Bato filed a resolution'],
            'numeric sign' => ['The temperature reached -5 degrees', 'The temperature reached 5 degrees'],
        ];
    }

    public function test_negation_conflict_reports_both_directions(): void
    {
        $matcher = new FactCheckClaimMatcher;
        $this->assertTrue($matcher->assess('Suan filed a resolution', 'Suan did not file a resolution')['negation_conflict']);
        $this->assertFalse($matcher->assess('Suan did not file a resolution', 'Suan did not file a resolution')['negation_conflict']);
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
