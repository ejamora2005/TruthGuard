<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('detections', function (Blueprint $table) {
            $table->timestamp('analyzed_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('detections')->whereNull('analyzed_at')->exists()) {
            throw new RuntimeException('Pending or failed assessments have no analysis timestamp. Resolve them before rolling back; do not fabricate timestamps.');
        }
        Schema::table('detections', function (Blueprint $table) {
            $table->timestamp('analyzed_at')->nullable(false)->change();
        });
    }
};
