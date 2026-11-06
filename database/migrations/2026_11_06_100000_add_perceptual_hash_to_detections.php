<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('detections', function (Blueprint $table): void {
            $table->string('media_perceptual_hash', 16)->nullable()->after('media_checksum')->index();
        });
    }

    public function down(): void
    {
        Schema::table('detections', function (Blueprint $table): void {
            $table->dropIndex(['media_perceptual_hash']);
            $table->dropColumn('media_perceptual_hash');
        });
    }
};
