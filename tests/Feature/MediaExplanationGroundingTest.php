<?php

namespace Tests\Feature;

use App\Services\Detections\MediaAnalysisReport;
use App\Services\Detections\OpenAiMediaExplanation;
use App\Services\Detections\ReportAnswer;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MediaExplanationGroundingTest extends TestCase
{
    public static function unsupportedDetails(): array
    {
        return [
            ['The image contains six fingers and distorted hands, so the detector marks it as generated.'],
            ['The image was created with Midjourney, according to the detector output provided here.'],
            ['The metadata proves this image is generated, confirming the detector assessment of its origin.'],
        ];
    }

    #[DataProvider('unsupportedDetails')]
    public function test_unsupplied_visual_or_provenance_claims_are_rejected(string $explanation): void
    {
        Http::preventStrayRequests();
        config()->set(['services.openai.analysis_enabled' => true, 'services.openai.media_explanation_enabled' => true,
            'services.openai.key' => 'offline-fixture-key']);
        $media = ['status' => 'completed', 'media_type' => 'image', 'label' => 'fake', 'fake_probability' => .97];
        $result = ReportAnswer::complete(MediaAnalysisReport::result($media, []));
        Http::fake(['api.openai.com/v1/responses' => Http::response(['status' => 'completed',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 10, 'total_tokens' => 20],
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode([
                'verdict' => $result['verdict'], 'explanation' => $explanation,
                'result_summary' => 'The detector output favors the synthetic class within the recorded screening limits.',
                'recommended_action' => 'Check the original creation record.', 'source_ids' => [],
            ])]]]]])]);
        $analysis = ['fact_check_result' => $result, 'signals' => []];
        $after = app(OpenAiMediaExplanation::class)->apply($analysis);

        $this->assertSame($result, $after['fact_check_result']);
        $this->assertSame('unavailable', $after['signals']['media_explanation'][0]['status']);
        $this->assertSame(20, $after['signals']['openai_usage']['total_tokens']);
        Http::assertSentCount(1);
    }
}
