import { createBrowserSession, closeBrowserSession } from './browser.mjs';
import { resolveRuntimeConfig } from './config.mjs';
import { createLogger } from './logger.mjs';
import { takePageScreenshot } from './page-utils.mjs';
import { withRetries } from './retry.mjs';
import { resolveSource } from './sources/index.mjs';

export async function runScrape(payload = {}) {
    const config = resolveRuntimeConfig(payload);
    const logger = createLogger(config.logLevel);

    if (! config.targetUrl) {
        throw new Error('A targetUrl is required. Pass one through stdin, --target-url, or PLAYWRIGHT_TARGET_URLS.');
    }

    const source = resolveSource(config.source);
    const startedAt = new Date().toISOString();

    const result = await withRetries(async ({ attempt }) => {
        let session = null;

        try {
            logger.info('Starting Playwright scrape run', {
                attempt,
                source: source.key,
                targetUrl: config.targetUrl,
            });

            session = await createBrowserSession(config, logger);

            const scrapeResult = await source.scrape(session.page, config, logger);
            let screenshotPath = null;

            if (config.takeScreenshot) {
                screenshotPath = await takePageScreenshot(session.page, config.screenshotDir, source.key, logger);
            }

            return {
                ...scrapeResult,
                screenshotPath,
            };
        } finally {
            await closeBrowserSession(session, logger);
        }
    }, {
        retries: config.retries,
        delayMs: 1500,
        logger,
        label: `scrape ${config.targetUrl}`,
    });

    const finishedAt = new Date().toISOString();
    const mediaItemsCollected = result.posts.reduce((total, post) => total + post.mediaUrls.length, 0);
    const sourceLinksCollected = result.posts.reduce((total, post) => total + post.sourceLinks.length, 0);

    return {
        ok: true,
        run: {
            source: source.key,
            sourceLabel: source.label,
            targetUrl: config.targetUrl,
            browserName: config.browserName,
            headless: config.headless,
            startedAt,
            finishedAt,
            screenshotPath: result.screenshotPath,
            summary: {
                postsCollected: result.posts.length,
                mediaItemsCollected,
                sourceLinksCollected,
                scrollsPerformed: result.meta?.scrollsPerformed ?? 0,
                finalUrl: result.meta?.finalUrl ?? config.targetUrl,
            },
        },
        posts: result.posts,
    };
}
