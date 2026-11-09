<?php

namespace Tests\Feature;

use App\Services\Detections\Evidence\ArticleSearch;
use App\Services\Detections\EvidenceVerificationService;
use App\Services\Detections\SubmissionTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VisualClaimContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_visible_objects_guide_research_without_becoming_transcribed_claims_or_price_evidence(): void
    {
        // These simulated vision outputs exercise data boundaries, not image accuracy.
        $quote = '10 million pesos bawat isa?';
        $visual = 'The caption is placed over several red three-wheeled fire-response vehicles.';
        $inferred = 'The pictured vehicles cost ten million pesos each.';
        Http::preventStrayRequests();
        config()->set(['services.openai.analysis_enabled' => true, 'services.openai.claim_extraction_enabled' => true,
            'services.openai.key' => 'fixture', 'services.filipino_news_classifier.enabled' => false,
            'services.live_verification.enabled' => true, 'fact_check.web_search.enabled' => false,
            'fact_check.retrieval.max_rounds' => 1]);
        $search = $this->mock(ArticleSearch::class);
        $search->shouldReceive('retrieve')->once()->withArgs(function ($queries, $budget) use ($visual) {
            $this->assertSame([$visual], $budget->researchContext['visual_contexts']);
            $this->assertStringContainsString('three-wheeled', implode(' ', $queries));

            return true;
        })->andReturn([]);
        Http::fake(['api.openai.com/*' => function ($request) use ($quote, $visual, $inferred) {
            $stage = $request['text']['format']['name'];
            if ($stage === 'truthguard_claim_extraction') {
                $this->assertContains('visual_context', $request['text']['format']['schema']['required']);
                $answer = ['visible_text' => $quote, 'visual_context' => $visual, 'claim_text' => $inferred,
                    'synthetic_media_note' => '', 'confidence' => 90];
            } else {
                $this->assertSame('claim_decomposition', $stage);
                $input = json_decode($request['input'][1]['content'], true);
                $this->assertSame($visual, $input['submission']['visual_context']);
                $this->assertSame($quote, $input['original_text']);
                $answer = ['claims' => [['quote' => $quote, 'kind' => 'factual', 'requires_context' => false,
                    'context_reason' => '', 'entities' => [], 'dates' => [], 'locations' => [],
                    'quantities' => ['10 million pesos'], 'qualifiers' => [], 'references' => [],
                    'searches' => ['three-wheeled fire-response vehicles 10 million pesos', 'fire trike price claim']]],
                    'original_claim_date' => null, 'date_quote' => ''];
            }

            return Http::response(['status' => 'completed', 'output' => [['type' => 'message', 'content' => [
                ['type' => 'output_text', 'text' => json_encode($answer)],
            ]]]]);
        }]);

        $result = app(EvidenceVerificationService::class)->apply(['signals' => []], [
            'analysis_task' => 'claim', 'media_type' => 'image', 'force_refresh' => true,
            'caption_text' => SubmissionTask::CLAIM_INSTRUCTION.'.',
        ], UploadedFile::fake()->image('synthetic-vehicles.png', 640, 480))['fact_check_result'];

        $this->assertSame($quote, $result['extraction']['analysis_text']);
        $this->assertSame($quote, $result['extraction']['visible_text']);
        $this->assertSame($visual, $result['extraction']['visual_context']);
        $this->assertSame($inferred, $result['extraction']['inferred_claim']);
        $this->assertSame($quote, $result['claims'][0]['text']);
        $this->assertSame('UNVERIFIED', $result['verdict']);
        $this->assertSame([], $result['sources']);
        Http::assertSentCount(2);
    }
}
