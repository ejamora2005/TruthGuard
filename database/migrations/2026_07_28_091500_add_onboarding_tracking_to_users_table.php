<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'onboarding_completed_at')) {
                $table->timestamp('onboarding_completed_at')->nullable()->after('privacy_policy_version');
            }

            if (! Schema::hasColumn('users', 'onboarding_skipped_at')) {
                $table->timestamp('onboarding_skipped_at')->nullable()->after('onboarding_completed_at');
            }

            if (! Schema::hasColumn('users', 'onboarding_version')) {
                $table->string('onboarding_version', 40)->nullable()->after('onboarding_skipped_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $columns = array_filter([
                Schema::hasColumn('users', 'onboarding_version') ? 'onboarding_version' : null,
                Schema::hasColumn('users', 'onboarding_skipped_at') ? 'onboarding_skipped_at' : null,
                Schema::hasColumn('users', 'onboarding_completed_at') ? 'onboarding_completed_at' : null,
            ]);

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
