<?php

$mediaUploadMaxMb = max(1, (int) env('TRUTHGUARD_MEDIA_UPLOAD_MAX_MB', 20));
$avatarUploadMaxMb = max(1, (int) env('TRUTHGUARD_AVATAR_UPLOAD_MAX_MB', 5));

return [
    'claims' => [
        'max_characters' => max(1, (int) env('TRUTHGUARD_CLAIM_MAX_CHARACTERS', 4000)),
    ],
    'uploads' => [
        'media_max_mb' => $mediaUploadMaxMb,
        'media_max_kb' => $mediaUploadMaxMb * 1024,
        'media_max_bytes' => $mediaUploadMaxMb * 1024 * 1024,
        'avatar_max_mb' => $avatarUploadMaxMb,
        'avatar_max_kb' => $avatarUploadMaxMb * 1024,
        'avatar_max_bytes' => $avatarUploadMaxMb * 1024 * 1024,
    ],
];
