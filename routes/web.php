<?php

use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\SessionTimeoutController;
use App\Http\Controllers\AdminAppController;
use App\Http\Controllers\AdminDataController;
use App\Http\Controllers\AdminFactCheckSourceController;
use App\Http\Controllers\AutomationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DetectionController;
use App\Http\Controllers\FacebookWebhookSimulatorController;
use App\Http\Controllers\FacebookWebhookController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\PrivacyPolicyController;
use App\Http\Controllers\ProfileController;
use App\Http\Middleware\EnsureAutomationToken;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsurePrivacyPolicyAccepted;
use App\Http\Middleware\EnsureWelcomeNotificationSent;
use App\Http\Middleware\ExpireIdleSession;
use App\Livewire\Auth\ForgotPassword;
use App\Livewire\Auth\ForgotPasswordSent;
use App\Livewire\Auth\ResetPassword;
use App\Livewire\WelcomePage;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;

Route::get('/', WelcomePage::class)->name('home');
Route::get('privacy-policy', [PrivacyPolicyController::class, 'policy'])->name('privacy.policy');

Route::get('auth/google/redirect', [GoogleAuthController::class, 'redirectToGoogle'])->name('google.redirect');
Route::get('auth/google/callback', [GoogleAuthController::class, 'handleGoogleCallback'])->name('google.callback');
Route::get('auth/facebook/redirect', [GoogleAuthController::class, 'redirectToFacebook'])->name('facebook.redirect');
Route::get('auth/facebook/callback', [GoogleAuthController::class, 'handleFacebookCallback'])->name('facebook.callback');

Route::get('webhooks/facebook', [FacebookWebhookController::class, 'verify'])->name('webhooks.facebook.verify');
Route::post('webhooks/facebook', [FacebookWebhookController::class, 'handle'])
    ->withoutMiddleware(PreventRequestForgery::class)
    ->name('webhooks.facebook.handle');

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store']);
    Route::get('register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('register', [RegisteredUserController::class, 'store']);
    Route::get('forgot-password', ForgotPassword::class)->name('password.request');
    Route::get('forgot-password/sent', ForgotPasswordSent::class)->name('password.sent');
    Route::get('reset-password/{token}', ResetPassword::class)->name('password.reset');
});

Route::middleware(['auth', ExpireIdleSession::class, EnsureWelcomeNotificationSent::class])->group(function () {
    Route::post('auth/session/keep-alive', [SessionTimeoutController::class, 'keepAlive'])->name('auth.session.keep-alive');
    Route::post('auth/session/expire', [SessionTimeoutController::class, 'expire'])->name('auth.session.expire');

    Route::get('privacy-policy/consent', [PrivacyPolicyController::class, 'consent'])->name('privacy.consent');
    Route::post('privacy-policy/consent', [PrivacyPolicyController::class, 'accept'])->name('privacy.accept');

    Route::middleware(EnsurePrivacyPolicyAccepted::class)->group(function () {
        Route::post('onboarding/complete', [OnboardingController::class, 'complete'])->name('onboarding.complete');
        Route::post('onboarding/skip', [OnboardingController::class, 'skip'])->name('onboarding.skip');
        Route::redirect('admin', 'admin/dashboard');
        Route::prefix('admin/api')
            ->middleware(EnsureAdmin::class)
            ->group(function () {
                Route::get('dashboard', [AdminDataController::class, 'dashboard']);
                Route::get('users', [AdminDataController::class, 'users']);
                Route::get('subscriptions', [AdminDataController::class, 'subscriptions']);
                Route::get('detections', [AdminDataController::class, 'detections']);
                Route::get('ai-usage', [AdminDataController::class, 'aiUsage']);
                Route::get('project-tracker', [AdminDataController::class, 'projectTracker']);
                Route::get('fact-check-sources', [AdminFactCheckSourceController::class, 'index']);
                Route::post('fact-check-sources', [AdminFactCheckSourceController::class, 'store']);
                Route::patch('fact-check-sources/{factCheckSource}', [AdminFactCheckSourceController::class, 'update']);
                Route::delete('fact-check-sources/{factCheckSource}', [AdminFactCheckSourceController::class, 'destroy']);
            });
        Route::get('admin/dashboard', AdminAppController::class)
            ->middleware(EnsureAdmin::class)
            ->name('admin.dashboard');
        Route::get('admin/facebook-webhook-simulator', [FacebookWebhookSimulatorController::class, 'create'])
            ->middleware(EnsureAdmin::class)
            ->name('facebook.webhook-simulator.create');
        Route::post('admin/facebook-webhook-simulator', [FacebookWebhookSimulatorController::class, 'store'])
            ->middleware(EnsureAdmin::class)
            ->name('facebook.webhook-simulator.store');
        Route::get('admin/{any}', AdminAppController::class)
            ->where('any', '.*')
            ->middleware(EnsureAdmin::class);
        Route::get('dashboard', DashboardController::class)->name('dashboard');
        Route::get('dashboard/fact-check-feed', [DashboardController::class, 'factCheckFeed'])->name('dashboard.fact-check-feed');
        Route::get('dashboard/fact-checks/{factCheck}', [DashboardController::class, 'factCheck'])->name('dashboard.fact-check');
        Route::get('history', [DashboardController::class, 'history'])->name('history');
        Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::get('notifications/peek', [NotificationController::class, 'peek'])->name('notifications.peek');
        Route::post('notifications/mark-all-read', [NotificationController::class, 'markAllAsRead'])->name('notifications.mark-all-read');
        Route::post('notifications/{notification}/read', [NotificationController::class, 'markAsRead'])->name('notifications.read');
        Route::delete('notifications/{notification}', [NotificationController::class, 'destroy'])->name('notifications.destroy');
        Route::get('detections/create', [DetectionController::class, 'create'])->name('detections.create');
        Route::get('detections/{detection}/result', [DetectionController::class, 'result'])->name('detections.result');
        Route::post('detections', [DetectionController::class, 'store'])->name('detections.store');
        Route::patch('profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::view('profile', 'profile')->name('profile');
    });
});

Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware(['auth', ExpireIdleSession::class])
    ->name('logout');

Route::post('automation/playwright/runs', [AutomationController::class, 'store'])
    ->withoutMiddleware(PreventRequestForgery::class)
    ->middleware(EnsureAutomationToken::class)
    ->name('automation.playwright.runs.store');
