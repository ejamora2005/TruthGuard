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

    public function test_source_backed_rating_and_plain_language_verdict_are_exposed_for_the_result_page(): void
    {
        $detection = new Detection([
            'verdict' => 'real',
            'fake_score' => 12,
            'signals' => [
                'trusted_fact_check' => [[
                    'label' => 'Matched fact-check source rated this claim Confirmed.',
                    'rating' => 'Confirmed',
                ]],
            ],
        ]);

        $presentation = DetectionVerdictPresenter::forDetection($detection);

        $this->assertSame('Confirmed', $presentation['source_rating']);
        $this->assertSame('Likely Real', $presentation['verdict_label']);
        $this->assertStringContainsString('not absolute proof', $presentation['verdict_meaning']);
    }

    public function test_verdict_meaning_does_not_turn_missing_source_evidence_into_certainty(): void
    {
        $detection = new Detection([
            'verdict' => 'real',
            'fake_score' => 18,
            'signals' => [],
            'verification_sources' => [],
        ]);

        $presentation = DetectionVerdictPresenter::forDetection($detection);

        $this->assertNull($presentation['source_rating']);
        $this->assertSame('Likely Real', $presentation['verdict_label']);
        $this->assertStringContainsString('not certainty', $presentation['verdict_meaning']);
    }

    public function test_verified_pipeline_with_only_social_context_becomes_no_official_confirmation(): void
    {
        $detection = new Detection([
            'verdict' => 'real',
            'fake_score' => 18,
            'signals' => [
                'verification_policy' => [['version' => 'test']],
            ],
            'verification_sources' => [[
                'source_type' => 'social_context',
                'url' => 'https://example.com/social-context',
            ]],
        ]);

        $presentation = DetectionVerdictPresenter::forDetection($detection);

        $this->assertTrue($presentation['is_unconfirmed']);
        $this->assertSame('No official confirmation found', $presentation['rating']);
        $this->assertSame('Needs Review', $presentation['verdict_label']);
        $this->assertSame(50, DetectionVerdictPresenter::confidencePercent($detection));
        $this->assertStringContainsString('not proof of falsehood', $presentation['verdict_meaning']);
    }
}
