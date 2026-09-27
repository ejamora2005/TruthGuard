<?php

namespace App\Services\Notifications;

use App\Models\PushSubscription;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class TruthGuardPushNotificationService
{
    public function send(PushSubscription $subscription, array $data): void
    {
        if (! config('firebase.enabled')) {
            return;
        }
        $project = config('firebase.web.projectId');
        if (! is_string($project) || ! preg_match('/^[a-z0-9-]+$/', $project)) {
            throw new RuntimeException('Firebase project ID is not configured.');
        }
        try {
            $response = Http::withToken($this->accessToken())->timeout(20)->connectTimeout(5)
                ->post("https://fcm.googleapis.com/v1/projects/{$project}/messages:send", [
                    'message' => [
                        'token' => $subscription->token,
                        'data' => array_map(fn ($value) => (string) $value, $data),
                        'webpush' => ['headers' => ['Urgency' => 'high', 'TTL' => '86400']],
                    ],
                ]);
        } catch (Throwable) {
            // Never attach HTTP request bodies, tokens or credential-bearing exceptions.
            throw new RuntimeException('FCM connection or authentication failed.');
        }
        $code = collect($response->json('error.details', []))
            ->firstWhere('@type', 'type.googleapis.com/google.firebase.fcm.v1.FcmError')['errorCode'] ?? null;
        if ($code === 'UNREGISTERED' || ($code === 'INVALID_ARGUMENT'
            && preg_match('/registration token.*(?:invalid|not a valid)|(?:invalid|not a valid).*registration token/i', (string) $response->json('error.message', '')))) {
            $subscription->delete();

            return;
        }
        if ($response->status() === 401) {
            Cache::forget($this->cacheKey());
        }
        if (! $response->successful()) {
            throw new RuntimeException('FCM delivery failed (HTTP '.$response->status().').');
        }
    }

    private function cacheKey(): string
    {
        return 'firebase:oauth:'.hash('sha256', (string) config('firebase.web.projectId').(string) config('firebase.credentials_base64').(string) config('firebase.credentials_path'));
    }

    protected function accessToken(): string
    {
        return Cache::remember($this->cacheKey(), 3000, function (): string {
            $encoded = config('firebase.credentials_base64');
            $path = config('firebase.credentials_path');
            $json = $encoded ? base64_decode($encoded, true) : ($path && is_readable($path) ? file_get_contents($path) : false);
            $credentials = json_decode($json ?: '{}', true);
            if (! isset($credentials['client_email'], $credentials['private_key'])
                || ($credentials['project_id'] ?? null) !== config('firebase.web.projectId')) {
                throw new RuntimeException('Firebase server credentials are missing or do not match the project.');
            }
            $encode = fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
            $now = time();
            $jwt = $encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])).'.'.$encode(json_encode([
                'iss' => $credentials['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ]));
            if (! openssl_sign($jwt, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException('Firebase credential signing failed.');
            }
            $response = Http::asForm()->timeout(15)->connectTimeout(5)->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt.'.'.$encode($signature),
            ]);
            if (! $response->successful() || ! is_string($response->json('access_token'))) {
                throw new RuntimeException('Firebase OAuth authentication failed.');
            }

            return $response->json('access_token');
        });
    }
}
