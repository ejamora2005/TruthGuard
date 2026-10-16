import assert from 'node:assert/strict';
import {existsSync, mkdirSync, readFileSync, statSync, writeFileSync} from 'node:fs';
import {resolve, sep} from 'node:path';
import {chromium} from 'playwright';

// Run after render-create-workspace.php and npm run build. All responses come
// from the local fixture/assets; a browser request can never reach a provider.
const fixture = readFileSync('storage/tmp-create-browser/create.html', 'utf8')
    .replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '');
const directory = 'storage/tmp-create-browser/screenshots';
mkdirSync(directory, {recursive: true});
const browser = await chromium.launch({channel: 'chrome', headless: true});
const cases = [
    {name: 'zoom125-short', width: 1536, height: 695, scale: 1.25},
    {name: 'zoom125-short-minimized', width: 1536, height: 695, scale: 1.25, minimized: true, thumbnail: true},
    {name: 'zoom125-long-feed', width: 1536, height: 695, scale: 1.25, longFeed: true},
    {name: 'zoom125-tall', width: 1536, height: 864, scale: 1.25},
    {name: 'desktop-short', width: 1920, height: 870, scale: 1},
    {name: 'desktop-tall', width: 1920, height: 1080, scale: 1},
    {name: 'desktop-narrow', width: 1366, height: 768, scale: 1},
    {name: 'mobile', width: 390, height: 844, scale: 1},
];
const results = [];

