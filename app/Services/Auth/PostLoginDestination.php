<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Http\Request;

class PostLoginDestination
{
    public function resolve(Request $request, User $user): string
    {
        $intended = $request->session()->pull('url.intended');
        if ($user->isAdmin()) {
            return route('admin.dashboard', absolute: false);
        }

        // Only resume same-site review URLs; ignore arbitrary redirect destinations.
        $pattern = str_replace('REVIEW_ID', '[A-Za-z0-9_-]+', preg_quote(
            route('dashboard.fact-check', ['factCheck' => 'REVIEW_ID']), '~'
        ));
        if (is_string($intended) && preg_match('~^'.$pattern.'$~D', $intended)) {
            return $intended;
        }

        return route('dashboard', absolute: false);
    }
}
