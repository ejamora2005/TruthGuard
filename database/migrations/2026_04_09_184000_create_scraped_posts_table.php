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
        Schema::create('scraped_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scrape_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('detection_id')
                ->nullable()
                ->constrained('detections')
                ->nullOnDelete();
            $table->string('source_key', 100);
            $table->string('external_id')->nullable();
            $table->string('post_fingerprint', 64)->nullable();
            $table->string('post_url', 2048)->nullable();
            $table->string('display_name')->nullable();
            $table->string('username')->nullable();
            $table->text('caption_text')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->json('source_links')->nullable();
            $table->json('image_urls')->nullable();
            $table->json('video_urls')->nullable();
            $table->json('media_urls')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamp('scraped_at');
            $table->timestamps();

            $table->index(['scrape_run_id', 'post_fingerprint']);
            $table->index('source_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scraped_posts');
    }
};
