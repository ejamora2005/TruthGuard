<?php

namespace App\Providers;

use App\Models\Detection;
use App\Models\PushSubscription;
use App\Observers\DetectionNotificationObserver;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Detection::observe(DetectionNotificationObserver::class);
        Event::listen(Logout::class, function ($event) {
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
