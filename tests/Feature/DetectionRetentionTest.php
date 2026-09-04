<?php

namespace Tests\Feature;

use App\Models\Detection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DetectionRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_history_archives_fact_checks_older_than_seven_days_without_deleting_data(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        Storage::disk('public')->put('detections/expired-proof.png', 'expired evidence');

        $recentDetection = $this->createDetection($user, [
            'caption_text' => 'Recent retained fact check',
            'analyzed_at' => now()->subDays(6),
        ]);

        $expiredDetection = $this->createDetection($user, [
            'caption_text' => 'Expired archived fact check',
            'media_path' => 'detections/expired-proof.png',
            'analyzed_at' => now()->subDays(8),
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('history'));

        $response
            ->assertOk()
            ->assertSee('Recent retained fact check')
            ->assertDontSee('Expired archived fact check');

        $this->assertDatabaseHas('detections', ['id' => $recentDetection->id]);
        $this->assertDatabaseHas('detections', ['id' => $expiredDetection->id]);
        $this->assertNotNull($expiredDetection->fresh()->archived_at);
        Storage::disk('public')->assertExists('detections/expired-proof.png');
    }

    public function test_history_archive_tab_shows_archived_fact_checks(): void
    {
        $user = User::factory()->create();

        $this->createDetection($user, [
            'caption_text' => 'Archived fact check remains available',
            'analyzed_at' => now()->subDays(8),
            'archived_at' => now(),
            'archive_reason' => 'retention-window',
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('history', ['status' => 'archived']));

        $response
            ->assertOk()
            ->assertSee('Archived fact check remains available')
            ->assertSee('Archived');
    }

    public function test_history_shows_latest_fifteen_fact_checks_per_page(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 16) as $index) {
            $this->createDetection($user, [
                'caption_text' => "Recent paged fact check {$index}",
                'analyzed_at' => now()->subMinutes($index),
            ]);
        }

        $firstPage = $this
            ->actingAs($user)
            ->get(route('history'));

        $firstPage
            ->assertOk()
            ->assertSee('Recent paged fact check 1')
            ->assertSee('Recent paged fact check 15')
            ->assertDontSee('Recent paged fact check 16');

        $secondPage = $this
            ->actingAs($user)
            ->get(route('history', ['page' => 2]));

        $secondPage
            ->assertOk()
            ->assertSee('Recent paged fact check 16')
            ->assertDontSee('Recent paged fact check 1');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createDetection(User $user, array $overrides = []): Detection
    {
        return Detection::query()->create(array_merge([
            'user_id' => $user->id,
            'source_kind' => 'upload',
            'platform' => 'web',
            'media_type' => 'image',
            'caption_text' => 'Fact check record',
            'fake_score' => 82,
            'processing_status' => 'completed',
            'preprocessing_summary' => 'Preprocessed successfully.',
            'analysis_summary' => 'Likely manipulated visual.',
            'verification_summary' => 'Cross-source verification found inconsistencies.',
            'explanation_summary' => 'Image metadata and caption context were inconsistent with source reporting.',
            'recommendation' => 'Treat as suspicious pending manual confirmation.',
            'signals' => ['ai_generated'],
            'verification_sources' => [],
            'verdict' => 'fake',
            'notes' => null,
            'analyzed_at' => now(),
        ], $overrides));
    }
}
