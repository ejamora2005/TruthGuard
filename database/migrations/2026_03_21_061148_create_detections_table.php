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
        Schema::create('detections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('source_kind', 20);
            $table->string('source_url', 2048)->nullable();
            $table->string('media_path')->nullable();
            $table->string('media_type', 20)->default('unknown');
            $table->unsignedTinyInteger('fake_score');
            $table->string('verdict', 20);
            $table->text('notes')->nullable();
            $table->timestamp('analyzed_at');
            $table->timestamps();

            $table->index(['user_id', 'verdict']);
            $table->index(['user_id', 'media_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('detections');
    }
};
