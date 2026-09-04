<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\Auth\SessionTimeoutManager;
use App\Services\Notifications\TruthGuardNotificationManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(Request $request): Response
    {
        $request->session()->regenerateToken();

        return response()
            ->view('auth.login')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Expires', 'Sat, 01 Jan 2000 00:00:00 GMT');
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(
        LoginRequest $request,
        SessionTimeoutManager $sessionTimeoutManager,
        TruthGuardNotificationManager $notifications,
    ): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();
        $sessionTimeoutManager->touch($request);

        $user = $request->user();

        if ($user) {
            $user->forceFill([
                'last_login_at' => now(),
            ])->save();

            $notifications->sendWelcomeOnce($user);
        }

        $request->session()->forget('url.intended');

        return redirect()->to(
            $user?->isAdmin()
                ? route('admin.dashboard', absolute: false)
                : route('dashboard', absolute: false)
        );
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect()->to(route('login', absolute: false));
    }
}
