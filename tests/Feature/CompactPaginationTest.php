<?php

namespace Tests\Feature;

use App\Models\Detection;
use App\Models\User;
use App\Services\Detections\GoogleFactCheckFeedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CompactPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_history_page_links_and_picker_keep_search_and_sort_filters(): void
    {
        Http::preventStrayRequests();
        config()->set('firebase.enabled', false);
        $user = User::factory()->create();
        foreach (range(1, 35) as $index) {
            Detection::create(['user_id' => $user->id, 'media_type' => 'text', 'source_kind' => 'text',
                'caption_text' => 'Pagination sample '.$index, 'processing_status' => 'completed',
                'verdict' => 'review', 'fake_score' => 0, 'analyzed_at' => now()->subMinutes($index)]);
        }
        $this->actingAs($user);
        $query = ['search' => 'Pagination', 'sort' => 'oldest', 'status' => 'all'];
        foreach ([1, 2, 3] as $page) {
            $response = $this->get(route('history', $query + ['page' => $page]))->assertOk();
            $this->checkPager($response->getContent(), $page, 3, $query, 'page', 'history');
        }
        Http::assertNothingSent();
    }

    public static function reviewLists(): array
    {
        return ['dashboard' => ['dashboard', 'fact_page', false], 'public' => ['reviews.index', 'page', true]];
    }

    #[DataProvider('reviewLists')]
    public function test_review_page_links_and_picker_keep_filters(string $route, string $pageName, bool $guest): void
    {
        Http::preventStrayRequests();
        config()->set('firebase.enabled', false);
        $items = array_map(fn (int $index) => ['id' => 'sample-'.$index,
            'headline' => 'Pagination sample '.$index, 'claim' => 'Pagination sample claim',
            'publisher' => 'Example newsroom', 'rating' => 'False', 'url' => 'https://example.org/'.$index,
            'date_label' => 'Oct 19, 2026'], range(1, $guest ? 35 : 25));
        $this->mock(GoogleFactCheckFeedService::class)->shouldReceive('latest')->andReturn(['items' => $items]);
        if (! $guest) {
            $this->actingAs(User::factory()->create());
        }
        $query = ['review_search' => 'Pagination', 'review_category' => 'fake-news'];
        foreach ([1, 2, 3] as $page) {
            $response = $this->get(route($route, $query + [$pageName => $page]))->assertOk();
            $this->checkPager($response->getContent(), $page, 3, $query, $pageName, $guest ? 'public' : 'dashboard');
        }
        Http::assertNothingSent();
    }

    private function checkPager(string $html, int $current, int $last, array $filters, string $pageName, string $fixture): void
    {
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($document);
        $options = $xpath->query('//select[contains(@class,"tg-pagination-select")]/option');
        $this->assertCount($last, $options);
        foreach ($options as $index => $option) {
            parse_str((string) parse_url($option->getAttribute('value'), PHP_URL_QUERY), $query);
            $this->assertSame($index + 1, (int) ($query[$pageName] ?? 1));
            $this->assertSame($index + 1 === $current, $option->hasAttribute('selected'));
            foreach ($filters as $key => $value) {
                $this->assertSame($value, $query[$key] ?? null);
            }
        }
        foreach (['prev' => $current - 1, 'next' => $current + 1] as $direction => $page) {
            $links = $xpath->query('//nav[contains(@class,"tg-pagination-controls")]/a[@rel="'.$direction.'"]');
            $valid = $page >= 1 && $page <= $last;
            $this->assertCount($valid ? 1 : 0, $links);
            if ($valid) {
                $this->assertSame($options[$page - 1]->getAttribute('value'), $links[0]->getAttribute('href'));
            }
        }
        if (getenv('TRUTHGUARD_PAGINATION_BROWSER_FIXTURE')) {
            $directory = storage_path('tmp-pagination');
            if (! is_dir($directory)) {
                mkdir($directory, 0755, true);
            }
            file_put_contents($directory.'/'.$fixture.'-'.$current.'.html', $html);
        }
    }
}
