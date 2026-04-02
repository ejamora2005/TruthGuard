<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
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
        return $this->handleProviderCallback(
            provider: 'google',
            errorMessage: 'Google sign-in failed. Please try again.'
        );
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

        $user = $this->resolveUser($socialUser);

        Auth::login($user, remember: true);
        request()->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    private function resolveUser(SocialiteUser $socialUser): User
    {
        $email = (string) $socialUser->getEmail();

        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $socialUser->getName() ?: Str::before($email, '@'),
                'password' => Hash::make(Str::random(40)),
                'email_verified_at' => now(),
            ]
        );

        if (! $user->name && $socialUser->getName()) {
            $user->forceFill(['name' => $socialUser->getName()])->save();
        }

        if (! $user->email_verified_at) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return $user;
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

        return Socialite::driver($provider)->redirectUrl($redirectUri);
    }
}
