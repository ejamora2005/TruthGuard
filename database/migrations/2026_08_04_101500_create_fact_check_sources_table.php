<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fact_check_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('key', 100)->unique();
            $table->string('name', 120);
            $table->string('domain', 160)->nullable()->index();
            $table->string('category', 40)->default('fact_check')->index();
            $table->string('scraper_key', 80)->default('article-search');
            $table->string('url_template', 2048);
            $table->json('ready_selectors')->nullable();
            $table->json('article_selectors')->nullable();
            $table->json('exclude_selectors')->nullable();
            $table->boolean('is_enabled')->default(true)->index();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['is_enabled', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fact_check_sources');
    }
};
