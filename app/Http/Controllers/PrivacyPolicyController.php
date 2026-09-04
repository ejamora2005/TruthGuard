<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PrivacyPolicyController extends Controller
{
    public function policy(): View
    {
        return view('privacy.policy', [
            'requiresConsent' => false,
            'policyVersion' => (string) config('app.privacy_policy_version', '2026-07-28'),
        ]);
    }

    public function consent(Request $request): View|RedirectResponse
    {
        if ($request->user()->hasAcceptedCurrentPrivacyPolicy()) {
            return redirect()->to($this->postConsentPath($request));
        }

        return view('privacy.consent', [
            'policyVersion' => (string) config('app.privacy_policy_version', '2026-07-28'),
        ]);
    }

    public function accept(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'privacy_policy_acceptance' => ['accepted'],
        ], [
            'privacy_policy_acceptance.accepted' => 'Please confirm the Privacy Policy before continuing.',
        ]);

        $request->user()->acceptCurrentPrivacyPolicy();
        $request->session()->put('privacy_policy_accepted_at', now()->toIso8601String());

        return redirect()
            ->to($this->postConsentPath($request))
            ->with('status', 'Privacy Policy accepted. Welcome to your TruthGuard workspace.');
    }

    private function postConsentPath(Request $request): string
    {
        $intended = $request->session()->pull('privacy.intended');

        if (is_string($intended) && trim($intended) !== '') {
            return $intended;
        }

        return $request->user()->isAdmin()
            ? route('admin.dashboard', absolute: false)
            : route('dashboard', absolute: false);
    }
}
