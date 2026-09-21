<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\SessionTimeoutManager;
use App\Services\Notifications\TruthGuardNotificationManager;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use RuntimeException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Throwable;

class GoogleAuthController extends Controller
{
    public function redirectToGoogle(): RedirectResponse
    {
        if (! $this->providerConfigured('google')) {
            return redirect()
                ->route('login')
                ->with('auth_error', 'Google sign-in is not configured yet.');
        }

        return $this->socialiteDriver('google')->redirect();
    }

    public function handleGoogleCallback(): RedirectResponse
    {
        try {
            $socialUser = $this->socialiteDriver('google')->user();
            $user = $this->resolveGoogleUser($socialUser);

            return $this->loginSocialUser($user);
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('login')
                ->with('auth_error', $exception->getMessage());
        } catch (Throwable) {
            return redirect()
                ->route('login')
                ->with('auth_error', 'Google sign-in failed. Please try again.');
        }
    }

    public function redirectToFacebook(): RedirectResponse
    {
        if (! $this->providerConfigured('facebook')) {
            return redirect()
                ->route('login')
                ->with('auth_error', 'Facebook sign-in is not configured yet.');
        }

        return $this->socialiteDriver('facebook')
            ->scopes(['email'])
            ->fields(['name', 'email'])
            ->redirect();
    }

    public function handleFacebookCallback(): RedirectResponse
    {
        return $this->handleProviderCallback(
            provider: 'facebook',
            errorMessage: 'Facebook sign-in failed. Please try again.'
        );
    }

    private function handleProviderCallback(string $provider, string $errorMessage): RedirectResponse
    {
        try {
            $socialUser = $this->socialiteDriver($provider)->user();
        } catch (Throwable) {
            return redirect()
                ->route('login')
                ->with('auth_error', $errorMessage);
        }

        $email = $socialUser->getEmail();

        if (! $email) {
            return redirect()
                ->route('login')
                ->with('auth_error', ucfirst($provider).' account did not provide an email address.');
        }

        try {
            $user = $this->resolveLegacySocialUser($socialUser);

            return $this->loginSocialUser($user);
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('login')
                ->with('auth_error', $exception->getMessage());
        }
    }

    private function resolveGoogleUser(SocialiteUser $socialUser): User
    {
        $googleId = $this->nullableString($socialUser->getId());
        $email = $this->normalizeEmail($socialUser->getEmail());

        if ($googleId === null) {
            throw new RuntimeException('Google did not provide a stable account identifier.');
        }

        if ($email === null) {
            throw new RuntimeException('Google account did not provide an email address.');
        }

        if (! $this->googleEmailIsVerified($socialUser)) {
            throw new RuntimeException('Use a Google account with a verified email address.');
        }

        return DB::transaction(function () use ($socialUser, $googleId, $email) {
            $user = User::query()
                ->where('google_id', $googleId)
                ->lockForUpdate()
                ->first();

            if ($user) {
                return $this->updateLinkedGoogleUser($user, $socialUser, $googleId, $email);
            }

            $existingUser = $this->findUserByEmail($email);

            if ($existingUser) {
                if (filled($existingUser->google_id) && $existingUser->google_id !== $googleId) {
                    throw new RuntimeException('This email is already linked to another Google account.');
                }

                return $this->updateLinkedGoogleUser($existingUser, $socialUser, $googleId, $email);
            }

            return User::query()->create([
                'name' => $this->nullableString($socialUser->getName()) ?: Str::before($email, '@'),
                'email' => $email,
                'email_verified_at' => now(),
                'password' => Hash::make(Str::random(48)),
                'is_admin' => false,
                'subscription_tier' => 'free',
                'subscription_status' => 'active',
                'google_id' => $googleId,
                'google_avatar_url' => $this->normalizeUrl($socialUser->getAvatar()),
                'last_login_at' => null,
                'theme_preference' => 'ocean',
            ]);
        });
    }

