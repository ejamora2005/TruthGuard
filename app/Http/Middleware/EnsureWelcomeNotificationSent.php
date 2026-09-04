<?php

namespace App\Http\Middleware;

use App\Services\Notifications\TruthGuardNotificationManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureWelcomeNotificationSent
{
    public function __construct(
        private readonly TruthGuardNotificationManager $notifications,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldBackfillWelcome($request)) {
            $this->notifications->sendWelcomeOnce($request->user());
        }

        return $next($request);
    }

    private function shouldBackfillWelcome(Request $request): bool
    {
        return $request->user() !== null
            && $request->isMethod('GET')
            && ! $request->expectsJson()
            && ! $request->ajax()
            && ! $request->headers->has('X-Livewire');
    }
}
