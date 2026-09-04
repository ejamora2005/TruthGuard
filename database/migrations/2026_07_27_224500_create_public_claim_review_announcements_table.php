<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('public_claim_review_announcements')) {
            return;
        }

        Schema::create('public_claim_review_announcements', function (Blueprint $table) {
            $table->id();
            $table->string('feed_item_id', 80)->unique();
            $table->string('publisher', 120)->nullable();
            $table->string('headline', 180);
            $table->text('claim')->nullable();
            $table->string('rating', 80)->nullable();
            $table->string('source_domain', 120)->nullable();
            $table->string('url', 2048)->nullable();
            $table->string('image_url', 2048)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('announced_at')->useCurrent();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_claim_review_announcements');
    }
};
