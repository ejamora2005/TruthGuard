<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('canonical_fact_check_results', function (Blueprint $table) {
            $table->id();
            $table->string('canonical_key', 64)->unique();
            $table->json('result');
            $table->timestamps();
        });

        Schema::table('detections', function (Blueprint $table) {
            $table->json('fact_check_result')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('detections', function (Blueprint $table) {
            $table->dropColumn('fact_check_result');
        });
        Schema::dropIfExists('canonical_fact_check_results');
    }
};
