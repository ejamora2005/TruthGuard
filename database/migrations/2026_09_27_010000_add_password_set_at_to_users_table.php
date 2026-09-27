<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'password_set_at')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->timestamp('password_set_at')->nullable()->after('password');
            });
        }

        DB::table('users')
            ->whereNull('google_id')
            ->whereNull('password_set_at')
            ->update(['password_set_at' => now()]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'password_set_at')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('password_set_at');
            });
        }
    }
};
