<?php

namespace App\Services\Detections\Evidence;

/** Provider-reported usage. Cached input and reasoning are subsets, never added to totals. */
final class TokenUsage
{
    public static function measure(array $usage): array
    {
        $input = max(0, (int) ($usage['input_tokens'] ?? 0));
        $output = max(0, (int) ($usage['output_tokens'] ?? 0));

        return ['input_tokens' => $input, 'output_tokens' => $output,
            'total_tokens' => max(0, (int) ($usage['total_tokens'] ?? ($input + $output))),
            'cached_input_tokens' => min($input, max(0, (int) ($usage['cached_input_tokens'] ?? $usage['input_tokens_details']['cached_tokens'] ?? 0))),
            'reasoning_tokens' => min($output, max(0, (int) ($usage['reasoning_tokens'] ?? $usage['output_tokens_details']['reasoning_tokens'] ?? 0)))];
    }

    public static function sum(array $rows): array
    {
        $total = self::measure([]) + ['requests' => 0, 'retries' => 0];
        foreach ($rows as $row) {
            foreach (array_keys($total) as $key) {
                $total[$key] += max(0, (int) ($row[$key] ?? 0));
            }
        }

        return $total;
    }

    public static function attempts(array $attempts, array $fallback = []): array
    {
        $usage = $attempts === [] ? self::measure($fallback)
            : self::sum(array_map(fn ($attempt) => self::measure($attempt['usage'] ?? []), $attempts));

        return array_replace($usage, ['requests' => max(1, count($attempts)), 'retries' => max(0, count($attempts) - 1)]);
    }
}
