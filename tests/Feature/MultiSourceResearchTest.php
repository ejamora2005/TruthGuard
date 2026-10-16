<?php

namespace Tests\Feature;

use App\Models\Detection;
use App\Models\User;
use App\Services\Detections\Evidence\RetrievalBudget;
use App\Services\Detections\Evidence\SafeArticleFetcher;
use App\Services\Detections\OpenAiDetectionReportService;
use App\Services\Detections\SubmissionTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MultiSourceResearchTest extends TestCase
{
    use RefreshDatabase;

    public static function timeScopes(): array
    {
        return [['was the election really postponed?', 'TRUE', 2], ['was the election postponed as of September 22, 2026?', 'FALSE', 1]];
    }

    #[DataProvider('timeScopes')]
    public function test_the_question_and_poster_are_researched_and_dated_sources_survive_submission_reload_and_render(string $question, string $expected, int $reviewed): void
    {
        $documents = json_decode(file_get_contents(base_path('tests/Fixtures/evidence/bske-dated-passages.json')), true)['records'];
        config()->set(['fact_check.enhanced_enabled' => true, 'fact_check.queue_enabled' => false,
            'fact_check.web_search.enabled' => true, 'fact_check.retrieval.max_rounds' => 1,
            'fact_check.retrieval.max_pages' => 4, 'fact_check.retrieval.max_search_requests' => 1,
            'services.openai.key' => 'fixture', 'services.openai.analysis_enabled' => true,
            'services.live_verification.enabled' => true, 'services.filipino_news_classifier.enabled' => false,
            'services.google_fact_check.key' => '', 'services.gnews.key' => '', 'services.newsapi.key' => '']);
        Http::preventStrayRequests();
        $target = SubmissionTask::questionClaim($question);
        $vision = $this->mock(OpenAiDetectionReportService::class);
        $vision->shouldReceive('extractClaimText')->once()->andReturn('BSKE 2026');
        $vision->shouldReceive('extractionDetails')->andReturn(['status' => 'completed', 'visible_text' => "BSKE 2026\n\nBSKE POSTPONEMENT", 'inferred_claim' => '']);
        $this->app->instance(SafeArticleFetcher::class, new class($documents) extends SafeArticleFetcher
        {
            public function __construct(private array $documents) {}

            public function fetch(string $url, RetrievalBudget $budget, bool $checkRobots = true): array
            {
                $budget->take('pages');
                $document = collect($this->documents)->firstWhere('url', $url);
                $text = implode("\n\n", $document['excerpts']);

                return ['url' => $url, 'status' => 'retrieved', 'http_status' => 200, 'retrieved_at' => now()->utc()->toIso8601String(),
                    'published_at' => $document['published_at'], 'updated_at' => null, 'title' => $document['title'],
                    'text' => $text, 'content_hash' => hash('sha256', $text), 'text_truncated' => true, 'links' => [], 'content_type' => 'text/html'];
            }
        });
        Http::fake(function ($request) use ($documents, $question, $target) {
            if (($request['tools'][0]['type'] ?? '') === 'web_search') {
                $input = json_decode($request['input'][1]['content'], true);
                $this->assertSame($question, $input['research_context']['question']);
                $this->assertStringContainsString('BSKE', $input['research_context']['upload_context']);

                return Http::response(['status' => 'completed', 'output' => [
                    ['type' => 'web_search_call', 'status' => 'completed', 'action' => ['type' => 'search', 'sources' => $documents]],
                    ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Dated regression records.', 'annotations' => array_map(fn ($doc) => [
                        'type' => 'url_citation', 'url' => $doc['url'], 'title' => $doc['title'],
                    ], $documents)]]],
                ]]);
            }
            $stage = $request['text']['format']['name'];
            if ($stage === 'claim_decomposition') {
                $reply = ['claims' => [['quote' => $target, 'kind' => 'factual', 'entities' => ['BSKE'], 'dates' => ['2026'],
                    'locations' => [], 'quantities' => [], 'qualifiers' => [], 'requires_context' => false, 'context_reason' => '',
                    'searches' => ['BSKE 2026 postponement', 'BSKE official schedule'],
                    'references' => [['phrase' => 'the election', 'referent' => 'BSKE', 'context_quote' => 'BSKE 2026']]]],
                    'original_claim_date' => null, 'date_quote' => ''];
            } else {
                $input = json_decode($request['input'][1]['content'], true);
                $oldId = collect($input['sources'])->firstWhere('url', $documents[0]['url'])['id'];
                $reply = ['context_findings' => [], 'relationships' => array_map(function ($source) use ($documents, $oldId) {
                    $later = $source['url'] === $documents[1]['url'];

                    return ['claim_id' => 'c1', 'source_id' => $source['id'], 'stance' => $later ? 'supporting' : 'contradicting',
                        'excerpt' => $later ? $documents[1]['excerpts'][0] : $documents[0]['excerpts'][0],
                        'explanation' => $later ? 'The 2026 BSKE was postponed under Republic Act 12326. The next schedule is November 13, 2028.'
                            : 'The proposed postponement had not become law at the September 22 cutoff. The scheduled 2026 election was still set to proceed then.',
                        'matches' => array_fill_keys(['event', 'entities', 'action', 'date', 'location', 'quantities', 'qualifiers'], true),
                        'basis' => 'fact_check', 'supersedes_source_ids' => $later ? [$oldId] : [],
                        'supersession_excerpt' => $later ? $documents[1]['excerpts'][0] : ''];
                }, $input['sources'])];
            }

            return Http::response(['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($reply)]]]]]);
        });
        $user = User::factory()->create();
        $response = $this->actingAs($user)->post(route('detections.store'), [
            'caption_text' => $question, 'analysis_task' => 'claim', 'media_file' => UploadedFile::fake()->image('poster.jpg'),
        ]);
        $detection = Detection::query()->latest('id')->firstOrFail();
        $response->assertRedirect(route('detections.result', $detection));
        $saved = $detection->fresh()->fact_check_result;
        $this->assertSame($expected, $saved['verdict']);
        $this->assertSame($reviewed, $saved['diagnostics']['sources_reviewed']);
        $this->assertStringStartsWith($expected === 'TRUE' ? 'Yes.' : 'No.', $saved['summary']);
        $this->assertCount(1, $saved['sources']);
        if ($expected === 'TRUE') {
            $this->assertStringContainsString('November 13, 2028', $saved['summary']);
            $this->assertTrue($saved['claims'][0]['relationships'][0]['superseded']);
        } else {
            $this->assertSame('2026-09-22T15:59:59+00:00', $saved['evidence_cutoff']);
        }
        $this->getJson(route('detections.result', $detection))->assertOk()->assertJsonPath('verdict', $expected)
            ->assertJsonPath('sources.0.id', $saved['sources'][0]['id'])->assertJsonMissingPath('diagnostics');
        $this->get(route('detections.result', $detection))->assertOk()->assertSee('Source verification')->assertSee('href="#source-verification"', false)
            ->assertSee($saved['sources'][0]['url'], false)->assertDontSee('web_search_call');
    }
}
