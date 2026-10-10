<?php

namespace App\Services\Notifications;

class PushConfiguration
{
    /** Return configuration names only; never return credential values. */
    public function problems(): array
    {
        $problems = [];
        if (! config('firebase.enabled')) {
            $problems[] = 'FIREBASE_ENABLED';
        }
        foreach (['apiKey', 'projectId', 'messagingSenderId', 'appId'] as $field) {
            if (! filled(config('firebase.web.'.$field))) {
                $problems[] = 'firebase.web.'.$field;
            }
        }
        $vapid = (string) config('firebase.vapid_key');
        $decoded = base64_decode(strtr($vapid, '-_', '+/'), true);
        if (! $decoded || strlen($decoded) !== 65 || ord($decoded[0]) !== 4) {
            $problems[] = 'FIREBASE_VAPID_KEY';
        }
        $encoded = config('firebase.credentials_base64');
        $path = config('firebase.credentials_path');
        $json = $encoded ? base64_decode($encoded, true) : ($path && is_readable($path) ? file_get_contents($path) : false);
        $credentials = json_decode($json ?: '{}', true);
        if (! is_array($credentials) || empty($credentials['client_email']) || empty($credentials['private_key'])
            || ($credentials['project_id'] ?? null) !== config('firebase.web.projectId')
            || ! @openssl_pkey_get_private($credentials['private_key'])) {
            $problems[] = 'Firebase service-account credentials (missing, invalid, or project mismatch)';
        }

        return $problems;
    }
}
