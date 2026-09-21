<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('public_claim_review_email_deliveries')) {
            return;
        }

        Schema::create('public_claim_review_email_deliveries', function (Blueprint $table) {
            $table->id();
            $table
                ->foreignId('public_claim_review_announcement_id')
                ->constrained(
                    table: 'public_claim_review_announcements',
                    indexName: 'pcred_announcement_fk'
                )
                ->cascadeOnDelete();
            $table
                ->foreignId('user_id')
                ->constrained(indexName: 'pcred_user_fk')
                ->cascadeOnDelete();
            $table->timestamp('sent_at')->useCurrent();
            $table->timestamps();

            $table->unique(
                ['public_claim_review_announcement_id', 'user_id'],
                'public_claim_review_email_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_claim_review_email_deliveries');
    }
};
