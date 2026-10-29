<?php

namespace App\Livewire\Actions;

use App\Services\Detections\ActiveDetectionManager;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

class Logout
{
    /**
     * Log the current user out of the application.
     */
    public function __invoke(ActiveDetectionManager $activeDetections): void
    {
        if (Auth::user()) {
            $activeDetections->cancelForUser(Auth::id());
        }

        Auth::guard('web')->logout();

        Session::invalidate();
        Session::regenerateToken();
    }
}
