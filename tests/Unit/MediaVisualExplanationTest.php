<?php

namespace Tests\Unit;

use App\Services\Detections\MediaAnalysisReport;
use App\Services\Detections\MediaVisualExplanation;
use App\Services\Detections\ReportAnswer;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MediaVisualExplanationTest extends TestCase
{
    private function assessment(string $label, ?array $observations): array
    {
        $hash = str_repeat('a', 64);
        $result = MediaAnalysisReport::result([
            'status' => 'completed', 'media_type' => 'image', 'label' => $label,
            'fake_probability' => $label === 'fake' ? .99 : .01, 'input_sha256' => $hash,
            'label_mapping' => ['real', 'fake'],
            'output_contract' => ['activation' => 'sigmoid', 'positive_class' => 'fake', 'binary_exclusive' => true],
        ], []);
        if ($observations !== null) {
            $result['visual_observations'] = MediaVisualExplanation::accept([
                'image_description' => '', 'observations' => $observations,
            ], $hash);
            $this->assertNotNull($result['visual_observations']);
        }

        return $result;
    }

    public function test_ready_answer_leads_with_the_finding_and_keeps_the_assessment_unchanged(): void
    {
        Http::preventStrayRequests();
        $detail = 'Two fingers on the left hand blend into one shape.';
        foreach (['fake', 'real'] as $label) {
            $saved = $this->assessment($label, [['region' => 'the left hand', 'detail' => $detail,
                'kind' => 'possible_artifact', 'visibility' => 'clear']]);
            $before = $saved;
            $public = ReportAnswer::forPresentation($saved);

            $this->assertStringStartsWith($label === 'fake' ? 'This image has been flagged as likely AI-generated.' : 'This image is likely not AI-generated.', $public['summary']);
            $this->assertStringContainsString($detail, $public['summary']);
            $this->assertStringContainsString('do not by themselves prove AI generation', $public['summary']);
            $this->assertDoesNotMatchRegularExpression('/detector|model|system|score|threshold|classification|%/i', $public['summary']);
            $this->assertSame($saved['verdict'], $public['verdict']);
            $this->assertSame($saved['score']['value'], $public['score']['value']);
            $this->assertSame($before, $saved);
        }
        Http::assertNothingSent();
    }

    public function test_quality_limitations_are_not_described_as_generation_artifacts(): void
    {
        $detail = 'The small lettering in the background is too blurred to read.';
        $result = $this->assessment('real', [['region' => 'the background', 'detail' => $detail,
            'kind' => 'image_quality', 'visibility' => 'clear']]);
        $public = MediaVisualExplanation::present($result);

        $this->assertStringStartsWith('This image is likely not AI-generated.', $public['summary']);
        $this->assertStringContainsString($detail, $public['summary']);
        $this->assertStringContainsString('limit what can be checked visually', $public['summary']);
        $this->assertStringNotContainsString('visible irregularities', $public['summary']);
        $this->assertStringNotContainsString('because', $public['summary']);
    }

    public function test_missing_visual_review_never_claims_that_no_artifacts_were_found(): void
    {
        foreach (['real', 'fake'] as $label) {
            $result = $this->assessment($label, null);
            $public = MediaVisualExplanation::present($result);
            $this->assertStringContainsString('appearance alone does not establish', $public['summary']);
            $this->assertStringNotContainsString('were identified', $public['summary']);
            $this->assertSame($result['verdict'], $public['verdict']);
            $this->assertSame($result['score'], $public['score']);
        }
    }

    public function test_completed_visual_review_with_no_artifacts_does_not_invent_an_explanation(): void
    {
        $real = MediaVisualExplanation::present($this->assessment('real', []));
        $fake = MediaVisualExplanation::present($this->assessment('fake', []));

        $this->assertStringContainsString('No clear visual signs of AI generation were identified', $real['summary']);
        $this->assertStringStartsWith('This image has been flagged as likely AI-generated.', $fake['summary']);
        $this->assertStringContainsString('did not identify a specific irregularity', $fake['summary']);
        $this->assertSame('LIKELY_AI_GENERATED', $fake['verdict']);
        $this->assertStringNotContainsString('hands', $fake['summary']);
    }

    public function test_observations_for_another_image_are_not_shown(): void
    {
        $result = $this->assessment('fake', [['region' => 'the left hand', 'detail' => 'The left hand has a repeated fingertip.',
            'kind' => 'possible_artifact', 'visibility' => 'clear']]);
        $result['visual_observations']['input_sha256'] = str_repeat('b', 64);

        $public = MediaVisualExplanation::present($result);
        $this->assertStringNotContainsString('fingertip', $public['summary']);
        $this->assertStringContainsString('appearance alone does not establish', $public['summary']);
    }

    public function test_ready_answers_cover_video_and_inconclusive_findings_without_extra_requests(): void
    {
        Http::preventStrayRequests();
        foreach (['image', 'video'] as $subject) {
            foreach (['LIKELY_AI_GENERATED', 'AI_GENERATED', 'LIKELY_NOT_AI_GENERATED', 'NO_STRONG_AI_SIGNAL', 'INCONCLUSIVE'] as $verdict) {
                $saved = $this->assessment('fake', []);
                $saved['verdict'] = $verdict;
                $saved['media_assessment']['media_type'] = $subject;
                $public = MediaVisualExplanation::present($saved);
                $opening = match ($verdict) {
                    'LIKELY_AI_GENERATED', 'AI_GENERATED' => "This {$subject} has been flagged as likely AI-generated.",
                    'LIKELY_NOT_AI_GENERATED', 'NO_STRONG_AI_SIGNAL' => "This {$subject} is likely not AI-generated.",
                    default => "The available evidence does not establish whether this {$subject} was AI-generated.",
                };
                $this->assertStringStartsWith($opening, $public['summary']);
                $this->assertSame($saved['verdict'], $public['verdict']);
                $this->assertSame($saved['score'], $public['score']);
                $this->assertSame($public['summary'], $public['answer_segments'][0]['text']);
                if ($subject === 'video') {
                    $this->assertStringContainsString('Only selected video frames were checked', $public['summary']);
                    $this->assertStringNotContainsString('visual review did not identify', $public['summary']);
                }
            }
        }
        Http::assertNothingSent();
    }
}
