<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new \RuntimeException('Demo accounts must not be seeded in production.');
        }

        // User::factory(10)->create();

        $admin = User::query()
            ->where('username', 'admin')
            ->orWhere('email', 'admin@truthguard.local')
            ->first() ?? new User();

        $admin->forceFill([
            'name' => 'Admin',
            'username' => 'admin',
            'email' => 'admin@truthguard.local',
            'email_verified_at' => now(),
            'password' => Hash::make('admin123'),
            'is_admin' => true,
            'subscription_tier' => 'institutional',
            'subscription_status' => 'active',
            'last_login_at' => null,
        ])->save();

        User::updateOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'email_verified_at' => now(),
                'password' => Hash::make('password'),
                'is_admin' => false,
                'subscription_tier' => 'free',
                'subscription_status' => 'active',
            ],
        );
    }
}
