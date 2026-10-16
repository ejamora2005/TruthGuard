<?php

namespace App\Providers;

use App\Http\Middleware\ExpireIdleSession;
use App\Models\Detection;
use App\Models\PushSubscription;
use App\Observers\DetectionNotificationObserver;
use App\Services\Auth\RememberedLogin;
use App\Services\Detections\Evidence\StructuredAnalysis;
use App\Services\Usage\OpenAiUsageRecorder;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(StructuredAnalysis::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(ResponseReceived::class, [OpenAiUsageRecorder::class, 'handle']);
        RateLimiter::for('detection-retry', function (Request $request) {
            $detection = $request->route('detection');
            $id = $detection instanceof Detection ? $detection->getKey() : $detection;

            return Limit::perMinute(3)->by(($request->user()?->id ?? $request->ip()).':'.$id)
                ->response(function (Request $request, array $headers) use ($id) {
                    $seconds = max(1, (int) ($headers['Retry-After'] ?? 60));
                    $message = 'This report has been rechecked several times. Its saved result is still available. Another recheck is available in '.$seconds.' seconds.';
                    if ($request->expectsJson()) {
                        return response()->json(['message' => $message, 'retry_after' => $seconds], 429, $headers);
                    }

                    return redirect()->route('detections.result', $id)->with('status', $message)->withHeaders($headers);
                });
        });
        Detection::observe(DetectionNotificationObserver::class);
        Livewire::addPersistentMiddleware([ExpireIdleSession::class]);
        Event::listen(Logout::class, function ($event) {
            Cookie::queue(Cookie::forget(RememberedLogin::KEY));
            if ($event->user && request()->cookie('truthguard_push')) {
                PushSubscription::where('user_id', $event->user->id)
                    ->where('token_hash', request()->cookie('truthguard_push'))->delete();
                Cookie::queue(Cookie::forget('truthguard_push'));
            }
        });

        Event::listen(Login::class, function ($event) {
            if ($hash = request()->cookie('truthguard_push')) {
                // Covers expired sessions followed by a different-account login.
                PushSubscription::where('token_hash', $hash)->where('user_id', '!=', $event->user->id)->delete();
            }
        });

        TrustProxies::at(config('deployment.trusted_proxies', []));

        if ($this->app->environment('production') && str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }
    }
}
