<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('email_verified_at');
            $table->string('subscription_tier', 40)->default('free')->after('remember_token');
            $table->string('subscription_status', 40)->default('active')->after('subscription_tier');
            $table->timestamp('subscription_renews_at')->nullable()->after('subscription_status');
            $table->timestamp('last_login_at')->nullable()->after('subscription_renews_at');

            $table->index(['is_admin', 'subscription_tier']);
        });

        $adminEmails = array_values(array_filter(array_map(
            static fn (string $email): string => strtolower(trim($email)),
            explode(',', (string) env('TRUTHGUARD_ADMIN_EMAILS', ''))
        )));

        if ($adminEmails !== []) {
            DB::table('users')
                ->whereIn(DB::raw('LOWER(email)'), $adminEmails)
                ->update([
                    'is_admin' => true,
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['is_admin', 'subscription_tier']);
            $table->dropColumn([
                'is_admin',
                'subscription_tier',
                'subscription_status',
                'subscription_renews_at',
                'last_login_at',
            ]);
        });
    }
};
