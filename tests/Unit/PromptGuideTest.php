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
        foreach (['image', 'video', 'text', 'link', 'document'] as $type) {
            foreach (SubmissionInput::GUIDE_PROMPTS as $guide => $prompt) {
                $context = ['caption_text' => $prompt, 'media_type' => $type, 'analysis_task' => $guide === 'media' ? 'media' : 'claim'];
                $expected = $guide === 'context' ? 'context' : ($guide === 'media' ? 'media' : 'claim');
                $this->assertSame($expected, SubmissionTask::primary($context), $type.': '.$guide);
                if ($guide === 'media') {
                    $this->assertSame(['media'], SubmissionTask::tasks($context));
                }
            }
        }
    }

    public function test_previous_shipped_prompts_remain_instructions_instead_of_becoming_claims(): void
    {
        $old = [
            'Check the supplied image or video for AI generation, explain the recorded detector finding and its limits, and do not invent visual evidence or replace the answer with a scene description',
            'Fact-check the exact claim in the supplied content against reliable sources, preserve its names, dates and qualifiers, and explain what the evidence supports or contradicts',
            'Check the original date, place and context of the supplied content, and explain whether it is current, reused, miscaptioned or missing important context',
            'Fact-check the supplied content using matching fact-checks and primary sources, and explain which references directly address the claim rather than merely discussing the same topic',
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

    public static function factualContent(): array
    {
        return [
            'public announcement' => ['The demonstration city cancelled classes on May 4, 2024.'],
            'research attribution' => ['The demonstration paper projects a population change if its assumptions hold.'],
            'statistics' => ['The demonstration dataset reports 23 percent of 500 respondents in 2024.'],
            'quotation' => ['The fictional mayor said "The library will open in June."'],
            'legal status' => ['The demonstration proposal was enacted on May 4, 2024.'],
            'historical event' => ['The fictional museum opened in 1924.'],
            'product claim' => ['The demonstration product has a 24-month warranty.'],
            'Filipino question' => ['Totoo bang walang pasok bukas sa Maynila?'],
            'Chinese statement' => ['这个示例公告称图书馆将于五月开放。'],
            'arithmetic' => ['1 + 1 = 2'],
        ];
    }

    #[DataProvider('factualContent')]
    public function test_every_guide_preserves_the_supplied_topic_and_language(string $content): void
    {
        foreach (SubmissionInput::GUIDE_PROMPTS as $prompt) {
            $combined = $prompt."\n\n".$content;
            $this->assertTrue(SubmissionInput::hasEvidenceText($combined));
            $this->assertSame(SubmissionTask::claimText($content), SubmissionTask::claimText($combined));
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
        $this->assertStringContainsString('40-90 words and three or four', $instructions);
        $this->assertStringContainsString('rather than returning a quotation alone', $instructions);
        foreach ([$instructions, ResultWording::evidenceInstructions()] as $guide) {
            $this->assertStringContainsString('Start with natural attribution and the concrete finding', $guide);
            $this->assertStringContainsString('why the evidence supports or contradicts the exact claim', $guide);
            $this->assertStringContainsString('never invent facts', $guide);
            $this->assertStringContainsString('source organization to check before sharing', $guide);
        }
    }
}
