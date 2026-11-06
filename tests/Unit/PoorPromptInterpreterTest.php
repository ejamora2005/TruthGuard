<?php

namespace Tests\Unit;

use App\Services\Detections\Evidence\PoorPromptInterpreter;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PoorPromptInterpreterTest extends TestCase
{
    public static function completeNames(): array
    {
        return [
            ['shoot to kill order for bato dela rosa', 'Ronald “Bato” dela Rosa'],
            ['shoot to kill order for Bato', 'Ronald “Bato” dela Rosa'],
            ['shoot to kill order for Ronald dela Rosa', 'Ronald dela Rosa'],
            ['shoot to kill order for Ronald "Bato" dela Rosa', 'Ronald "Bato" dela Rosa'],
            ['shoot to kill order for Ronald ‘Bato’ dela Rosa', 'Ronald ‘Bato’ dela Rosa'],
            ['shoot to kill order for Ronald “Bato” dela Rosa', 'Ronald “Bato” dela Rosa'],
            ['Lordan Suan proposed a shoot-to-kill resolution.', 'Lordan Suan'],
            ['PAGASA weather agency forecast', 'PAGASA weather agency'],
        ];
    }

    #[DataProvider('completeNames')]
    public function test_search_aliases_do_not_duplicate_existing_names_or_decide_the_claim(string $text, string $expected): void
    {
        $result = app(PoorPromptInterpreter::class)->interpret($text);
        $this->assertSame($text, $result['original_text']);
        $this->assertSame($text, $result['searches'][0]);
        $this->assertStringContainsString($expected, $result['interpreted_claim']);
        $this->assertSame(1, substr_count(Str::lower($result['interpreted_claim']), Str::lower($expected)));
        $this->assertStringNotContainsString('dela Rosa dela rosa', $result['interpreted_claim']);
        $this->assertStringNotContainsString('Lordan Lordan', $result['interpreted_claim']);
        if (! str_contains(Str::lower($text), 'suan')) {
            $this->assertStringNotContainsString('Suan', implode(' ', $result['searches']));
        }
        $this->assertArrayNotHasKey('verdict', $result);
    }

    public function test_short_rated_prompt_is_expanded_for_research_without_replacing_original_text(): void
    {
        $result = app(PoorPromptInterpreter::class)->interpret('Suan shoot kill Bato fake');

        $this->assertSame('Suan shoot kill Bato fake', $result['original_text']);
        $this->assertStringContainsString('Lordan Suan', $result['interpreted_claim']);
        $this->assertStringContainsString('Ronald “Bato” dela Rosa', $result['interpreted_claim']);
        $this->assertTrue($result['assumption_used']);
        $this->assertGreaterThanOrEqual(2, count($result['searches']));
    }

    public function test_sparse_input_still_produces_searches_and_records_ambiguity(): void
    {
        $result = app(PoorPromptInterpreter::class)->interpret('BBM ayuda');

        $this->assertNotEmpty($result['searches']);
        $this->assertNotEmpty($result['ambiguities']);
        $this->assertStringContainsString('Ferdinand Marcos Jr.', $result['interpreted_claim']);
    }
}
