<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

class AdminAppController extends Controller
{
    public function __invoke(): View
    {
        $logoPath = ltrim((string) config('app.truthguard_logo', 'images/truthguard-logo.png'), '/');
        $logoUrl = is_file(public_path($logoPath))
            ? asset($logoPath)
            : asset('images/logo/logo.svg');
        $logoDarkUrl = $logoUrl;
        $logoIconUrl = $logoUrl;
        $user = auth()->user();

        return view('admin.app', [
            'adminUserConfig' => [
                'brandName' => 'TruthGuard Admin',
                'logoUrl' => $logoUrl,
                'logoDarkUrl' => $logoDarkUrl,
                'logoIconUrl' => $logoIconUrl,
                'apiBaseUrl' => url('admin/api'),
                'user' => [
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatarUrl' => $user->display_profile_avatar_url,
                    'initial' => strtoupper(substr($user->name, 0, 1)),
                ],
                'logoutUrl' => route('logout'),
                'loginUrl' => route('login'),
                'dashboardPath' => '/admin/dashboard',
                'websiteUrl' => route('home'),
            ],
        ]);
    }
}
