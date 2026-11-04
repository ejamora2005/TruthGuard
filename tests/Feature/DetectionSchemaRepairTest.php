<?php

namespace Tests\Feature;

use App\Models\Detection;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DetectionSchemaRepairTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_result_column_is_repaired_without_losing_uploads(): void
    {
        $detection = Detection::create(['user_id' => User::factory()->create()->id,
            'source_kind' => 'upload', 'media_type' => 'image', 'media_path' => 'detections/saved.jpg',
            'processing_status' => 'processing', 'fake_score' => 0, 'verdict' => 'review']);
        Schema::table('detections', fn (Blueprint $table) => $table->dropColumn('fact_check_result'));

        $repair = require database_path('migrations/2026_11_04_000000_repair_missing_detection_result_column.php');
        $repair->up();
        $repair->up();

        $this->assertTrue(Schema::hasColumn('detections', 'fact_check_result'));
        $this->assertSame('detections/saved.jpg', $detection->fresh()->media_path);
        $detection->refresh()->forceFill(['fact_check_result' => ['verdict' => 'MISLEADING']])->saveQuietly();
        $repair->down();
        $this->assertSame('MISLEADING', $detection->fresh()->fact_check_result['verdict']);
    }
}
