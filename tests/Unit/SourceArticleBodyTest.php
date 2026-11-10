<?php

namespace Tests\Unit;

use App\Services\Detections\Evidence\ArticleSearch;
use App\Services\Detections\Evidence\ClaimPlanner;
use App\Services\Detections\Evidence\SafeArticleFetcher;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SourceArticleBodyTest extends TestCase
{
    public static function bodyMarkers(): array
    {
        return [['itemprop="articleBody"'], ['class="elementor-element elementor-widget-theme-post-content"'], ['class="entry-content content-body"']];
    }

    #[DataProvider('bodyMarkers')]
    public function test_the_main_post_is_read_instead_of_a_related_article_card(string $marker): void
    {
        $text = 'The agency did not issue the reported class suspension announcement. The original advisory identifies the date and explains which authorities issue official notices.';
        $body = '<html><head><title>Official advisory</title></head><body><div '.$marker.'><p>'.$text.'</p></div>'
            .'<section><h2>Related posts</h2><article><h3>An unrelated September headline</h3><p>A short card.</p></article></section></body></html>';
        $parsed = app(SafeArticleFetcher::class)->html($body, 'https://example.gov.ph/advisory');
        $this->assertSame($text, $parsed['text']);
        $this->assertStringNotContainsString('unrelated', $parsed['text']);
        $this->assertSame('Official advisory', $parsed['title']);
    }

    public function test_agency_searches_keep_the_action_and_event_date(): void
    {
        $queries = app(ClaimPlanner::class)->queries([['kind' => 'factual',
            'text' => 'WALANG PASOK Alinsunod sa anunsyo ng DILG', 'entities' => ['DILG'],
            'dates' => ['August 27, 2025'], 'locations' => [], 'quantities' => [],
            'searches' => ['DILG fake class suspension August 27 2025', 'DILG August 27 2025 official denial']]], 0);
        $this->assertCount(2, $queries);
        $this->assertStringContainsString('site:dilg.gov.ph', $queries[1]);
        $this->assertStringContainsString('walang pasok', strtolower($queries[1]));
        $this->assertStringContainsString('August 27, 2025', $queries[1]);
        $this->assertNotContains('DILG', $queries);
    }

    public function test_share_buttons_and_date_archives_are_not_crawled_as_evidence_documents(): void
    {
        $search = app(ArticleSearch::class);
        foreach (['https://x.com/intent/post?url=https://example.org/report',
            'https://api.whatsapp.com/send?text=example', 'https://www.linkedin.com/shareArticle?url=example',
            'https://example.gov.ph/2025/08/', 'https://example.gov.ph/2025/08/page/4/'] as $url) {
            $this->assertTrue($search->isGenericLandingPage($url), $url);
        }
        $this->assertFalse($search->isGenericLandingPage('https://example.gov.ph/2025/08/actual-advisory/'));
        $this->assertFalse($search->isGenericLandingPage('https://www.linkedin.com/posts/original-statement'));
    }
}
