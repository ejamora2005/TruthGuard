<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('openai_request_usages', function (Blueprint $table) {
            $table->id();
            $table->string('event_key', 64)->unique();
            $table->string('response_id')->nullable();
            $table->string('request_id')->nullable();
            $table->string('model')->nullable();
            $table->unsignedSmallInteger('http_status');
            $table->unsignedBigInteger('input_tokens')->nullable();
            $table->unsignedBigInteger('output_tokens')->nullable();
            $table->unsignedBigInteger('total_tokens')->nullable();
            $table->unsignedBigInteger('cached_input_tokens')->nullable();
            $table->unsignedBigInteger('reasoning_tokens')->nullable();
            $table->unsignedInteger('web_search_calls')->default(0);
            $table->boolean('quota_exhausted')->default(false);
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
        });

        Schema::create('openai_cost_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 64);
            $table->string('month', 7);
            $table->bigInteger('usd_micros')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->string('sync_error')->nullable();
            $table->timestamps();
            $table->unique(['scope', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('openai_cost_snapshots');
        Schema::dropIfExists('openai_request_usages');
    }
};
