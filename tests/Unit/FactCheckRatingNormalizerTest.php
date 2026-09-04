<?php

namespace Tests\Unit;

use App\Services\Detections\FactCheckRatingNormalizer;
use PHPUnit\Framework\TestCase;

class FactCheckRatingNormalizerTest extends TestCase
{
    public function test_preserves_specific_fact_check_categories(): void
    {
        $this->assertSame(
            'AI-generated, misinformation',
            FactCheckRatingNormalizer::normalize('False', 'The image is AI-generated, misinformation.'),
        );

        $this->assertSame(
            'Miscaptioned',
            FactCheckRatingNormalizer::normalize('False', 'Old photo used out of context with a wrong caption.'),
        );

        $this->assertSame(
            'Manipulated media',
            FactCheckRatingNormalizer::normalize('False', 'The video was digitally edited and manipulated.'),
        );

        $this->assertSame(
            'Missing context',
            FactCheckRatingNormalizer::normalize('Needs context', 'The post is missing context.'),
        );
    }

    public function test_maps_categories_to_verdict_tones(): void
    {
        $this->assertSame('fake', FactCheckRatingNormalizer::verdictFor('Miscaptioned'));
        $this->assertSame('fake', FactCheckRatingNormalizer::verdictFor('AI-generated, misinformation'));
        $this->assertSame('fake', FactCheckRatingNormalizer::verdictFor('AI-Generated'));
        $this->assertSame('fake', FactCheckRatingNormalizer::verdictFor('Manipulated'));
        $this->assertSame('review', FactCheckRatingNormalizer::verdictFor('Missing context'));
        $this->assertSame('review', FactCheckRatingNormalizer::verdictFor('No official confirmation found'));
        $this->assertSame('review', FactCheckRatingNormalizer::verdictFor('Unconfirmed'));
        $this->assertSame('real', FactCheckRatingNormalizer::verdictFor('Confirmed'));
    }

    public function test_short_labels_keep_feed_ratings_compact(): void
    {
        $this->assertSame(
            'False',
            FactCheckRatingNormalizer::shortLabel(
                'FACT CHECK: Private prosecutor Mae Divinagracia NO',
                'FACT CHECK: Private prosecutor Mae Divinagracia NOT disbarred for violating impeachment rules',
            ),
        );

        $this->assertSame(
            'False',
            FactCheckRatingNormalizer::shortLabel(
                'MALI-Ta Minute: Mga kwentong-multo at sari-saring',
                'Mga maling balita at disinfo na kumalat ngayong Agosto',
            ),
        );

        $this->assertSame('AI-Generated', FactCheckRatingNormalizer::shortLabel('AI-generated, misinformation'));
        $this->assertSame('Missing Context', FactCheckRatingNormalizer::shortLabel('Missing context'));
        $this->assertSame('Manipulated', FactCheckRatingNormalizer::shortLabel('False. This video has been altered to change what happened'));
        $this->assertSame('Unconfirmed', FactCheckRatingNormalizer::shortLabel('No official confirmation found'));
        $this->assertSame('False', FactCheckRatingNormalizer::shortLabel('Mostly false'));
        $this->assertSame('False', FactCheckRatingNormalizer::shortLabel('Fake', 'Survey graphic is AI-generated.'));
    }
}
