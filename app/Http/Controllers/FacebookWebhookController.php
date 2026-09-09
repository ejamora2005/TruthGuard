<?php

namespace App\Http\Controllers;

use App\Services\Facebook\FacebookWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class FacebookWebhookController extends Controller
{
    public function verify(Request $request): Response
    {
        $mode = $request->query('hub_mode', $request->query('hub.mode'));
        $token = $request->query('hub_verify_token', $request->query('hub.verify_token'));
        $challenge = $request->query('hub_challenge', $request->query('hub.challenge'));

        if ($mode === 'subscribe' && hash_equals((string) config('services.facebook.webhook_verify_token'), (string) $token)) {
            return response((string) $challenge, 200)->header('Content-Type', 'text/plain');
        }

        Log::warning('Facebook webhook verification failed.', [
            'mode' => $mode,
            'has_token' => filled($token),
        ]);

        return response('Forbidden', 403);
    }

    public function handle(Request $request, FacebookWebhookService $webhookService): JsonResponse
    {
        if (! $this->hasValidSignature($request)) {
            Log::warning('Facebook webhook event rejected because the signature was invalid.');

            return response()->json(['message' => 'Invalid signature'], 403);
        }

        Log::info('Facebook webhook event received.', [
            'object' => $request->input('object'),
            'entry_count' => count((array) $request->input('entry', [])),
            'payload' => $request->all(),
        ]);

        $webhookService->handle($request->all());

        return response()->json(['success' => true]);
    }

    private function hasValidSignature(Request $request): bool
    {
        $secret = (string) config('services.facebook.client_secret');
        $signature = (string) $request->header('X-Hub-Signature-256');

        if ($secret === '' || $signature === '') {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $signature);
    }
}
