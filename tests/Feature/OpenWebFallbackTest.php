<?php

namespace Tests\Feature;

use App\Services\Detections\Evidence\ArticleSearch;
use App\Services\Detections\EvidenceVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OpenWebFallbackTest extends TestCase
{
    use RefreshDatabase;

    public static function cases(): array
    {
        return [
            'preferred source resolves the claim' => [true, 'supporting', false, 'TRUE'],
            'other reporting supports' => [false, 'supporting', false, 'TRUE'],
            'other reporting refutes' => [false, 'contradicting', false, 'FALSE'],
            'other reporting is insufficient' => [false, 'insufficient', false, 'UNVERIFIED'],
            'fabricated fallback excerpt is rejected' => [false, 'supporting', true, 'UNVERIFIED'],
        ];
    }

    #[DataProvider('cases')]
    public function test_preferred_sources_are_reviewed_first_then_other_readable_reporting_when_needed(bool $preferredResolves, string $fallbackStance, bool $inventExcerpt, string $expected): void
    {
        Http::preventStrayRequests();
        config()->set(['services.openai.analysis_enabled' => true, 'services.openai.key' => 'fixture',
            'services.filipino_news_classifier.enabled' => false, 'services.live_verification.enabled' => true,
            'fact_check.web_search.enabled' => false, 'fact_check.retrieval.max_rounds' => 1,
            'fact_check.max_model_calls' => 0, 'fact_check.report_budget.max_requests' => 0,
            'fact_check.report_budget.stop_after_tokens' => 0]);
        $claim = 'The demonstration library opened on May 4, 2024.';
        $preferred = $preferredResolves
            ? 'The dated municipal record confirms the demonstration library opened on May 4, 2024.'
            : 'The municipal archive lists the demonstration library building but does not give its opening date.';
        $fallback = match ($fallbackStance) {
            'supporting' => 'Reporter Ada Example attended the opening of the demonstration library on May 4, 2024, and quotes the dated opening programme.',
            'contradicting' => 'Reporter Ada Example attended the opening of the demonstration library on May 7, 2024, not May 4, and quotes the dated opening programme.',
            default => 'The demonstration library is a popular local building; this article does not describe its opening or give an opening date.',
        };
        $records = [
            ['id' => 's1', 'url' => 'https://example.gov/library-record', 'source_type' => 'official_government', 'text' => $preferred],
            ['id' => 's2', 'url' => 'https://local-journal.example/library-opening', 'source_type' => 'unapproved', 'text' => $fallback],
        ];
        foreach ($records as &$record) {
            $record += ['name' => 'Synthetic publisher', 'title' => 'Synthetic library record',
                'retrieval_status' => 'retrieved', 'published_at' => '2024-05-05T00:00:00Z',
                'retrieved_at' => now()->utc()->toIso8601String(), 'text_truncated' => false];
        }
        unset($record);
        $search = $this->mock(ArticleSearch::class);
        $search->shouldReceive('retrieve')->once()->andReturn($records);
        $search->shouldReceive('isGenericLandingPage')->andReturn(false);
        $reviewed = [];
        Http::fake(['api.openai.com/*' => function ($request) use ($claim, $preferredResolves, $fallbackStance, $inventExcerpt, &$reviewed) {
            if ($request['text']['format']['name'] === 'claim_decomposition') {
                $data = ['claims' => [['quote' => $claim, 'kind' => 'factual', 'requires_context' => false,
                    'context_reason' => '', 'entities' => [], 'locations' => [], 'dates' => [], 'quantities' => [],
                    'qualifiers' => [], 'references' => [], 'searches' => ['demonstration library opening record']]],
                    'date_quote' => '', 'original_claim_date' => null];
            } else {
                $input = json_decode($request['input'][1]['content'], true);
                $reviewed[] = array_column($input['sources'], 'source_type');
                $rows = [];
                foreach ($input['sources'] as $source) {
                    $fallback = $source['source_type'] === 'other_web';
                    $rows[] = ['claim_id' => 'c1', 'source_id' => $source['id'],
                        'stance' => $fallback ? $fallbackStance : ($preferredResolves ? 'supporting' : 'insufficient'),
                        'basis' => $fallback ? 'direct_reporting' : 'primary_record',
                        'excerpt' => $fallback && $inventExcerpt ? 'An invented passage that never appeared in the article.' : $source['text'],
                        'explanation' => $source['text'],
                        'matches' => array_fill_keys(['event', 'entities', 'action', 'date', 'location', 'quantities', 'qualifiers'], true),
                        'supersedes_source_ids' => [], 'supersession_excerpt' => ''];
                }
                $data = ['context_findings' => [], 'wording' => [], 'relationships' => $rows];
            }

            return Http::response(['status' => 'completed', 'output' => [['type' => 'message', 'content' => [
                ['type' => 'output_text', 'text' => json_encode($data)],
            ]]]]);
        }]);

        $result = app(EvidenceVerificationService::class)->apply(['signals' => []], [
            'caption_text' => $claim, 'media_type' => 'text', 'analysis_task' => 'claim', 'force_refresh' => true,
        ], null)['fact_check_result'];

        $this->assertSame($expected, $result['verdict']);
        $this->assertSame(['official_government'], $reviewed[0]);
        $this->assertSame(! $preferredResolves, $result['diagnostics']['other_web_fallback_reviewed']);
        $this->assertCount($preferredResolves ? 1 : 2, $reviewed);
        if (! $preferredResolves) {
            $this->assertSame(['other_web'], $reviewed[1]);
        }
        if (! $preferredResolves && $expected !== 'UNVERIFIED') {
            $this->assertSame('other_web', $result['sources'][0]['type']);
            $this->assertTrue($result['quality_review']['human_review_recommended']);
            $this->assertStringContainsString('outside the preferred publisher list', implode(' ', $result['limitations']));
        }
        Http::assertSentCount($preferredResolves ? 2 : 3);
    }
}
