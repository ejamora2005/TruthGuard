<?php

namespace App\Services\Automation;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Process;

class PlaywrightRunner
{
    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function run(array $options): array
    {
        if (! config('playwright.enabled')) {
            throw new RuntimeException('Playwright automation is disabled in configuration.');
        }

        $scriptPath = base_path((string) config('playwright.script_path'));

        if (! is_file($scriptPath)) {
            throw new RuntimeException("Playwright CLI script was not found at {$scriptPath}.");
        }

        $payload = $this->buildPayload($options);
        $nodeBinary = (string) config('playwright.node_binary', 'node');
        $timeoutSeconds = $this->resolveProcessTimeout($payload);
        $logChannel = (string) config('playwright.log_channel', 'playwright');

        $process = new Process([$nodeBinary, $scriptPath], base_path());
        $process->setInput(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $process->setTimeout($timeoutSeconds);
        $process->run();

        $stderr = trim($process->getErrorOutput());

        if ($stderr !== '') {
            Log::channel($logChannel)->debug('Playwright CLI stderr output', [
                'output' => $stderr,
            ]);
        }

        if (! $process->isSuccessful()) {
            Log::channel($logChannel)->error('Playwright CLI process failed', [
                'exit_code' => $process->getExitCode(),
                'stderr' => $stderr,
                'stdout' => trim($process->getOutput()),
            ]);

            throw new RuntimeException($stderr !== '' ? $stderr : 'Playwright CLI exited with a non-zero status.');
        }

        $decoded = json_decode($process->getOutput(), true);

        if (! is_array($decoded) || ! ($decoded['ok'] ?? false)) {
            Log::channel($logChannel)->error('Playwright CLI returned an invalid payload', [
                'stdout' => trim($process->getOutput()),
            ]);

            throw new RuntimeException('Playwright CLI returned an invalid JSON payload.');
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function buildPayload(array $options): array
    {
        $screenshotDirectory = $options['screenshot_dir'] ?? storage_path('app/private/playwright/screenshots');

        return [
            'targetUrl' => $options['target_url'] ?? (config('playwright.target_urls')[0] ?? null),
            'source' => $options['source_key'] ?? config('playwright.default_source'),
            'postLimit' => (int) ($options['post_limit'] ?? config('playwright.post_limit')),
            'takeScreenshot' => (bool) ($options['take_screenshot'] ?? config('playwright.take_screenshot')),
            'readySelectors' => array_values($options['ready_selectors'] ?? []),
            'extraSelectors' => $options['extra_selectors'] ?? [],
            'browserName' => $options['browser_name'] ?? config('playwright.browser'),
            'headless' => (bool) ($options['headless'] ?? config('playwright.headless')),
            'timeoutMs' => (int) config('playwright.timeout_ms'),
            'navigationTimeoutMs' => (int) config('playwright.navigation_timeout_ms'),
            'initialWaitMs' => (int) config('playwright.initial_wait_ms'),
            'scrollLimit' => (int) ($options['scroll_limit'] ?? config('playwright.scroll_limit')),
            'scrollPauseMs' => (int) ($options['scroll_pause_ms'] ?? config('playwright.scroll_pause_ms')),
            'retries' => (int) ($options['retries'] ?? config('playwright.retries')),
            'screenshotDir' => is_string($screenshotDirectory) ? $screenshotDirectory : storage_path('app/private/playwright/screenshots'),
            'logLevel' => env('PLAYWRIGHT_LOG_LEVEL', env('LOG_LEVEL', 'debug')),
            'userAgent' => $options['user_agent'] ?? config('playwright.user_agent'),
            'storageStatePath' => $options['storage_state_path'] ?? config('playwright.storage_state_path'),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveProcessTimeout(array $payload): int
    {
        $timeoutMs = (int) ($payload['timeoutMs'] ?? 45000);
        $scrollLimit = (int) ($payload['scrollLimit'] ?? 0);
        $scrollPauseMs = (int) ($payload['scrollPauseMs'] ?? 0);

        return max(30, (int) ceil(($timeoutMs + ($scrollLimit * $scrollPauseMs) + 15000) / 1000));
    }
}
