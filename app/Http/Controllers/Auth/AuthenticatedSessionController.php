<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\Auth\PostLoginDestination;
use App\Services\Auth\SessionTimeoutManager;
use App\Services\Notifications\TruthGuardNotificationManager;
use Illuminate\Http\JsonResponse;
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
    ): RedirectResponse|JsonResponse {
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

        $destination = app(PostLoginDestination::class)->resolve($request, $user);
        $request->session()->flash('truthguard_auth_completed', true);

        if ($request->expectsJson()) {
            return response()->json(['authenticated' => true, 'redirect' => $destination])
                ->header('Cache-Control', 'no-store');
        }

        return redirect()->to($destination);
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
