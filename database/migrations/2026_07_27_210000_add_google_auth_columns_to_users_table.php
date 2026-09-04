<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'google_id')) {
                $table->string('google_id')->nullable()->unique()->after('remember_token');
            }

            if (! Schema::hasColumn('users', 'google_avatar_url')) {
                $table->string('google_avatar_url', 2048)->nullable()->after('google_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'google_id')) {
                $table->dropUnique('users_google_id_unique');
            }

            $columns = array_values(array_filter([
                Schema::hasColumn('users', 'google_id') ? 'google_id' : null,
                Schema::hasColumn('users', 'google_avatar_url') ? 'google_avatar_url' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
