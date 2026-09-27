<?php

namespace App\Services\Notifications;

class NotificationUrl
{
    public static function safe(?string $url): string
    {
        // Only app routes generated for notifications; no redirects or external origins.
        return is_string($url) && preg_match('~^/(?:notifications|profile|claim-reviews|detections/[0-9]+/result|dashboard/fact-checks/[A-Za-z0-9_-]+)$~D', $url)
            ? $url : '/notifications';
    }
}
