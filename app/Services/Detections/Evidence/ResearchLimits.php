<?php

namespace App\Services\Detections\Evidence;

/** Optional research caps; network and provider context constraints stay separate. */
final class ResearchLimits
{
    public static function unrestricted(): bool
    {
        return (bool) config('fact_check.unrestricted_research', false);
    }

    public static function ceiling(string $key, int $default): int
    {
        return self::unrestricted() ? PHP_INT_MAX : max(0, (int) config($key, $default));
    }

    public static function outputTokens(int $limit): array
    {
        return self::unrestricted() ? [] : ['max_output_tokens' => max(1, $limit)];
    }
}
