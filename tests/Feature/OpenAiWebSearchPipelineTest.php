<?php

namespace Tests\Feature;

use App\Models\Detection;
use App\Models\User;
use App\Services\Detections\DetectionPipeline;
use App\Services\Detections\Evidence\RetrievalBudget;
use App\Services\Detections\Evidence\SafeArticleFetcher;
use App\Services\Detections\Evidence\SubmittedText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAiWebSearchPipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_search_document_is_reviewed_persisted_and_cited_after_reload(): void
    {
        $claim = 'NASA launched the uncrewed Artemis I mission on November 16, 2022.';
        $url = 'https://www.nasa.gov/news-release/artemis-i-launch/';
        $passage = 'NASA launched the uncrewed Artemis I mission on November 16, 2022, from Kennedy Space Center.';
        config()->set([
            'fact_check.enhanced_enabled' => true, 'fact_check.web_search.enabled' => true,
            'fact_check.web_search.model' => 'gpt-5.4', 'fact_check.retrieval.max_rounds' => 1,
            'fact_check.retrieval.max_search_requests' => 2, 'fact_check.retrieval.max_pages' => 4,
            'services.openai.key' => 'fixture', 'services.openai.analysis_enabled' => true,
            'services.filipino_news_classifier.enabled' => false,
            'services.google_fact_check.key' => '', 'services.gnews.key' => '', 'services.newsapi.key' => '',
            'services.live_verification.enabled' => true,
        ]);
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($claim, $url, $passage) {
            if (($request['tools'][0]['type'] ?? null) === 'web_search') {
                return Http::response(['status' => 'completed', 'output' => [
                    ['type' => 'web_search_call', 'status' => 'completed', 'action' => ['type' => 'search',
                        'sources' => [['url' => $url]]]],
                    ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'NASA record.', 'annotations' => [
                        ['type' => 'url_citation', 'url' => $url, 'title' => 'Artemis I launch - NASA',
                            'start_index' => 0, 'end_index' => 12],
                    ]]]],
                ], 'usage' => ['input_tokens' => 80, 'output_tokens' => 30, 'total_tokens' => 110]]);
            }
            $stage = $request['text']['format']['name'] ?? '';
            if ($stage === 'claim_decomposition') {
                $reply = ['claims' => [[
                    'quote' => $claim, 'kind' => 'factual', 'entities' => ['NASA', 'Artemis I'],
                    'dates' => ['November 16, 2022'], 'locations' => [], 'quantities' => [],
                    'qualifiers' => ['uncrewed'], 'requires_context' => false, 'context_reason' => '',
                    'searches' => ['NASA Artemis I November 16 2022', 'Artemis I launch NASA'], 'references' => [],
                ]], 'original_claim_date' => null, 'date_quote' => ''];
            } elseif ($stage === 'evidence_relationships_0') {
                $input = json_decode($request['input'][1]['content'], true);
                $sourceId = $input['sources'][0]['id'];
                $reply = ['context_findings' => [], 'relationships' => [[
                    'claim_id' => 'c1', 'source_id' => $sourceId, 'stance' => 'supporting',
                    'excerpt' => $passage,
                    'explanation' => 'NASA states that the uncrewed Artemis I mission launched on November 16, 2022.',
                    'matches' => array_fill_keys(['event', 'entities', 'action', 'date', 'location', 'quantities', 'qualifiers'], true),
                    'basis' => 'primary_record', 'supersedes_source_ids' => [], 'supersession_excerpt' => '',
                ]]];
            } else {
                return Http::response([], 503);
            }

            return Http::response(['status' => 'completed', 'output' => [['type' => 'message',
                'content' => [['type' => 'output_text', 'text' => json_encode($reply)]]]]]);
        });
        $this->app->instance(SafeArticleFetcher::class, new class($url, $passage) extends SafeArticleFetcher
        {
            public function __construct(private string $url, private string $passage) {}

            public function fetch(string $url, RetrievalBudget $budget, bool $checkRobots = true): array
            {
                $budget->take('pages');

                return ['url' => $this->url, 'status' => 'retrieved', 'http_status' => 200,
                    'retrieved_at' => now()->utc()->toIso8601String(), 'published_at' => '2022-11-16T00:00:00Z',
                    'updated_at' => null, 'text' => str_repeat($this->passage.' ', 3),
                    'content_hash' => hash('sha256', $this->passage), 'text_truncated' => false,
                    'content_type' => 'text/html', 'title' => 'Artemis I launch - NASA', 'links' => []];
            }
        });

        $user = User::factory()->create();
        $detection = app(DetectionPipeline::class)->run($user, ['caption_text' => $claim, 'analysis_task' => 'claim'], null);
        $result = Detection::findOrFail($detection->id)->fact_check_result;
        $this->assertSame('TRUE', $result['verdict']);
        $this->assertSame('ok', $result['diagnostics']['providers']['openai_web_search']);
        $this->assertSame(1, $result['diagnostics']['articles_retrieved']);
        $this->assertSame(1, $result['diagnostics']['sources_reviewed']);
        $this->assertSame(1, $result['diagnostics']['sources_used']);
        $this->assertSame($url, $result['sources'][0]['url']);
        $this->assertSame($result['sources'][0]['id'], $result['answer_segments'][0]['source_ids'][0]);
        $this->assertCount(1, $result['sources']);
        $this->assertNotEmpty(SubmittedText::sentences($result['summary']));
        $this->assertStringContainsString('2022', $result['summary']);
        $this->assertStringNotContainsString('heuristic', $result['summary']);
        $this->assertSame($result, $detection->fresh()->fact_check_result);
        Http::assertSent(fn ($request) => ($request['tool_choice'] ?? null) === 'required'
            && ($request['include'] ?? null) === ['web_search_call.action.sources']);

        $html = view('detections.partials.canonical-result-card', [
            'selectedDetection' => $detection, 'result' => $result, 'userReportNumber' => 1,
        ])->render();
        $this->assertStringContainsString('id="evidence-source-1"', $html);
        $this->assertStringContainsString('href="#evidence-source-1"', $html);
        $this->assertStringContainsString('id="sources-to-verify"', $html);
    }
}
