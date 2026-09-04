<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAutomationToken
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->isAdmin()) {
            return $next($request);
        }

        $configuredToken = (string) config('playwright.automation_token', '');

        if ($configuredToken === '') {
            abort(403, 'The Playwright automation token is not configured.');
        }

        $providedToken = $request->bearerToken() ?: $request->header('X-TruthGuard-Automation-Token');

        if (! is_string($providedToken) || $providedToken === '' || ! hash_equals($configuredToken, $providedToken)) {
            abort(401, 'The provided automation token is invalid.');
        }

        return $next($request);
    }
}
