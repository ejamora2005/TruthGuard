<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'privacy_policy_accepted_at')) {
                $table->timestamp('privacy_policy_accepted_at')->nullable()->after('last_login_at');
            }

            if (! Schema::hasColumn('users', 'privacy_policy_version')) {
                $table->string('privacy_policy_version', 40)->nullable()->after('privacy_policy_accepted_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $columns = array_filter([
                Schema::hasColumn('users', 'privacy_policy_version') ? 'privacy_policy_version' : null,
                Schema::hasColumn('users', 'privacy_policy_accepted_at') ? 'privacy_policy_accepted_at' : null,
            ]);

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
