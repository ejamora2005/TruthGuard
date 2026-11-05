<?php

namespace Tests\Unit;

use App\Services\Detections\Evidence\PoorPromptInterpreter;
use Tests\TestCase;

class PoorPromptInterpreterTest extends TestCase
{
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
