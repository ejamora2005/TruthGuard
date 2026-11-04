<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Older installations can have the original migration recorded while
        // the result column is missing. Preserve every existing submission.
        if (Schema::hasTable('detections') && ! Schema::hasColumn('detections', 'fact_check_result')) {
            Schema::table('detections', function (Blueprint $table) {
                $table->json('fact_check_result')->nullable();
            });
        }
    }

    public function down(): void
    {
        // This column belongs to the original migration. Rolling back a repair
        // must never remove saved fact-check results.
    }
};
