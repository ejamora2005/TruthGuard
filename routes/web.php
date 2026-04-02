<?php

use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DetectionController;
use App\Livewire\Auth\ForgotPassword;
use App\Livewire\Auth\ForgotPasswordSent;
use App\Livewire\Auth\ResetPassword;
use App\Livewire\WelcomePage;
use Illuminate\Support\Facades\Route;

Route::get('/', WelcomePage::class)->name('home');

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store']);
    Route::get('register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('register', [RegisteredUserController::class, 'store']);
    Route::get('auth/google/redirect', [GoogleAuthController::class, 'redirectToGoogle'])->name('google.redirect');
    Route::get('auth/google/callback', [GoogleAuthController::class, 'handleGoogleCallback'])->name('google.callback');
    Route::get('auth/facebook/redirect', [GoogleAuthController::class, 'redirectToFacebook'])->name('facebook.redirect');
    Route::get('auth/facebook/callback', [GoogleAuthController::class, 'handleFacebookCallback'])->name('facebook.callback');
    Route::get('forgot-password', ForgotPassword::class)->name('password.request');
    Route::get('forgot-password/sent', ForgotPasswordSent::class)->name('password.sent');
    Route::get('reset-password/{token}', ResetPassword::class)->name('password.reset');
});

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('detections/create', [DetectionController::class, 'create'])->name('detections.create');
    Route::post('detections', [DetectionController::class, 'store'])->name('detections.store');
    Route::view('profile', 'profile')->name('profile');
});

Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');
