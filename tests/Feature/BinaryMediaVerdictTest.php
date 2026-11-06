<?php

namespace Tests\Feature;

use App\Models\Detection;
use App\Models\User;
use App\Services\Detections\GoogleFactCheckFeedService;
use App\Services\Detections\MediaAnalysisReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BinaryMediaVerdictTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_disputed_report_shows_primary_ai_prediction_without_running_analysis_again(): void
    {
        Http::preventStrayRequests();
        $this->mock(GoogleFactCheckFeedService::class)->shouldReceive('latest')->andReturn(['items' => []]);
        $user = User::factory()->create();
        $saved = MediaAnalysisReport::result(['status' => 'completed', 'media_type' => 'image',
            'label' => 'fake', 'fake_probability' => .9999, 'detector_disagreement' => true,
            'origin_policy' => ['inconclusive' => true, 'reasons' => ['detector_disagreement']]], []);
        $record = Detection::create(['user_id' => $user->id, 'source_kind' => 'upload', 'media_type' => 'image',
            'caption_text' => 'Is this image AI-generated?', 'processing_status' => 'completed',
            'verdict' => 'review', 'fake_score' => 0, 'fact_check_result' => $saved]);
        $this->actingAs($user)->get(route('detections.result', $record))->assertOk()
            ->assertSee('Likely AI-Generated')->assertSee('The two detectors gave different results')
            ->assertDontSee('AI origin inconclusive');
        $this->getJson(route('detections.result', $record))->assertOk()->assertJsonPath('verdict', 'LIKELY_AI_GENERATED');
        $this->assertSame('INCONCLUSIVE', $record->fresh()->fact_check_result['verdict']);
        Http::assertNothingSent();
    }
}
