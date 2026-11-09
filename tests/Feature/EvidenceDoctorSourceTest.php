<?php

namespace Tests\Feature;

use App\Models\Detection;
use App\Models\User;
use App\Services\Detections\Evidence\SafeArticleFetcher;
use App\Services\Detections\Evidence\StructuredAnalysis;
use App\Services\Detections\FilipinoNewsClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EvidenceDoctorSourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_saved_report_diagnostics_show_retrieval_failures_without_secrets_or_requests(): void
    {
        config()->set('services.openai.key', 'secret-api-credential');
        $user = User::factory()->create();
        $detection = Detection::create(['user_id' => $user->id, 'source_kind' => 'text',
            'caption_text' => 'Private submitted text', 'processing_status' => 'completed', 'verdict' => 'review', 'fake_score' => 0,
            'fact_check_result' => ['primary_task' => 'claim', 'verdict' => 'UNVERIFIED',
                'diagnostics' => ['outcome_reason' => 'no_readable_sources']],
            'verification_sources' => [['url' => 'https://example.com/article?token=private-query',
                'retrieval_status' => 'robots_restricted_or_unavailable', 'retrieval_http_status' => null,
                'robots_detail' => 'robots_fetch_timeout', 'text' => '']]]);
        $before = $detection->fresh()->getRawOriginal();
        $this->assertSame(0, Artisan::call('truthguard:evidence-doctor', ['--detection' => $detection->id]));
        $raw = Artisan::output();
        $data = json_decode($raw, true);
        $this->assertSame('UNVERIFIED', $data['saved_report']['verdict']);
        $this->assertSame('robots_fetch_timeout', $data['saved_report']['sources'][0]['robots_detail']);
        $this->assertSame('example.com', $data['saved_report']['sources'][0]['host']);
        $this->assertArrayHasKey('pipeline_version', $data['runtime']);
        foreach (['secret-api-credential', 'private-query', 'Private submitted text'] as $secret) {
            $this->assertStringNotContainsString($secret, $raw);
        }
        $this->assertSame($before, $detection->fresh()->getRawOriginal());
        Http::assertNothingSent();
    }

    public function test_source_probe_fetches_one_page_without_search_or_model_usage(): void
    {
        $this->mock(StructuredAnalysis::class)->shouldNotReceive('ask');
        $this->mock(FilipinoNewsClassifier::class)->shouldNotReceive('inspect');
        $fetcher = $this->partialMock(SafeArticleFetcher::class);
        $fetcher->shouldReceive('publicAddress')->andReturn('93.184.216.34');
        Http::fakeSequence()->push("User-agent: *\nAllow: /", 200, ['Content-Type' => 'text/plain'])
            ->push('<article>'.str_repeat('Readable diagnostic source passage. ', 5).'</article>', 200, ['Content-Type' => 'text/html']);
        $this->assertSame(0, Artisan::call('truthguard:evidence-doctor', ['--url' => 'https://example.com/article?token=private-query']));
        $raw = Artisan::output();
        $data = json_decode($raw, true);
        $this->assertSame('source_probe', $data['mode']);
        $this->assertSame('retrieved', $data['source_probe']['status']);
        $this->assertSame(200, $data['source_probe']['http_status']);
        $this->assertSame(0, $data['source_probe']['model_calls']);
        $this->assertStringNotContainsString('private-query', $raw);
        Http::assertSentCount(2);
    }

    public function test_missing_reports_and_credentialed_urls_are_rejected_without_requests(): void
    {
        $this->assertSame(1, Artisan::call('truthguard:evidence-doctor', ['--detection' => 'not-a-number']));
        $this->assertSame(1, Artisan::call('truthguard:evidence-doctor', ['--url' => 'https://user:secret@example.com/article']));
        Http::assertNothingSent();
    }
}
