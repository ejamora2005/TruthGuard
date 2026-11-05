<?php

namespace Tests\Unit;

use App\Services\Detections\ResultWording;
use App\Services\Detections\SubmissionInput;
use App\Services\Detections\SubmissionTask;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PromptGuideTest extends TestCase
{
    public static function guides(): array
    {
        return array_map(fn (string $prompt): array => [$prompt], SubmissionInput::GUIDE_PROMPTS);
    }

    #[DataProvider('guides')]
    public function test_a_guide_alone_is_not_treated_as_user_evidence(string $prompt): void
    {
        $this->assertFalse(SubmissionInput::hasEvidenceText($prompt));
        $this->assertSame('', SubmissionTask::claimText($prompt));
    }

    #[DataProvider('guides')]
    public function test_a_guide_preserves_the_users_actual_content(string $prompt): void
    {
        $claim = 'The city cancelled classes on November 5, 2026.';

        $this->assertTrue(SubmissionInput::hasEvidenceText($prompt."\n\n".$claim));
        $this->assertSame($claim, SubmissionTask::claimText($prompt."\n\n".$claim));
    }

    public function test_result_wording_guide_covers_distinct_result_and_content_types(): void
    {
        $instructions = ResultWording::instructions();

        $this->assertStringContainsString('Do not call a claim false merely because evidence is absent', $instructions);
        $this->assertStringContainsString('Misleading, partly true, missing context, or out of context', $instructions);
        $this->assertStringContainsString('Opinion, satire, parody, or non-factual content', $instructions);
        $this->assertStringContainsString('Image, video, document, screenshot, post, or link', $instructions);
        $this->assertStringContainsString('keep media authenticity, content provenance, and factual claim accuracy separate', $instructions);
    }
}
