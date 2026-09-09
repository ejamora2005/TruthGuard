<?php

namespace App\Http\Controllers;

use App\Services\Facebook\FacebookWebhookService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FacebookWebhookSimulatorController extends Controller
{
    public function create(): View
    {
        return view('facebook.webhook-simulator');
    }

    public function store(Request $request, FacebookWebhookService $facebookWebhookService): RedirectResponse
    {
        $validated = $request->validate([
            'caption_text' => ['required', 'string', 'max:4000'],
            'source_url' => ['nullable', 'url', 'max:2048'],
        ]);

        $result = $facebookWebhookService->simulateMention(
            $validated['caption_text'],
            $validated['source_url'] ?? null,
        );

        return redirect()
            ->route('facebook.webhook-simulator.create')
            ->with('facebook_webhook_simulation', [
                'detection_id' => $result['detection']->id,
                'verdict' => $result['detection']->verdict,
                'fake_score' => $result['detection']->fake_score,
                'reply' => $result['reply'],
            ]);
    }
}
