<?php

namespace Tests\Feature;

use App\Services\Detections\Evidence\RetrievalBudget;
use App\Services\Detections\Evidence\SafeArticleFetcher;
use App\Services\Detections\Evidence\SubmissionExtractor;
use App\Services\Detections\OpenAiDetectionReportService;
use Tests\TestCase;

class PastedLinkExtractionTest extends TestCase
{
    public function test_duplicate_url_does_not_hide_the_readable_post_text(): void
    {
        config()->set('services.live_verification.enabled', true);
        $url = 'https://www.facebook.com/share/p/1CJPr4vmtw/';
        $fetcher = $this->mock(SafeArticleFetcher::class);
        $fetcher->shouldReceive('fetch')->once()->andReturn([
            'url' => $url, 'status' => 'retrieved', 'text' => 'The Riverside bridge reopened on November 2, 2026.',
        ]);
        $vision = $this->mock(OpenAiDetectionReportService::class);
        $vision->shouldNotReceive('extractClaimText');
        $record = (new SubmissionExtractor($vision, $fetcher))->extract([
            'source_url' => $url, 'caption_text' => $url,
        ], null, new RetrievalBudget);
        $this->assertSame('The Riverside bridge reopened on November 2, 2026.', $record['analysis_text']);
        $this->assertSame('', $record['submitted_text']);
    }

    public function test_facebook_login_shell_is_not_treated_as_post_content(): void
    {
        config()->set('services.live_verification.enabled', true);
        $fetcher = $this->mock(SafeArticleFetcher::class);
        $fetcher->shouldReceive('fetch')->once()->andReturn([
            'status' => 'retrieved', 'text' => 'Log in to Facebook to see this post.',
        ]);
        $record = (new SubmissionExtractor($this->mock(OpenAiDetectionReportService::class), $fetcher))
            ->extract(['source_url' => 'https://www.facebook.com/share/p/example/'], null, new RetrievalBudget);
        $this->assertSame('', $record['analysis_text']);
        $this->assertSame('login_or_unavailable', $record['source_record']['status']);
    }
}