    private function updateLinkedGoogleUser(User $user, SocialiteUser $socialUser, string $googleId, string $email): User
    {
        $this->ensureUserCanSignIn($user);

        $emailOwner = $this->findUserByEmail($email, $user->id);

        if ($emailOwner) {
            throw new RuntimeException('This Google email is already used by another TruthGuard account.');
        }

        $updates = [
            'google_id' => $googleId,
            'google_avatar_url' => $this->normalizeUrl($socialUser->getAvatar()),
        ];

        if ($this->normalizeEmail($user->email) !== $email) {
            $updates['email'] = $email;
        }

        if (! $user->email_verified_at) {
            $updates['email_verified_at'] = now();
        }

        if (blank($user->name) && filled($socialUser->getName())) {
            $updates['name'] = $this->nullableString($socialUser->getName());
        }

        $user->forceFill($updates)->save();

        return $user->refresh();
    }

    private function resolveLegacySocialUser(SocialiteUser $socialUser): User
    {
        $email = (string) $socialUser->getEmail();

        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $socialUser->getName() ?: Str::before($email, '@'),
                'password' => Hash::make(Str::random(40)),
                'email_verified_at' => now(),
                'is_admin' => false,
                'subscription_tier' => 'free',
                'subscription_status' => 'active',
            ]
        );

        $this->ensureUserCanSignIn($user);

        $updates = [];

        if (! $user->name && $socialUser->getName()) {
            $updates['name'] = $socialUser->getName();
        }

        if (! $user->email_verified_at) {
            $updates['email_verified_at'] = now();
        }

        if ($updates !== []) {
            $user->forceFill($updates)->save();
        }

        return $user;
    }

    private function loginSocialUser(User $user): RedirectResponse
    {
        $this->ensureUserCanSignIn($user);

        Auth::login($user, remember: false);
        request()->session()->regenerate();
        app(SessionTimeoutManager::class)->touch(request());

        $user->forceFill(['last_login_at' => now()])->save();
        app(TruthGuardNotificationManager::class)->sendWelcomeOnce($user);

        return redirect()->to($this->postLoginPath($user));
    }

    private function ensureUserCanSignIn(User $user): void
    {
        $status = Str::lower(trim((string) $user->subscription_status));

        if (in_array($status, ['inactive', 'suspended'], true)) {
            throw new RuntimeException('Your account is not active. Please contact TruthGuard support.');
        }
    }

    private function findUserByEmail(string $email, ?int $exceptUserId = null): ?User
    {
        return User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->when($exceptUserId !== null, fn ($query) => $query->whereKeyNot($exceptUserId))
            ->lockForUpdate()
            ->first();
    }

    private function googleEmailIsVerified(SocialiteUser $socialUser): bool
    {
        $raw = $socialUser->getRaw() ?? [];
        $verified = data_get($raw, 'verified_email', data_get($raw, 'email_verified'));

        return filter_var($verified, FILTER_VALIDATE_BOOLEAN) === true;
    }

    private function normalizeEmail(mixed $email): ?string
    {
        $email = Str::lower(trim((string) $email));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function normalizeUrl(mixed $url): ?string
    {
        $url = $this->nullableString($url);

        if ($url === null || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        return Str::limit($url, 2048, '');
    }

    private function providerConfigured(string $provider): bool
    {
        $clientId = (string) config("services.{$provider}.client_id");
        $clientSecret = (string) config("services.{$provider}.client_secret");

        return $clientId !== '' && $clientSecret !== '';
    }

    private function socialiteDriver(string $provider)
    {
        $callbackRoute = "{$provider}.callback";
        $redirectUri = route($callbackRoute);

        return Socialite::driver($provider)
            ->redirectUrl($redirectUri)
            ->stateless();
    }

    private function postLoginPath(User $user): string
    {
        return app(\App\Services\Auth\PostLoginDestination::class)->resolve(request(), $user);
    }
}
