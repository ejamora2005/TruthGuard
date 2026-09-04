<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn (Request $request) => route('login', absolute: false));

        $middleware->redirectUsersTo(function (Request $request): string {
            $user = $request->user();

            return $user?->isAdmin()
                ? route('admin.dashboard', absolute: false)
                : route('dashboard', absolute: false);
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (HttpException $exception, Request $request) {
            if ($exception->getStatusCode() !== 419) {
                return null;
            }

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Your session expired. Refresh the page and try again.',
                ], 419);
            }

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            $redirectTo = $request->headers->get('referer');

            if (! is_string($redirectTo) || trim($redirectTo) === '') {
                $redirectTo = route('login', absolute: false);
            }

            return redirect()->to($redirectTo)
                ->withInput($request->except(['password', 'password_confirmation']))
                ->with('auth_error', 'Your session expired. Please try again.');
        });
    })->create();
