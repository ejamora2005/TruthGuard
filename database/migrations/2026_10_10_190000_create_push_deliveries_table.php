<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('push_subscription_id')->constrained()->cascadeOnDelete();
            $table->uuid('notification_id');
            $table->string('status', 20)->default('queued');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['push_subscription_id', 'notification_id']);
            $table->foreign('notification_id')->references('id')->on('notifications')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_deliveries');
    }
};
