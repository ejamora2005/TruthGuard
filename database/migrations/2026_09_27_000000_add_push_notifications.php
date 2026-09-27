<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('token');
            $table->string('token_hash', 64)->unique();
            $table->string('device', 255)->nullable();
            $table->timestamps();
        });
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('push_enabled')->default(false);
            $table->boolean('analysis_results')->default(true);
            $table->boolean('fact_check_updates')->default(true);
            $table->boolean('new_fact_checks')->default(true);
            $table->boolean('system_notifications')->default(true);
            $table->timestamps();
        });
        Schema::table('notifications', fn (Blueprint $table) => $table->string('event_key', 64)->nullable()->unique());
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropUnique(['event_key']);
            $table->dropColumn('event_key');
        });
        Schema::dropIfExists('push_subscriptions');
        Schema::dropIfExists('notification_preferences');
    }
};