try {
    for (const view of cases) {
        const context = await browser.newContext({
            viewport: {width: view.width, height: view.height},
            deviceScaleFactor: view.scale,
            reducedMotion: 'reduce',
            serviceWorkers: 'block',
        });
        const page = await context.newPage();
        const root = resolve('public');
        const unexpectedRequests = [];
        await page.route('**/*', async route => {
            const url = new URL(route.request().url());
            if (url.pathname === '/fixture/create') {
                return route.fulfill({contentType: 'text/html', body: fixture});
            }
            const local = resolve(root, `.${url.pathname}`);
            if (url.origin === 'http://127.0.0.1:8000'
                && local.startsWith(root + sep)
                && existsSync(local)
                && statSync(local).isFile()) {
                const contentType = local.endsWith('.css') ? 'text/css'
                    : local.endsWith('.svg') ? 'image/svg+xml'
                    : local.endsWith('.png') ? 'image/png'
                    : local.endsWith('.jpg') ? 'image/jpeg'
                    : 'application/octet-stream';
                return route.fulfill({contentType, body: readFileSync(local)});
            }
            if (!['fonts.googleapis.com', 'fonts.gstatic.com'].includes(url.hostname)) {
                unexpectedRequests.push(`${route.request().method()} ${url.origin}${url.pathname}`);
            }
            return route.fulfill({status: 204});
        });
        await page.goto('http://127.0.0.1:8000/fixture/create');
        await page.locator('.truthguard-workspace-hero').waitFor();
        await page.evaluate(({minimized, thumbnail, longFeed}) => {
            document.documentElement.classList.remove('tg-loading');
            document.body.classList.remove('tg-loading');
            // The real layout's Alpine default is an expanded 290px desktop
            // sidebar. Apply that state without booting scripts in this fixture.
            if (innerWidth >= 1024) {
                const sidebar = document.querySelector('.truthguard-sidebar');
                const content = document.querySelector('.truthguard-user-content-column');
                sidebar.removeAttribute('x-cloak');
                sidebar.querySelectorAll('[x-cloak]').forEach(element => element.removeAttribute('x-cloak'));
                sidebar.style.transition = 'none';
                sidebar.style.width = '290px';
                sidebar.style.transform = 'translateX(0)';
                content.style.transition = 'none';
                content.style.marginLeft = '290px';
                content.style.setProperty('--tg-user-sidebar-width', '290px');
            }
            if (minimized) {
                const restore = document.querySelector('.truthguard-progress-restore');
                restore.removeAttribute('x-cloak');
                restore.style.display = 'flex';
                document.querySelector('.truthguard-guided-prompts').style.setProperty('display', 'none', 'important');
            }
            if (thumbnail) {
                const anchor = document.querySelector('.truthguard-preview-anchor');
                anchor.removeAttribute('x-cloak');
                anchor.style.display = 'block';
                const image = document.createElement('img');
                image.src = '/images/truthguard-logo.png';
                image.alt = 'Synthetic selected upload';
                image.className = 'h-full w-full object-cover';
                anchor.querySelector('.truthguard-preview-trigger').append(image);
                document.querySelector('.truthguard-attachment-trigger').style.display = 'none';
            }
            if (longFeed) {
                document.querySelectorAll('.truthguard-recent-claim').forEach(element => {
                    element.textContent = 'FACT CHECK: This synthetic public review intentionally uses a lengthy headline that wraps across several lines';
                });
                document.querySelectorAll('.truthguard-recent-summary').forEach(element => {
                    element.textContent = 'A synthetic review with added context about the claimed event, its date, source, and outcome, included solely to test realistic card wrapping.';
                });
            }
        }, {minimized: Boolean(view.minimized), thumbnail: Boolean(view.thumbnail), longFeed: Boolean(view.longFeed)});
        if (process.env.TG_LAYOUT_PROBE_CSS) {
            await page.addStyleTag({content: process.env.TG_LAYOUT_PROBE_CSS});
        }
        const metrics = await page.evaluate(() => {
            const box = (selector) => {
                const element = document.querySelector(selector);
                if (!element) return null;
                const rect = element.getBoundingClientRect();
                const css = getComputedStyle(element);
                return {
                    x: +rect.x.toFixed(1), y: +rect.y.toFixed(1),
                    width: +rect.width.toFixed(1), height: +rect.height.toFixed(1),
                    right: +rect.right.toFixed(1), bottom: +rect.bottom.toFixed(1),
                    visible: css.display !== 'none' && css.visibility !== 'hidden',
                    overflow: css.overflow, position: css.position,
                };
            };
            const hero = box('.truthguard-workspace-hero');
            const text = box('.truthguard-workspace-intro > p');
            const orb = box('.truthguard-workspace-orb');
            const recent = box('.truthguard-recent-section');
            const form = box('#truthguard-composer');
            const stage = document.querySelector('.truthguard-detection-stage');
            const heroStyle = getComputedStyle(document.querySelector('.truthguard-workspace-hero'));
            const titleStyle = getComputedStyle(document.querySelector('.truthguard-workspace-title'));
            const orbStyle = getComputedStyle(document.querySelector('.truthguard-workspace-orb'));
            const coreStyle = getComputedStyle(document.querySelector('.truthguard-workspace-orb-core'));
            const imageStyle = getComputedStyle(document.querySelector('.truthguard-workspace-orb img'));
            return {
                viewport: {
                    width: innerWidth, height: innerHeight,
                    devicePixelRatio,
                    rootFontSize: getComputedStyle(document.documentElement).fontSize,
                    maxHeight700: matchMedia('(max-height: 700px)').matches,
                    maxHeight820: matchMedia('(max-height: 820px)').matches,
                    maxHeight740: matchMedia('(max-height: 740px)').matches,
                },
                styles: {
                    heroPaddingTop: heroStyle.paddingTop,
                    heroPaddingBottom: heroStyle.paddingBottom,
                    heroMinHeight: heroStyle.minHeight,
                    titleFontSize: titleStyle.fontSize,
                    titleLineHeight: titleStyle.lineHeight,
                    titleMarginTop: titleStyle.marginTop,
                    orbWidth: orbStyle.width,
                    orbHeight: orbStyle.height,
                    coreInset: coreStyle.inset,
                    imageWidth: imageStyle.width,
                    imageHeight: imageStyle.height,
                    imageObjectFit: imageStyle.objectFit,
                    stageJustifyContent: getComputedStyle(stage).justifyContent,
                },
                documentOverflowX: document.documentElement.scrollWidth - innerWidth,
                stageOverflowX: stage.scrollWidth - stage.clientWidth,
                stageScrollHeight: stage.scrollHeight,
                stageClientHeight: stage.clientHeight,
                stageScrollTop: stage.scrollTop,
                stageScrollbarDisplay: getComputedStyle(stage, '::-webkit-scrollbar').display,
                stageScrollbarWidth: getComputedStyle(stage, '::-webkit-scrollbar').width,
                sidebar: box('.truthguard-sidebar'),
                topbar: box('.truthguard-topbar'),
                stage: box('.truthguard-detection-stage'),
                hero,
                pill: box('.truthguard-agent-pill'),
                title: box('.truthguard-workspace-title'),
                text,
                capabilities: box('.truthguard-desktop-capabilities'),
                orb,
                orbCore: box('.truthguard-workspace-orb-core'),
                orbImage: box('.truthguard-workspace-orb img'),
                recent,
                recentGrid: box('.truthguard-recent-grid'),
                summaryHeights: [...document.querySelectorAll('.truthguard-recent-summary')].map(element => +element.getBoundingClientRect().height.toFixed(1)),
                form,
                composer: box('.truthguard-detection-composer'),
                toolbar: box('.truthguard-composer-toolbar'),
                restore: box('.truthguard-progress-restore'),
                prompts: box('.truthguard-guided-prompts'),
                composerField: box('.truthguard-composer-field'),
                textarea: box('.truthguard-detection-textarea'),
                composerFooter: box('.truthguard-composer-footer'),
                disclaimer: box('.truthguard-ai-disclaimer-copy'),
                textBottomClearance: hero && text ? +(hero.bottom - text.bottom).toFixed(1) : null,
                orbBottomClearance: hero && orb ? +(hero.bottom - orb.bottom).toFixed(1) : null,
                gapRecentToForm: recent && form ? +(form.y - recent.bottom).toFixed(1) : null,
            };
        });
        assert.deepEqual(unexpectedRequests, [], `${view.name}: unexpected browser requests`);
        assert.ok(metrics.hero && metrics.recent && metrics.form, `${view.name}: create-page landmarks render`);
        assert.ok(metrics.documentOverflowX <= 1, `${view.name}: horizontal page overflow`);
        assert.ok(metrics.stageOverflowX <= 1, `${view.name}: horizontal stage overflow`);
        if (['zoom125-short', 'zoom125-short-minimized', 'zoom125-long-feed'].includes(view.name)) {
            assert.ok(metrics.stageScrollHeight <= metrics.stageClientHeight + 1, `${view.name}: stage requires initial vertical scroll`);
            assert.equal(metrics.stageScrollTop, 0, `${view.name}: stage starts scrolled below the banner`);
            assert.ok(metrics.form.bottom <= metrics.stage.bottom + 1, `${view.name}: complete composer form is initially cut off by ${(metrics.form.bottom - metrics.stage.bottom).toFixed(1)}px (stage scroll ${metrics.stageScrollHeight - metrics.stageClientHeight}px)`);
            assert.ok(metrics.hero.y >= metrics.stage.y - 1, `${view.name}: hero is clipped at the top`);
        }
        if (view.thumbnail) {
            assert.ok(await page.locator('.truthguard-preview-anchor').isVisible(), `${view.name}: selected upload thumbnail is hidden`);
        }
        if (['zoom125-short', 'zoom125-tall', 'zoom125-long-feed'].includes(view.name)) {
            const visibleContent = await page.evaluate(() => {
                const isVisible = (element) => {
                    if (!element) return false;
                    const rect = element.getBoundingClientRect();
                    const style = getComputedStyle(element);
                    return style.display !== 'none' && style.visibility !== 'hidden'
                        && rect.width > 0 && rect.height > 0;
                };
                const pills = [...document.querySelectorAll('.truthguard-desktop-capabilities .truthguard-capability-pill')];
                const cards = [...document.querySelectorAll('.truthguard-recent-card')];
                return {
                    capabilities: ['Image', 'Video', 'Link'].every(label => pills.some(pill => pill.textContent.trim() === label && isVisible(pill))),
                    subtitle: isVisible(document.querySelector('.truthguard-recent-subtitle')),
                    summaries: cards.length === 3 && cards.every(card => isVisible(card.querySelector('.truthguard-recent-summary'))),
                    metadata: cards.length === 3 && cards.every(card => isVisible(card.querySelector('.truthguard-recent-meta'))),
                };
            });
            for (const [part, visible] of Object.entries(visibleContent)) {
                assert.ok(visible, `${view.name}: ${part} should remain visible`);
            }
        }
        assert.ok(metrics.textBottomClearance >= -1, `${view.name}: hero clips its explanatory text`);
        if (metrics.orb.visible) {
            assert.ok(metrics.orbBottomClearance >= -1, `${view.name}: orb extends below hero`);
            assert.ok(metrics.orb.y >= metrics.hero.y - 1, `${view.name}: orb extends above hero`);
            assert.ok(metrics.orb.right <= metrics.hero.right + 1, `${view.name}: orb extends beyond hero right edge`);
            assert.ok(metrics.orbImage.bottom <= metrics.hero.bottom + 1, `${view.name}: logo image extends below hero`);
            const orbCenterX = (metrics.orb.x + metrics.orb.right) / 2;
            const orbCenterY = (metrics.orb.y + metrics.orb.bottom) / 2;
            const logoCenterX = (metrics.orbImage.x + metrics.orbImage.right) / 2;
            const logoCenterY = (metrics.orbImage.y + metrics.orbImage.bottom) / 2;
            assert.ok(Math.abs(logoCenterX - orbCenterX) <= 1, `${view.name}: logo is horizontally off-center in the orb`);
            assert.ok(Math.abs(logoCenterY - orbCenterY) <= 1, `${view.name}: logo is vertically off-center in the orb`);
        }
        assert.ok(metrics.recent.y >= metrics.hero.bottom - 1, `${view.name}: recent cards overlap hero`);
        assert.ok(metrics.form.y >= metrics.recent.bottom - 1, `${view.name}: form overlaps recent cards`);
        if (view.longFeed) {
            assert.ok(metrics.summaryHeights.every(height => height >= 31 && height <= 33), `${view.name}: long summaries should remain visible in two lines`);
        }
        await page.screenshot({path: `${directory}/${view.name}.png`});
        if (view.width >= 1024) {
            await page.locator('.truthguard-detection-composer').scrollIntoViewIfNeeded();
            const reachedComposer = await page.evaluate(() => {
                const stage = document.querySelector('.truthguard-detection-stage').getBoundingClientRect();
                const composer = document.querySelector('.truthguard-detection-composer').getBoundingClientRect();
                return {
                    visible: composer.top >= stage.top - 1 && composer.bottom <= stage.bottom + 1,
                    stageScrollTop: document.querySelector('.truthguard-detection-stage').scrollTop,
                    composerTop: +composer.top.toFixed(1),
                    composerBottom: +composer.bottom.toFixed(1),
                    stageBottom: +stage.bottom.toFixed(1),
                };
            });
            assert.ok(reachedComposer.visible, `${view.name}: composer cannot be reached in stage scroller`);
            metrics.composerReached = reachedComposer;
            if (['zoom125-short-minimized', 'zoom125-long-feed'].includes(view.name)) {
                await page.screenshot({path: `${directory}/${view.name}-composer-reached.png`});
            }
        }
        results.push({name: view.name, scale: view.scale, ...metrics});
        console.log(`${view.name}: hero ${metrics.hero.height}px, text clearance ${metrics.textBottomClearance}px, orb ${metrics.orb.width}x${metrics.orb.height}px, orb clearance ${metrics.orbBottomClearance}px, recent→form ${metrics.gapRecentToForm}px, stage scroll ${metrics.stageScrollHeight - metrics.stageClientHeight}px`);
        await context.close();
    }
} finally {
    await browser.close();
    writeFileSync('storage/tmp-create-browser/layout-metrics.json', JSON.stringify(results, null, 2));
}
