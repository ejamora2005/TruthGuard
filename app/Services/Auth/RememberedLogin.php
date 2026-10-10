<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;

class RememberedLogin
{
    public const KEY = 'truthguard_remembered_login';

    public const MINUTES = 30 * 24 * 60;

    public function establish(User $user, bool $requested): void
    {
        $remember = $requested && ! $user->isAdmin();
        $guard = Auth::guard('web');
        $guard->setRememberDuration(self::MINUTES);
        $guard->login($user, $remember);
        request()->session()->forget(self::KEY);
        if (! $remember) {
            Cookie::queue(Cookie::forget(self::KEY));
            Cookie::queue(Cookie::forget($guard->getRecallerName()));

            return;
        }
        $record = ['user' => (string) $user->id, 'until' => now()->timestamp + self::MINUTES * 60,
            'credential' => hash('sha256', $user->getAuthPassword())];
        request()->session()->put(self::KEY, $record);
        // Laravel's EncryptCookies protects this absolute deadline against tampering.
        Cookie::queue(cookie(self::KEY, json_encode($record), self::MINUTES, '/',
            config('session.domain'), config('session.secure'), true, false, 'lax'));
    }

    // null means an ordinary session; zero means remembered access must expire.
    public function deadline(Request $request): ?int
    {
        $guard = Auth::guard('web');
        $record = $request->session()->get(self::KEY);
        if (! $record && $guard->viaRemember()) {
            $record = json_decode($request->cookie(self::KEY, ''), true);
            if (! is_array($record)) {
                return 0;
            }
            $request->session()->put(self::KEY, $record);
        }
        if (! $record) {
            return null;
        }
        $user = $request->user();
        if (! $user || $user->isAdmin() || ($record['user'] ?? '') !== (string) $user->id
            || ! hash_equals(hash('sha256', $user->getAuthPassword()), (string) ($record['credential'] ?? ''))) {
            return 0;
        }

        return (int) ($record['until'] ?? 0);
    }
}
