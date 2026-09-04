<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

#[Fillable([
    'name',
    'username',
    'email',
    'password',
    'is_admin',
    'subscription_tier',
    'subscription_status',
    'subscription_renews_at',
    'last_login_at',
    'privacy_policy_accepted_at',
    'privacy_policy_version',
    'onboarding_completed_at',
    'onboarding_skipped_at',
    'onboarding_version',
    'profile_photo_path',
    'google_id',
    'google_avatar_url',
    'theme_preference',
])]
#[Hidden(['password', 'remember_token', 'google_id'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected static ?bool $profilesTableExists = null;

    protected static function booted(): void
    {
        static::created(function (User $user): void {
            if (! static::profileTableExists()) {
                return;
            }

            $user->profile()->firstOrCreate([], [
                'avatar_path' => $user->getRawOriginal('profile_photo_path'),
                'theme_preference' => $user->getRawOriginal('theme_preference') ?: 'ocean',
            ]);
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'subscription_renews_at' => 'datetime',
            'last_login_at' => 'datetime',
            'privacy_policy_accepted_at' => 'datetime',
            'onboarding_completed_at' => 'datetime',
            'onboarding_skipped_at' => 'datetime',
        ];
    }

    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }

    public static function profileTableExists(): bool
    {
        return static::$profilesTableExists ??= Schema::hasTable('profiles');
    }

    protected function resolveProfile(): ?Profile
    {
        if (! static::profileTableExists()) {
            return null;
        }

        if ($this->relationLoaded('profile')) {
            return $this->getRelation('profile');
        }

        return $this->profile()->first();
    }

    public function getProfilePhotoPathAttribute($value): ?string
    {
        $profile = $this->resolveProfile();

        if ($profile) {
            return $profile->avatar_path;
        }

        return $value;
    }

    public function getThemePreferenceAttribute($value): string
    {
        $profile = $this->resolveProfile();

        if ($profile) {
            return $profile->theme_preference ?: 'ocean';
        }

        return $value ?: 'ocean';
    }

    public function syncProfilePreferences(?string $avatarPath, string $themePreference): void
    {
        $this->forceFill([
            'profile_photo_path' => $avatarPath,
            'theme_preference' => $themePreference,
        ])->save();

        if (! static::profileTableExists()) {
            return;
        }

        $this->profile()->updateOrCreate([], [
            'avatar_path' => $avatarPath,
            'theme_preference' => $themePreference,
        ]);

        $this->unsetRelation('profile');
    }

    public function getProfilePhotoUrlAttribute(): ?string
    {
        if (! $this->profile_photo_path) {
            return null;
        }

        $normalizedPath = ltrim(str_replace('\\', '/', (string) $this->profile_photo_path), '/');

        return '/storage/'.$normalizedPath;
    }

    public function getDefaultProfileAvatarPathAttribute(): string
    {
        $theme = in_array((string) $this->theme_preference, ['ocean', 'forest', 'sunset'], true)
            ? (string) $this->theme_preference
            : 'ocean';

        return "images/avatars/default-{$theme}.svg";
    }

    public function getDefaultProfileAvatarUrlAttribute(): string
    {
        return asset($this->default_profile_avatar_path);
    }

    public function getDisplayProfileAvatarUrlAttribute(): string
    {
        return $this->profile_photo_url
            ?: ($this->google_avatar_url ?: $this->default_profile_avatar_url);
    }

    public function getUsesStarterProfileAvatarAttribute(): bool
    {
        return ! filled($this->profile_photo_path) && ! filled($this->google_avatar_url);
    }

    public function getProfileInitialsAttribute(): string
    {
        $initials = collect(preg_split('/\s+/', trim((string) $this->name)) ?: [])
            ->filter()
            ->take(2)
            ->map(fn (string $part) => Str::upper(Str::substr($part, 0, 1)))
            ->implode('');

        return $initials !== '' ? $initials : 'TG';
    }

    public function detections(): HasMany
    {
        return $this->hasMany(Detection::class);
    }

    public function scrapeRuns(): HasMany
    {
        return $this->hasMany(ScrapeRun::class, 'triggered_by_user_id');
    }

    public function isAdmin(): bool
    {
        return (bool) $this->is_admin;
    }

    public function hasAcceptedCurrentPrivacyPolicy(): bool
    {
        return $this->privacy_policy_accepted_at !== null
            && (string) $this->privacy_policy_version === (string) config('app.privacy_policy_version', '2026-07-28');
    }

    public function acceptCurrentPrivacyPolicy(): void
    {
        $this->forceFill([
            'privacy_policy_accepted_at' => now(),
            'privacy_policy_version' => (string) config('app.privacy_policy_version', '2026-07-28'),
        ])->save();
    }

    public function hasCurrentOnboardingDecision(): bool
    {
        return (string) $this->onboarding_version === (string) config('app.onboarding_version', '2026-07-28')
            && ($this->onboarding_completed_at !== null || $this->onboarding_skipped_at !== null);
    }

    public function needsCurrentOnboarding(): bool
    {
        return $this->hasAcceptedCurrentPrivacyPolicy()
            && ! $this->hasCurrentOnboardingDecision();
    }

    public function completeCurrentOnboarding(): void
    {
        $this->forceFill([
            'onboarding_completed_at' => now(),
            'onboarding_skipped_at' => null,
            'onboarding_version' => (string) config('app.onboarding_version', '2026-07-28'),
        ])->save();
    }

    public function skipCurrentOnboarding(): void
    {
        $this->forceFill([
            'onboarding_completed_at' => null,
            'onboarding_skipped_at' => now(),
            'onboarding_version' => (string) config('app.onboarding_version', '2026-07-28'),
        ])->save();
    }
}
