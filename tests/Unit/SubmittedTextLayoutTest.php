<?php

namespace Tests\Unit;

use App\Services\Detections\Evidence\SubmittedText;
use PHPUnit\Framework\TestCase;

class SubmittedTextLayoutTest extends TestCase
{
    public function test_isolated_short_mark_remains_verbatim_but_separate_from_a_headline(): void
    {
        $visible = "SENATE OF THE PHILIPPINES\nBSKE\n2026\nBSKE POSTPONEMENT:\nA RACE AGAINST TIME —\nAND THE CONSTITUTION\nTOA";

        $this->assertSame(
            "SENATE OF THE PHILIPPINES BSKE\n\n2026 BSKE POSTPONEMENT: A RACE AGAINST TIME — AND THE CONSTITUTION\n\nTOA",
            SubmittedText::paragraphs($visible),
        );
    }
}
