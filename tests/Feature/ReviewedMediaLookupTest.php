<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Detections\DetectionPipeline;
use App\Services\Detections\ReviewedMediaLookup;
use Database\Seeders\ReviewedMediaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReviewedMediaLookupTest extends TestCase
{
    use RefreshDatabase;

    private const REQUEST = ['resolved_task' => 'media', 'tasks' => ['media'], 'selected_mode' => 'media', 'question' => 'Is this image AI-generated?'];

    public function test_seed_is_idempotent_and_preserves_disabled_records(): void
    {
        $this->seed(ReviewedMediaSeeder::class);
        DB::table('reviewed_media')->where('slug', 'ai-red-tricycle')->update(['active' => false]);
        $this->seed(ReviewedMediaSeeder::class);
        $this->assertSame(10, DB::table('reviewed_media')->count());
        $this->assertSame(5, DB::table('reviewed_media')->where('origin', 'photograph')->count());
        $this->assertSame(5, DB::table('reviewed_media')->where('origin', 'ai_generated')->count());
        $this->assertFalse((bool) DB::table('reviewed_media')->where('slug', 'ai-red-tricycle')->value('active'));
    }

    public function test_only_exact_files_and_media_only_requests_are_admitted(): void
    {
        $this->seed(ReviewedMediaSeeder::class);
        $record = DB::table('reviewed_media')->where('slug', 'ai-cat-by-window')->first();
        $analysis = ReviewedMediaLookup::analysis($record->sha256, 'image', self::REQUEST);
        $this->assertSame('AI_GENERATED', $analysis['fact_check_result']['verdict']);
        $this->assertSame('exact_sha256_reviewed_media', $analysis['fact_check_result']['diagnostics']['match_method']);
        $this->assertNull($analysis['fact_check_result']['score']['value']);
        $this->assertNull(ReviewedMediaLookup::analysis(str_repeat('0', 64), 'image', self::REQUEST));
        $this->assertNull(ReviewedMediaLookup::analysis($record->sha256, 'video', self::REQUEST));
        $this->assertNull(ReviewedMediaLookup::analysis($record->sha256, 'image', ['resolved_task' => 'claim', 'tasks' => ['claim']]));
        $this->assertNull(ReviewedMediaLookup::analysis($record->sha256, 'image', ['resolved_task' => 'media', 'tasks' => ['media', 'claim']]));
        $this->assertNull(ReviewedMediaLookup::analysis($record->sha256, 'image', self::REQUEST, 'https://example.org/news'));
        DB::table('reviewed_media')->where('id', $record->id)->update(['active' => false]);
        $this->assertNull(ReviewedMediaLookup::analysis($record->sha256, 'image', self::REQUEST));
    }

    public function test_shipped_ai_shortcut_uses_exact_provenance_instead_of_running_a_detector(): void
    {
        Storage::fake('public');
        Http::preventStrayRequests();
        $this->seed(ReviewedMediaSeeder::class);
        $upload = UploadedFile::fake()->image('renamed-beach.png');
        $checksum = hash_file('sha256', $upload->getRealPath());
        DB::table('reviewed_media')->where('slug', 'ai-beach-fishing-boat')->update(['sha256' => $checksum]);
        $this->mock(\App\Services\Detections\AiOriginClassifier::class)->shouldNotReceive('classify');
        $this->mock(\App\Services\Detections\DeepfakeCnnClassifier::class)->shouldNotReceive('classify');
        $caption = \App\Services\Detections\SubmissionTask::MEDIA_INSTRUCTION.'.';
        $request = \App\Services\Detections\SubmissionTask::resolve(['caption_text' => $caption, 'media_type' => 'image', 'analysis_task' => 'media']);
        $this->assertSame(['media'], $request['tasks']);
        $detection = app(DetectionPipeline::class)->run(User::factory()->create(), [
            'analysis_task' => 'media', 'caption_text' => $caption,
        ], $upload);
        $this->assertSame('AI_GENERATED', $detection->fact_check_result['verdict']);
        $this->assertSame('ai-beach-fishing-boat', $detection->fact_check_result['diagnostics']['reviewed_media_slug']);
        $this->assertNull($detection->fact_check_result['score']['value']);
        Http::assertNothingSent();
    }

    public function test_explicit_separate_editing_question_still_does_not_shortcut_a_combined_request(): void
    {
        $this->seed(ReviewedMediaSeeder::class);
        $request = \App\Services\Detections\SubmissionTask::resolve([
            'caption_text' => 'Is this AI-generated? Was it edited?', 'media_type' => 'image']);
        $this->assertSame(['media', 'manipulation'], $request['tasks']);
        $checksum = DB::table('reviewed_media')->where('slug', 'ai-beach-fishing-boat')->value('sha256');
        $this->assertNull(ReviewedMediaLookup::analysis($checksum, 'image', $request));
    }

    public function test_photo_provenance_has_a_source_and_no_invented_confidence(): void
    {
        $this->seed(ReviewedMediaSeeder::class);
        $record = DB::table('reviewed_media')->where('slug', 'real-domestic-cat')->first();
        $result = ReviewedMediaLookup::analysis($record->sha256, 'image', self::REQUEST)['fact_check_result'];
        $this->assertSame('LIKELY_NOT_AI_GENERATED', $result['verdict']);
        $this->assertStringStartsWith('Real photograph.', $result['summary']);
        $this->assertSame([], $result['sources']);
        $this->assertCount(1, $result['method_references']);
        $this->assertSame('https://commons.wikimedia.org/wiki/File:Domestic_Cat.jpg', $result['method_references'][0]['url']);
        $this->assertNull($result['confidence']);
    }

    public function test_pipeline_uses_the_record_after_renaming_without_provider_calls(): void
    {
        Storage::fake('public');
        Http::preventStrayRequests();
        $this->seed(ReviewedMediaSeeder::class);
        // A tiny test image stands in for an approved original; no paid provider is involved.
        $upload = UploadedFile::fake()->image('renamed-image.jpg');
        $checksum = hash_file('sha256', $upload->getRealPath());
        DB::table('reviewed_media')->where('slug', 'ai-red-tricycle')->update(['sha256' => $checksum]);
        $detection = app(DetectionPipeline::class)->run(User::factory()->create(), [
            'analysis_task' => 'media', 'caption_text' => 'Is this image AI-generated?',
        ], $upload);
        $this->assertSame('completed', $detection->processing_status);
        $this->assertSame($checksum, $detection->media_checksum);
        $this->assertSame('AI_GENERATED', $detection->fact_check_result['verdict']);
        $this->assertSame(0, $detection->fact_check_result['diagnostics']['api_usage']['total_tokens']);
        $this->assertSame('ai-red-tricycle', $detection->fact_check_result['diagnostics']['reviewed_media_slug']);
        Http::assertNothingSent();
    }
}
