<?php

namespace Tests\Unit;

use App\Models\Detection;
use App\Services\Detections\DetectionVerdictPresenter;
use App\Services\Detections\FactCheckRatingNormalizer;
use Tests\TestCase;

class DetectionVerdictPresenterTest extends TestCase
{
    public function test_ai_generated_detection_uses_specific_public_label(): void
    {
        $detection = new Detection([
            'verdict' => 'fake',
            'fake_score' => 94,
            'signals' => [
                'ai_media_assessment' => [
                    [
                        'label' => 'OpenAI identified the uploaded evidence as AI-generated or synthetic media.',
                        'weight' => 96,
                        'rating' => FactCheckRatingNormalizer::AI_GENERATED_MISINFORMATION,
                    ],
                ],
            ],
        ]);

        $presentation = DetectionVerdictPresenter::forDetection($detection);

        $this->assertSame('AI-GENERATED MEDIA', $presentation['short_label']);
        $this->assertSame('AI-generated media', $presentation['history_label']);
        $this->assertSame('AI-generated media detected', $presentation['notification_label']);
        $this->assertSame('SYNTHETIC MEDIA', $presentation['risk_label']);
    }

    public function test_generic_fake_detection_uses_more_specific_wording_than_fake(): void
    {
        $detection = new Detection([
            'verdict' => 'fake',
            'fake_score' => 88,
            'signals' => [],
        ]);

        $presentation = DetectionVerdictPresenter::forDetection($detection);

        $this->assertSame('LIKELY MISLEADING', $presentation['short_label']);
        $this->assertSame('Likely misleading', $presentation['history_label']);
    }
}
