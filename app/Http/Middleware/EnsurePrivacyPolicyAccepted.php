<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePrivacyPolicyAccepted
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->hasAcceptedCurrentPrivacyPolicy()) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Please review and accept the TruthGuard Privacy Policy before continuing.',
                'privacyPolicyRequired' => true,
                'redirect' => route('privacy.consent', absolute: false),
            ], 409);
        }

        if ($request->isMethod('GET')) {
            $request->session()->put('privacy.intended', $request->getRequestUri());
        }

        return redirect()->route('privacy.consent');
    }
}
