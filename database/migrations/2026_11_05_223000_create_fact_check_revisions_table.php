<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('fact_check_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('detection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reviewer_id')->constrained('users')->cascadeOnDelete();
            $table->string('reason', 500);
            $table->json('previous_result');
            $table->json('revised_result');
            $table->timestamp('published_at');
            $table->timestamps();
            $table->index(['detection_id', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fact_check_revisions');
    }
};
