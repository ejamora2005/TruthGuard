<?php

return [
    'enabled' => (bool) env('FIREBASE_ENABLED', false),
    'web' => [
        'apiKey' => env('FIREBASE_API_KEY'),
        'authDomain' => env('FIREBASE_AUTH_DOMAIN'),
        'projectId' => env('FIREBASE_PROJECT_ID'),
        'storageBucket' => env('FIREBASE_STORAGE_BUCKET'),
        'messagingSenderId' => env('FIREBASE_MESSAGING_SENDER_ID'),
        'appId' => env('FIREBASE_APP_ID'),
    ],
    'vapid_key' => env('FIREBASE_VAPID_KEY'),
    // Server only. Dokploy can supply a base64 encoded service-account JSON secret.
    'credentials_base64' => env('FIREBASE_CREDENTIALS_BASE64'),
    'credentials_path' => env('FIREBASE_CREDENTIALS_PATH'),
];
