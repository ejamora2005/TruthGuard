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
            $table->string('platform', 40)->nullable()->after('source_kind');
            $table->text('caption_text')->nullable()->after('media_type');
            $table->string('processing_status', 40)->default('completed')->after('fake_score');
            $table->text('preprocessing_summary')->nullable()->after('processing_status');
            $table->text('analysis_summary')->nullable()->after('preprocessing_summary');
            $table->text('verification_summary')->nullable()->after('analysis_summary');
            $table->text('explanation_summary')->nullable()->after('verification_summary');
            $table->text('recommendation')->nullable()->after('explanation_summary');
            $table->json('signals')->nullable()->after('recommendation');
            $table->json('verification_sources')->nullable()->after('signals');

            $table->index(['platform', 'processing_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('detections', function (Blueprint $table) {
            $table->dropIndex(['platform', 'processing_status']);
            $table->dropColumn([
                'platform',
                'caption_text',
                'processing_status',
                'preprocessing_summary',
                'analysis_summary',
                'verification_summary',
                'explanation_summary',
                'recommendation',
                'signals',
                'verification_sources',
            ]);
        });
    }
};
