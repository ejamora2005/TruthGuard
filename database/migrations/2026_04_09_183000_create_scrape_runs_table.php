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
        Schema::create('scrape_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('triggered_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('trigger_source', 40)->default('manual');
            $table->string('source_key', 100);
            $table->string('target_url', 2048);
            $table->string('status', 30)->default('queued');
            $table->string('browser_name', 20)->default('chromium');
            $table->boolean('headless')->default(true);
            $table->string('screenshot_path')->nullable();
            $table->json('options')->nullable();
            $table->json('summary')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'source_key']);
            $table->index('triggered_by_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scrape_runs');
    }
};
