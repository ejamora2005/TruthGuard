<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\SessionTimeoutManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SessionTimeoutController extends Controller
{
    public function keepAlive(Request $request, SessionTimeoutManager $sessionTimeoutManager): JsonResponse
    {
        $sessionTimeoutManager->touch($request);

        return response()
            ->json($sessionTimeoutManager->payload($request))
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }

    public function expire(Request $request, SessionTimeoutManager $sessionTimeoutManager): JsonResponse|RedirectResponse
    {
        $sessionTimeoutManager->logoutAndInvalidate($request);

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'message' => SessionTimeoutManager::EXPIRED_MESSAGE,
                'redirect_url' => route('login', absolute: false),
            ]);
        }

        return redirect()
            ->route('login')
            ->with('auth_error', SessionTimeoutManager::EXPIRED_MESSAGE);
    }
}
