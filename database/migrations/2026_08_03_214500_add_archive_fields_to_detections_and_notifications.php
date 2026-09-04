<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('detections', 'archived_at')) {
            Schema::table('detections', function (Blueprint $table) {
                $table->timestamp('archived_at')->nullable()->after('analyzed_at');
                $table->string('archive_reason', 80)->nullable()->after('archived_at');

                $table->index(['user_id', 'archived_at']);
                $table->index('archived_at');
            });
        }

        if (Schema::hasTable('notifications') && ! Schema::hasColumn('notifications', 'archived_at')) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->timestamp('archived_at')->nullable()->after('read_at');
                $table->index(['notifiable_type', 'notifiable_id', 'archived_at']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('notifications') && Schema::hasColumn('notifications', 'archived_at')) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->dropIndex(['notifiable_type', 'notifiable_id', 'archived_at']);
                $table->dropColumn('archived_at');
            });
        }

        if (Schema::hasColumn('detections', 'archived_at')) {
            Schema::table('detections', function (Blueprint $table) {
                $table->dropIndex(['user_id', 'archived_at']);
                $table->dropIndex(['archived_at']);
                $table->dropColumn(['archived_at', 'archive_reason']);
            });
        }
    }
};
