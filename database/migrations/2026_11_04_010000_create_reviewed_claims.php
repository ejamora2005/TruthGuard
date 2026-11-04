<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('reviewed_claims', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->text('claim');
            $table->json('result');
            $table->boolean('active')->default(true);
            $table->timestamp('reviewed_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
        Schema::create('claim_variations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reviewed_claim_id')->constrained()->cascadeOnDelete();
            $table->text('text');
            $table->text('normalized_text');
            $table->string('text_hash', 64)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claim_variations');
        Schema::dropIfExists('reviewed_claims');
    }
};
