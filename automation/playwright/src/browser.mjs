import fs from 'node:fs';
import path from 'node:path';
import { chromium, firefox, webkit } from 'playwright';

const BROWSERS = {
    chromium,
    firefox,
    webkit,
};

export async function createBrowserSession(config, logger) {
    const browserType = BROWSERS[config.browserName] ?? chromium;

    logger?.info('Launching Playwright browser', {
        browser: config.browserName,
        headless: config.headless,
    });

    const browser = await browserType.launch({
        headless: config.headless,
    });

    let storageState = undefined;

    if (config.storageStatePath) {
        const resolvedPath = path.resolve(process.cwd(), config.storageStatePath);

        if (fs.existsSync(resolvedPath)) {
            storageState = resolvedPath;
            logger?.info('Using Playwright storage state file', {
                path: resolvedPath,
            });
        } else {
            logger?.warn?.('Playwright storage state file not found', {
                path: resolvedPath,
            });
        }
    }

    const context = await browser.newContext({
        ignoreHTTPSErrors: true,
        userAgent: config.userAgent ?? undefined,
        viewport: { width: 1440, height: 1080 },
        storageState,
    });

    const page = await context.newPage();
    page.setDefaultTimeout(config.timeoutMs);
    page.setDefaultNavigationTimeout(config.navigationTimeoutMs);

    return { browser, context, page };
}

export async function closeBrowserSession(session, logger) {
    if (! session) {
        return;
    }

    await Promise.allSettled([
        session.page?.close(),
        session.context?.close(),
        session.browser?.close(),
    ]);

    logger?.debug('Closed Playwright browser session');
}
