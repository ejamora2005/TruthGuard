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

    public function test_guides_route_to_the_intended_task_for_supported_content_types(): void
    {
        foreach (['image', 'video', 'text', 'link'] as $type) {
            foreach (SubmissionInput::GUIDE_PROMPTS as $guide => $prompt) {
                $context = ['caption_text' => $prompt, 'media_type' => $type, 'analysis_task' => $guide === 'media' ? 'media' : 'claim'];
                $expected = $guide === 'context' ? 'context' : ($guide === 'media' ? 'media' : 'claim');
                $this->assertSame($expected, SubmissionTask::primary($context), $type.': '.$guide);
                if ($guide === 'media') $this->assertSame(['media'], SubmissionTask::tasks($context));
            }
        }
    }

    public function test_previous_shipped_prompts_remain_instructions_instead_of_becoming_claims(): void
    {
        $old = [
            'Check whether this image or video shows signs of AI generation or manipulation, and explain what the detector can and cannot establish',
            'Fact-check the exact claim using reliable evidence, including its names, dates, places, numbers, and qualifiers, and explain the verdict and uncertainty',
            'Check whether this content is current and shown in its original date, place, event, and context, and explain what changed or was omitted',
            'Find matching fact-checks and primary sources, compare what each one actually establishes, and explain any disagreement',
        ];
        foreach ($old as $prompt) {
            $this->assertSame('', SubmissionTask::claimText($prompt.'.'));
            $this->assertSame('The bridge reopened.', SubmissionTask::claimText($prompt.'.'."\n\n".'The bridge reopened.'));
        }
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
