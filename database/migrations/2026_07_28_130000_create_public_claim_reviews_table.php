<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('public_claim_reviews')) {
            return;
        }

        Schema::create('public_claim_reviews', function (Blueprint $table) {
            $table->id();
            $table->string('feed_item_id', 80)->unique();
            $table->string('publisher', 120)->nullable();
            $table->string('headline', 180);
            $table->text('claim')->nullable();
            $table->string('claimant', 120)->nullable();
            $table->string('rating', 80)->nullable();
            $table->string('tone', 24)->nullable();
            $table->string('source_domain', 120)->nullable();
            $table->string('url', 2048)->nullable();
            $table->string('image_url', 2048)->nullable();
            $table->string('logo_url', 2048)->nullable();
            $table->string('query', 255)->nullable();
            $table->string('feed_source_type', 80)->nullable();
            $table->string('publisher_filter', 120)->nullable();
            $table->json('source_payload')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index('published_at');
            $table->index('last_seen_at');
            $table->index(['source_domain', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_claim_reviews');
    }
};
