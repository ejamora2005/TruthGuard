import path from 'node:path';
import dotenv from 'dotenv';

dotenv.config({
    path: path.resolve(process.cwd(), '.env'),
    quiet: true,
});

function parseBoolean(value, fallback) {
    if (typeof value === 'boolean') {
        return value;
    }

    if (typeof value === 'string') {
        const normalized = value.trim().toLowerCase();

        if (['1', 'true', 'yes', 'on'].includes(normalized)) {
            return true;
        }

        if (['0', 'false', 'no', 'off'].includes(normalized)) {
            return false;
        }
    }

    return fallback;
}

function parseInteger(value, fallback) {
    const numeric = Number.parseInt(String(value ?? ''), 10);

    return Number.isFinite(numeric) ? numeric : fallback;
}

function normalizeString(value) {
    if (typeof value !== 'string') {
        return null;
    }

    const normalized = value.trim();

    return normalized !== '' ? normalized : null;
}

function normalizeArray(value) {
    if (Array.isArray(value)) {
        return value
            .map((entry) => normalizeString(String(entry)))
            .filter(Boolean);
    }

    const normalized = normalizeString(String(value ?? ''));

    if (! normalized) {
        return [];
    }

    return normalized
        .split(',')
        .map((entry) => entry.trim())
        .filter(Boolean);
}

export function resolveRuntimeConfig(payload = {}) {
    const env = process.env;
    const targetUrls = normalizeArray(payload.targetUrls ?? env.PLAYWRIGHT_TARGET_URLS);
    const targetUrl = normalizeString(payload.targetUrl ?? '') ?? targetUrls[0] ?? null;

    return {
        projectRoot: process.cwd(),
        source: normalizeString(payload.source ?? '') ?? env.PLAYWRIGHT_DEFAULT_SOURCE ?? 'generic-feed',
        targetUrl,
        browserName: normalizeString(payload.browserName ?? '') ?? env.PLAYWRIGHT_BROWSER ?? 'chromium',
        headless: parseBoolean(payload.headless ?? env.PLAYWRIGHT_HEADLESS, true),
        timeoutMs: parseInteger(payload.timeoutMs ?? env.PLAYWRIGHT_TIMEOUT_MS, 45000),
        navigationTimeoutMs: parseInteger(payload.navigationTimeoutMs ?? env.PLAYWRIGHT_NAVIGATION_TIMEOUT_MS, 45000),
        initialWaitMs: parseInteger(payload.initialWaitMs ?? env.PLAYWRIGHT_INITIAL_WAIT_MS, 1500),
        scrollLimit: parseInteger(payload.scrollLimit ?? env.PLAYWRIGHT_SCROLL_LIMIT, 5),
        scrollPauseMs: parseInteger(payload.scrollPauseMs ?? env.PLAYWRIGHT_SCROLL_PAUSE_MS, 1200),
        postLimit: parseInteger(payload.postLimit ?? env.PLAYWRIGHT_POST_LIMIT, 10),
        retries: parseInteger(payload.retries ?? env.PLAYWRIGHT_RETRIES, 2),
        takeScreenshot: parseBoolean(payload.takeScreenshot ?? env.PLAYWRIGHT_TAKE_SCREENSHOT, false),
        screenshotDir: path.resolve(
            process.cwd(),
            normalizeString(payload.screenshotDir ?? '') ?? env.PLAYWRIGHT_SCREENSHOT_DIR ?? 'storage/app/private/playwright/screenshots',
        ),
        readySelectors: normalizeArray(payload.readySelectors ?? []),
        extraSelectors: typeof payload.extraSelectors === 'object' && payload.extraSelectors !== null
            ? payload.extraSelectors
            : {},
        userAgent: normalizeString(payload.userAgent ?? ''),
        storageStatePath: normalizeString(payload.storageStatePath ?? '') ?? normalizeString(env.PLAYWRIGHT_STORAGE_STATE_PATH ?? ''),
        logLevel: normalizeString(payload.logLevel ?? '') ?? env.PLAYWRIGHT_LOG_LEVEL ?? 'info',
    };
}
