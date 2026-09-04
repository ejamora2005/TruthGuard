<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('detections', function (Blueprint $table) {
            $table->string('request_fingerprint', 64)->nullable()->after('media_type');
            $table->string('media_checksum', 64)->nullable()->after('media_path');
            $table->foreignId('reused_from_detection_id')
                ->nullable()
                ->after('request_fingerprint')
                ->constrained('detections')
                ->nullOnDelete();

            $table->index(['request_fingerprint', 'processing_status']);
            $table->index('media_checksum');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('detections', function (Blueprint $table) {
            $table->dropIndex(['request_fingerprint', 'processing_status']);
            $table->dropIndex(['media_checksum']);
            $table->dropConstrainedForeignId('reused_from_detection_id');
            $table->dropColumn(['request_fingerprint', 'media_checksum']);
        });
    }
};
