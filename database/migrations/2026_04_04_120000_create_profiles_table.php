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
        Schema::create('profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete()->unique();
            $table->string('avatar_path')->nullable();
            $table->string('theme_preference', 40)->default('ocean');
            $table->timestamps();
        });

        DB::table('users')
            ->select('id', 'profile_photo_path', 'theme_preference')
            ->orderBy('id')
            ->chunkById(100, function ($users): void {
                $now = now();

                $rows = $users->map(fn ($user) => [
                    'user_id' => $user->id,
                    'avatar_path' => $user->profile_photo_path,
                    'theme_preference' => $user->theme_preference ?: 'ocean',
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                if ($rows !== []) {
                    DB::table('profiles')->insert($rows);
                }
            }, 'id');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('profiles');
    }
};
