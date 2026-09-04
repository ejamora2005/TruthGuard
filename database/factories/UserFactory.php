<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'is_admin' => false,
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'subscription_tier' => 'free',
            'subscription_status' => 'active',
            'subscription_renews_at' => null,
            'last_login_at' => now(),
            'privacy_policy_accepted_at' => now(),
            'privacy_policy_version' => config('app.privacy_policy_version', '2026-07-28'),
            'onboarding_completed_at' => now(),
            'onboarding_skipped_at' => null,
            'onboarding_version' => config('app.onboarding_version', '2026-07-28'),
            'profile_photo_path' => null,
            'google_id' => null,
            'google_avatar_url' => null,
            'theme_preference' => 'ocean',
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
