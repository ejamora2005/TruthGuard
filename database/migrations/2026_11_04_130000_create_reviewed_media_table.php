<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviewed_media', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('filename');
            $table->string('sha256', 64)->unique();
            $table->string('origin');
            $table->text('summary');
            $table->json('provenance');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviewed_media');
    }
};
