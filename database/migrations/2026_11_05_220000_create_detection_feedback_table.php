<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('detection_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('detection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('category', 40);
            $table->timestamps();
            $table->unique(['detection_id', 'user_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('detection_feedback');
    }
};
