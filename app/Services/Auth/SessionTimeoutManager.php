<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class SessionTimeoutManager
{
    public const LAST_ACTIVITY_KEY = 'auth_last_activity_at';

    public const EXPIRED_MESSAGE = 'Your session expired due to inactivity. Please sign in again.';

    public function timeoutMinutesFor(?User $user): int
    {
        $fallback = (int) config('session.auth_timeout_minutes', config('session.lifetime', 120));
        $minutes = $user?->isAdmin()
            ? (int) config('session.auth_timeout_admin_minutes', 15)
            : (int) config('session.auth_timeout_user_minutes', $fallback);

        return max(1, $minutes);
    }

    public function timeoutSecondsFor(?User $user): int
    {
        return $this->timeoutMinutesFor($user) * 60;
    }

    public function warningSecondsFor(?User $user): int
    {
        $timeoutSeconds = $this->timeoutSecondsFor($user);
        $warningSeconds = max(5, (int) config('session.auth_timeout_warning_seconds', 60));

        return min($warningSeconds, max(1, $timeoutSeconds - 1));
    }

    public function lastActivityAt(Request $request): int
    {
        return (int) $request->session()->get(self::LAST_ACTIVITY_KEY, 0);
    }

    public function touch(Request $request): int
    {
        $timestamp = time();
        $request->session()->put(self::LAST_ACTIVITY_KEY, $timestamp);

        return $timestamp;
    }

    /**
     * @return array<string, int|string>
     */
    public function payload(Request $request): array
    {
        $user = $request->user();
        $timeoutSeconds = $this->timeoutSecondsFor($user);
        $lastActivityAt = $this->lastActivityAt($request) ?: time();

        return [
            'lastActivityAt' => $lastActivityAt,
            'expiresAt' => $lastActivityAt + $timeoutSeconds,
            'timeoutSeconds' => $timeoutSeconds,
            'warningSeconds' => $this->warningSecondsFor($user),
            'keepAliveUrl' => route('auth.session.keep-alive', absolute: false),
            'expireUrl' => route('auth.session.expire', absolute: false),
            'logoutUrl' => route('logout', absolute: false),
            'loginUrl' => route('login', absolute: false),
            'message' => self::EXPIRED_MESSAGE,
        ];
    }

    public function expiredResponse(Request $request): Response|RedirectResponse|JsonResponse
    {
        $this->logoutAndInvalidate($request);

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'message' => self::EXPIRED_MESSAGE,
                'redirect_url' => route('login', absolute: false),
            ], 419);
        }

        return redirect()
            ->route('login')
            ->with('auth_error', self::EXPIRED_MESSAGE);
    }

    public function logoutAndInvalidate(Request $request): void
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
