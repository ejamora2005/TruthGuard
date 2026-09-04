import fs from 'node:fs/promises';
import path from 'node:path';

export async function waitForDynamicContent(page, config, logger) {
    await page.goto(config.targetUrl, {
        waitUntil: 'domcontentloaded',
        timeout: config.navigationTimeoutMs,
    });

    try {
        await page.waitForLoadState('networkidle', {
            timeout: Math.min(config.navigationTimeoutMs, 10000),
        });
    } catch (error) {
        logger?.debug('Network idle wait timed out, continuing with available DOM', {
            error: error instanceof Error ? error.message : String(error),
        });
    }

    for (const selector of config.readySelectors) {
        try {
            await page.waitForSelector(selector, {
                timeout: config.initialWaitMs * 2,
            });

            logger?.debug('Ready selector matched', { selector });
            break;
        } catch (error) {
            logger?.debug('Ready selector not found yet', {
                selector,
                error: error instanceof Error ? error.message : String(error),
            });
        }
    }

    if (config.initialWaitMs > 0) {
        await page.waitForTimeout(config.initialWaitMs);
    }
}

export async function autoScroll(page, maxScrolls, pauseMs, logger) {
    let previousHeight = 0;
    let performed = 0;

    for (let index = 0; index < maxScrolls; index += 1) {
        const currentHeight = await page.evaluate(() => document.body.scrollHeight);

        if (index > 0 && currentHeight <= previousHeight) {
            break;
        }

        previousHeight = currentHeight;

        await page.evaluate(() => {
            window.scrollTo(0, document.body.scrollHeight);
        });

        performed += 1;
        await page.waitForTimeout(pauseMs);
    }

    logger?.debug('Completed auto-scroll sequence', { performed });

    return performed;
}

function slugifyLabel(label) {
    return label
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 60) || 'page';
}

export async function takePageScreenshot(page, screenshotDir, label, logger) {
    await fs.mkdir(screenshotDir, { recursive: true });

    const fileName = `${Date.now()}-${slugifyLabel(label)}.png`;
    const absolutePath = path.join(screenshotDir, fileName);

    await page.screenshot({
        path: absolutePath,
        fullPage: true,
    });

    const relativePath = path.relative(process.cwd(), absolutePath).replace(/\\/g, '/');

    logger?.info('Saved Playwright screenshot', { path: relativePath });

    return relativePath;
}

export function absoluteUrl(value, baseUrl) {
    if (typeof value !== 'string' || value.trim() === '') {
        return null;
    }

    try {
        return new URL(value, baseUrl).href;
    } catch {
        return null;
    }
}

export function uniqueStrings(values) {
    return [...new Set(values.filter(Boolean))];
}

export function toIsoTimestamp(value) {
    if (typeof value !== 'string' || value.trim() === '') {
        return null;
    }

    const candidate = new Date(value);

    if (Number.isNaN(candidate.getTime())) {
        return null;
    }

    return candidate.toISOString();
}
