<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'email_updates_enabled')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->boolean('email_updates_enabled')
                    ->default(false)
                    ->after('email_verified_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'email_updates_enabled')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('email_updates_enabled');
            });
        }
    }
};
