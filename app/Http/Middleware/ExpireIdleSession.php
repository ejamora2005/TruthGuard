<?php

namespace App\Http\Middleware;

use App\Services\Auth\SessionTimeoutManager;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class ExpireIdleSession
{
    public function __construct(
        private readonly SessionTimeoutManager $sessionTimeoutManager,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check() || ! $request->hasSession()) {
            return $next($request);
        }

        $lastActivityAt = $this->sessionTimeoutManager->lastActivityAt($request);

        if ($lastActivityAt <= 0) {
            $lastActivityAt = $this->sessionTimeoutManager->touch($request);
        }

        if ((time() - $lastActivityAt) >= $this->sessionTimeoutManager->timeoutSecondsFor($request->user())) {
            return $this->sessionTimeoutManager->expiredResponse($request);
        }

        if ($this->shouldRefreshLastActivity($request)) {
            $this->sessionTimeoutManager->touch($request);
        }

        $response = $next($request);
        $payload = $this->sessionTimeoutManager->payload($request);

        $response->headers->set('X-TruthGuard-Session-Expires-At', (string) $payload['expiresAt']);
        $response->headers->set('X-TruthGuard-Session-Timeout-Seconds', (string) $payload['timeoutSeconds']);
        $response->headers->set('X-TruthGuard-Session-Warning-Seconds', (string) $payload['warningSeconds']);

        return $response;
    }

    private function shouldRefreshLastActivity(Request $request): bool
    {
        if ($request->routeIs('auth.session.keep-alive')) {
            return true;
        }

        if ($request->routeIs('auth.session.expire') || $request->routeIs('logout')) {
            return false;
        }

        if ($request->is('admin/api/*') || $request->routeIs('dashboard.fact-check-feed')) {
            return false;
        }

        if ($request->expectsJson() || $request->ajax() || $request->headers->has('X-Livewire')) {
            return false;
        }

        if ($request->isMethod('OPTIONS')) {
            return false;
        }

        return true;
    }
}
