<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeDetectionEvidence;
use App\Models\Detection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DetectionSubmissionValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Storage::fake('public');
        config()->set(['fact_check.enhanced_enabled' => true, 'fact_check.queue_enabled' => true]);
        $this->actingAs(User::factory()->create());
    }

    public static function invalidInputs(): array
    {
        return [
            'empty' => [[], 'caption_text'],
            'invisible' => [['caption_text' => "\u{00a0}\u{200b}\u{feff}"], 'caption_text'],
            'punctuation' => [['caption_text' => '... ??? !!!'], 'caption_text'],
            'emoji' => [['caption_text' => '🤔🤔'], 'caption_text'],
            'media guide alone' => [['caption_text' => 'Is this image or video AI-generated or manipulated?'], 'caption_text'],
            'claim guide alone' => [['caption_text' => 'Is the claim in this post true?'], 'caption_text'],
            'sources guide alone' => [['caption_text' => 'Has this claim already been fact-checked, and what ratings did trusted sources give it?'], 'caption_text'],
            'guide and punctuation' => [['caption_text' => "Is the claim in this post true?\n\n???"], 'caption_text'],
            'legacy guide' => [['caption_text' => 'Fact-check this claim against trusted public sources and explain the evidence.'], 'caption_text'],
            'array caption' => [['caption_text' => ['not a string']], 'caption_text'],
            'array URL' => [['source_url' => ['https://example.com']], 'source_url'],
            'FTP link' => [['source_url' => 'ftp://example.com/file'], 'source_url'],
        ];
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_submission_returns_json_validation_without_analysis(array $input, string $field): void
    {
        $this->postJson(route('detections.store'), $input)
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('detections', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_browser_validation_redirect_preserves_the_draft(): void
    {
        $this->from(route('detections.create'))->post(route('detections.store'), ['caption_text' => '???'])
            ->assertRedirect(route('detections.create'))->assertSessionHasErrors('caption_text')
            ->assertSessionHasInput('caption_text', '???');
        Queue::assertNothingPushed();
    }

    public function test_valid_questions_and_evidence_are_still_accepted(): void
    {
        foreach ([
            ['caption_text' => 'Did the Riverside bridge reopen in May 2026?'],
            ['caption_text' => 'Totoo bang walang pasok bukas sa Maynila?'],
            ['caption_text' => '水在标准大气压下于100°C沸腾。'],
            ['caption_text' => '1 + 1 = 2'],
            ['caption_text' => "Is the claim in this post true?\n\nThe bridge reopened in May 2026."],
            ['caption_text' => 'Is the claim in this post true? https://example.com/news'],
            ['caption_text' => 'Is this image or video AI-generated or manipulated?', 'media_file' => UploadedFile::fake()->image('photo.png')],
            ['source_url' => 'https://example.com/news'],
        ] as $input) {
            $this->post(route('detections.store'), $input)->assertSessionHasNoErrors()->assertRedirect();
        }
        $this->assertDatabaseCount('detections', 8);
        Queue::assertPushed(AnalyzeDetectionEvidence::class, 8);
        Http::assertNothingSent();
        $this->assertSame('Is the claim in this post true?', Detection::orderBy('id')->skip(5)->first()->caption_text);
    }
}
