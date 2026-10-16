<?php

namespace Tests\Feature;

use App\Models\Detection;
use App\Models\User;
use App\Services\Detections\Evidence\SafeArticleFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EvidenceSubmissionCoverageTest extends TestCase
{
    use RefreshDatabase;

    private const CLAIM = 'The Example City library opened on October 12, 2026.';

    private const SOURCE = 'https://records.gov.ph/library-notice';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 13)->setTime(12, 0));
        Storage::fake('public');
        Http::preventStrayRequests();
        config()->set([
            'fact_check.enhanced_enabled' => true, 'fact_check.queue_enabled' => false,
            'fact_check.retrieval.respect_robots' => false, 'fact_check.retrieval.google_pages' => 1,
            'fact_check.retrieval.max_search_requests' => 2,
            'services.openai.analysis_enabled' => true, 'services.openai.claim_extraction_enabled' => true,
            'services.openai.image_context_enabled' => true, 'services.openai.image_max_bytes' => 4194304,
            'services.openai.key' => 'fixture-key', 'services.deepfake_cnn.enabled' => false,
            'services.filipino_news_classifier.enabled' => false, 'services.live_verification.enabled' => true,
            'services.google_fact_check.key' => 'fixture-key', 'services.gnews.key' => '', 'services.newsapi.key' => '',
            'playwright.related_posts_inline' => false,
        ]);
        $this->app->instance(SafeArticleFetcher::class, new class extends SafeArticleFetcher
        {
            protected function resolve(string $host): array
            {
                return ['93.184.216.34'];
            }
        });
    }

    public static function inputFormats(): array
    {
        return array_combine(
            ['jpg', 'jpeg', 'png', 'webp', 'mp4', 'mov', 'webm', 'm4v', 'pdf', 'link', 'text'],
            array_map(fn ($format) => [$format], ['jpg', 'jpeg', 'png', 'webp', 'mp4', 'mov', 'webm', 'm4v', 'pdf', 'link', 'text']),
        );
    }

    #[DataProvider('inputFormats')]
    public function test_every_accepted_input_format_reaches_the_same_evidence_engine(string $format): void
    {
        $this->fakeEvidence(self::CLAIM, 'supporting');
        $input = match ($format) {
            'text' => ['caption_text' => self::CLAIM],
            'link' => ['source_url' => self::SOURCE],
            default => ['media_file' => new UploadedFile(base_path('tests/Fixtures/evidence/media/notice.'.$format), 'notice.'.$format, null, null, true)],
        };
        $this->actingAs(User::factory()->create())->post(route('detections.store'), $input + ['analysis_task' => 'claim'])->assertSessionHasNoErrors()->assertRedirect();
        $detection = Detection::sole();
        $result = $detection->fact_check_result;
        $this->assertSame('True', $result['assessment_label'], json_encode($result['limitations']));
        $this->assertSame(self::CLAIM, $result['claims'][0]['text']);
        $this->assertSame(1, $result['diagnostics']['sources_used']);
        $this->assertSame('completed', $detection->processing_status);
        $this->assertNull($result['confidence']);
        if (isset($input['media_file'])) {
            Storage::disk('public')->assertExists($detection->media_path);
            $this->assertSame(hash_file('sha256', $input['media_file']->getRealPath()), hash_file('sha256', Storage::disk('public')->path($detection->media_path)));
        }
        if (in_array($format, ['mp4', 'mov', 'webm', 'm4v'], true)) {
            $this->assertSame('video', $detection->media_type);
            $this->assertGreaterThan(0, $result['extraction']['sampled_frames']);
            $this->assertStringContainsString('Audio and the complete video were not transcribed', implode(' ', $result['limitations']));
        }
        if ($format === 'pdf') {
            $this->assertSame('document', $detection->media_type);
            $this->assertStringContainsString(self::CLAIM, $result['extraction']['article_text']);
        }
        $this->getJson(route('detections.result', $detection))->assertOk()->assertJsonPath('assessment_label', 'True');
    }

    public static function topics(): array
    {
        // Fictional, frozen statements and mocked evidence, not real-world labels.
        return [
            'education, Filipino' => ['Walang pasok sa Paaralang Halimbawa sa Oktubre 12, 2026.', 'supporting', 'factual', 'True'],
            'health' => ['The demonstration regulator approved Example-X as a cure for every illness.', 'contradicting', 'factual', 'False'],
            'weather' => ['A storm warning covered Test Province on October 12, 2026.', 'insufficient', 'factual', 'Unverified'],
            'finance, Filipino' => ['Itinaas ng Bangkong Halimbawa ang interest rate sa limang porsiyento.', 'contradicting', 'factual', 'False'],
            'science' => ['The Aurora demonstration satellite launched on October 12, 2026.', 'supporting', 'factual', 'True'],
            'technology' => ['The demonstration application encrypts all stored messages.', 'insufficient', 'factual', 'Unverified'],
            'public announcement' => [self::CLAIM, 'supporting', 'factual', 'True'],
            'opinion' => ['I think the new demonstration park looks beautiful.', 'insufficient', 'opinion', 'Opinion / Not Fact-checkable'],
            'question' => ['Will the demonstration museum open tomorrow?', 'insufficient', 'question', 'Opinion / Not Fact-checkable'],
            'prediction' => ['The demonstration team will win next season.', 'insufficient', 'prediction', 'Opinion / Not Fact-checkable'],
        ];
    }

    #[DataProvider('topics')]
    public function test_assessment_is_topic_independent_and_does_not_force_needs_context(string $claim, string $stance, string $kind, string $expected): void
    {
        $this->fakeEvidence($claim, $stance, $kind);
        $this->actingAs(User::factory()->create())->post(route('detections.store'), ['caption_text' => $claim])->assertSessionHasNoErrors();
        $result = Detection::sole()->fact_check_result;
        $this->assertSame($expected, $result['assessment_label']);
        $this->assertSame($claim, $result['claims'][0]['text']);
        $this->assertFalse($result['ui']['show_missing_context']);
        if ($stance === 'insufficient') {
            $this->assertSame([], $result['sources']);
        }
        if ($kind !== 'factual') {
            Http::assertNotSent(fn ($r) => str_contains($r->url(), 'factchecktools.googleapis.com'));
        }
    }

    public function test_image_above_ocr_byte_limit_gets_a_readable_copy_without_changing_original(): void
    {
        config()->set('services.openai.image_max_bytes', 20000);
        $this->fakeEvidence(self::CLAIM, 'supporting');
        $file = new UploadedFile(base_path('tests/Fixtures/evidence/media/notice.jpg'), 'notice.jpg', null, null, true);
        $this->actingAs(User::factory()->create())->post(route('detections.store'), ['media_file' => $file, 'analysis_task' => 'claim'])->assertSessionHasNoErrors();
        $detection = Detection::sole();
        $result = $detection->fact_check_result;
        $this->assertSame('True', $result['assessment_label']);
        $this->assertTrue($result['extraction']['media_preparation']['resized']);
        $this->assertSame(hash_file('sha256', $file->getRealPath()), hash_file('sha256', Storage::disk('public')->path($detection->media_path)));
        Http::assertSent(function ($r) {
            if (data_get($r->data(), 'text.format.name') !== 'truthguard_claim_extraction') {
                return false;
            }
            $image = $r['input'][1]['content'][1]['image_url'];

            return str_starts_with($image, 'data:image/jpeg;base64,') && strlen(base64_decode(explode(',', $image, 2)[1])) <= 20000;
        });
    }

    public function test_unreadable_upload_never_invents_a_claim_from_the_filename(): void
    {
        $this->fakeEvidence(self::CLAIM, 'supporting', visible: '');
        $this->actingAs(User::factory()->create())->post(route('detections.store'), [
            'media_file' => UploadedFile::fake()->image('proof-the-library-opened.png'),
            'analysis_task' => 'claim',
        ])->assertSessionHasNoErrors();
        $result = Detection::sole()->fact_check_result;
        $this->assertSame('Unreadable Content', $result['assessment_label']);
        $this->assertSame('', $result['claim']);
        $this->assertSame([], $result['sources']);
        Http::assertNotSent(fn ($r) => data_get($r->data(), 'text.format.name') === 'claim_decomposition');
    }

    public function test_executable_uploads_are_rejected_before_analysis(): void
    {
        $this->actingAs(User::factory()->create())->post(route('detections.store'), [
            'media_file' => UploadedFile::fake()->create('program.exe', 10, 'application/octet-stream'),
        ])->assertSessionHasErrors('media_file');
        $this->assertSame(0, Detection::count());
        Http::assertNothingSent();
    }

    private function fakeEvidence(string $claim, string $stance, string $kind = 'factual', ?string $visible = null): void
    {
        $article = match ($stance) {
            'supporting' => $claim.' This is the dated original record for the demonstration event, published on October 12, 2026.',
            'contradicting' => 'The following demonstration statement is incorrect: '.$claim.' The dated original record explicitly denies it.',
            default => 'This demonstration document concerns a different event and does not establish the submitted statement.',
        };
        Http::fake(function ($request) use ($claim, $stance, $kind, $visible, $article) {
            if (str_contains($request->url(), 'api.openai.com')) {
                $stage = data_get($request->data(), 'text.format.name');
                if ($stage === 'truthguard_claim_extraction') {
                    $output = ['claim_text' => '', 'visible_text' => $visible ?? $claim, 'synthetic_media_note' => 'Synthetic test fixture.', 'confidence' => 95];
                } elseif ($stage === 'claim_decomposition') {
                    $input = json_decode($request['input'][1]['content'], true);
                    $this->assertStringContainsString($claim, $input['original_text']);
                    $output = ['claims' => [['quote' => $claim, 'kind' => $kind, 'entities' => [], 'dates' => [], 'locations' => [], 'quantities' => [], 'qualifiers' => [],
                        'requires_context' => false, 'context_reason' => '', 'references' => [], 'searches' => [$claim]]], 'original_claim_date' => null, 'date_quote' => ''];
                } else {
                    $input = json_decode($request['input'][1]['content'], true);
                    $output = ['context_findings' => [], 'relationships' => array_map(fn ($s) => ['claim_id' => 'c1', 'source_id' => $s['id'], 'stance' => $stance,
                        'excerpt' => $article, 'explanation' => 'This frozen fixture addresses the exact demonstration statement.',
                        'matches' => array_fill_keys(['event', 'entities', 'action', 'date', 'location', 'quantities', 'qualifiers'], $stance !== 'insufficient'),
                        'basis' => $stance === 'insufficient' ? 'background' : 'primary_record', 'supersedes_source_ids' => [], 'supersession_excerpt' => ''], $input['sources'])];
                }

                return Http::response(['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($output)]]]]]);
            }
            if (str_contains($request->url(), 'factchecktools.googleapis.com')) {
                return Http::response(['claims' => [['text' => $claim, 'claimReview' => [['url' => self::SOURCE, 'title' => 'Original demonstration record', 'publisher' => ['name' => 'Fixture archive'], 'reviewDate' => '2026-10-12T00:00:00Z', 'textualRating' => 'Unspecified']]]]]);
            }
            if ($request->url() === self::SOURCE) {
                return Http::response('<html><head><meta property="article:published_time" content="2026-10-12T00:00:00Z"></head><body><article>'.$article.'</article></body></html>', 200, ['Content-Type' => 'text/html']);
            }

            return Http::response([], 503);
        });
    }
}
