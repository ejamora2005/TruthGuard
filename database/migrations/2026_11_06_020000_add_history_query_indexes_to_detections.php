<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('detections', function (Blueprint $table): void {
            $table->index(['user_id', 'id'], 'detections_user_report_number_index');
            $table->index(
                ['user_id', 'archived_at', 'analyzed_at', 'id'],
                'detections_user_history_sort_index'
            );
            $table->index(
                ['user_id', 'archived_at', 'verdict', 'analyzed_at', 'id'],
                'detections_user_history_verdict_sort_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('detections', function (Blueprint $table): void {
            $table->dropIndex('detections_user_report_number_index');
            $table->dropIndex('detections_user_history_sort_index');
            $table->dropIndex('detections_user_history_verdict_sort_index');
        });
    }
};
