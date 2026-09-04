import { absoluteUrl, autoScroll, toIsoTimestamp, uniqueStrings, waitForDynamicContent } from '../page-utils.mjs';

function normalizeText(value) {
    if (typeof value !== 'string') {
        return null;
    }

    const normalized = value.replace(/\s+/g, ' ').trim();

    return normalized !== '' ? normalized : null;
}

function normalizeRecord(record, pageUrl) {
    const imageUrls = uniqueStrings(
        (record.imageUrls ?? [])
            .map((entry) => absoluteUrl(entry, pageUrl))
            .filter(Boolean),
    );
    const sourceLinks = uniqueStrings(
        (record.sourceLinks ?? [])
            .map((entry) => absoluteUrl(entry, pageUrl))
            .filter(Boolean),
    );
    const postUrl = absoluteUrl(record.postUrl, pageUrl) ?? sourceLinks[0] ?? null;

    return {
        externalId: normalizeText(record.externalId) ?? postUrl ?? null,
        postUrl,
        displayName: normalizeText(record.displayName),
        username: normalizeText(record.username),
        captionText: normalizeText(record.captionText),
        postedAt: toIsoTimestamp(record.postedAt) ?? normalizeText(record.postedAt),
        imageUrls,
        videoUrls: [],
        mediaUrls: imageUrls,
        sourceLinks,
        rawPayload: record.rawPayload ?? {},
    };
}

async function dismissConsentPrompts(page, extraSelectors, logger) {
    const buttonSelectors = [
        ...(Array.isArray(extraSelectors?.dismissButtons) ? extraSelectors.dismissButtons : []),
        '#onetrust-reject-all-handler',
        '#onetrust-accept-btn-handler',
        'button[aria-label="Close"]',
        'button[title="Close"]',
        'button:has-text("Reject all")',
        'button:has-text("Reject All")',
        'button:has-text("Reject")',
        'button:has-text("Accept all")',
        'button:has-text("Accept All")',
        'button:has-text("Accept")',
        'button:has-text("I agree")',
        'button:has-text("Close")',
        'button:has-text("Continue")',
    ];

    for (const selector of buttonSelectors) {
        try {
            const locator = page.locator(selector).first();

            if (await locator.isVisible({ timeout: 800 })) {
                await locator.click({ timeout: 1200 });
                logger?.debug('Dismissed page prompt before scraping article search results', { selector });
                await page.waitForTimeout(350);
                return;
            }
        } catch {
            // Intentionally continue trying other selectors.
        }
    }
}

export async function scrapeArticleSearch(page, config, logger) {
    await waitForDynamicContent(page, config, logger);
    await dismissConsentPrompts(page, config.extraSelectors, logger);

    const scrollsPerformed = await autoScroll(page, Math.min(config.scrollLimit, 2), config.scrollPauseMs, logger);

    const rawPosts = await page.evaluate(({ limit, extraSelectors }) => {
        const articleSelectors = [
            'article',
            '.gsc-webResult.gsc-result',
            '.PageList-items-item',
            '.search-result',
            '.search-result-item',
            '.search-results-item',
            '.c-search-results__item',
            '.archive-post',
            '.post',
            '.story-card',
            '.tease-card',
            '.result',
        ];

        if (Array.isArray(extraSelectors?.articleContainers)) {
            articleSelectors.push(...extraSelectors.articleContainers);
        }

        const excludeSelectors = Array.isArray(extraSelectors?.excludeSelectors)
            ? extraSelectors.excludeSelectors
            : [];

        const siteLabel = typeof extraSelectors?.siteLabel === 'string'
            ? extraSelectors.siteLabel
            : '';

        const toArray = (value) => Array.from(value ?? []);
        const uniqueElements = (elements) => [...new Set(elements)];
        const normalize = (value) => {
            if (typeof value !== 'string') {
                return '';
            }

            return value.replace(/\s+/g, ' ').trim();
        };
        const collectUrls = (elements, attribute = 'href') => {
            return [...new Set(
                elements
                    .map((element) => element.getAttribute(attribute) ?? element.getAttribute('src') ?? '')
                    .map((value) => normalize(value))
                    .filter((value) => value !== '' && !value.startsWith('javascript:') && !value.startsWith('data:'))
            )];
        };
        const isExcluded = (node) => {
            return excludeSelectors.some((selector) => {
                try {
                    return node.matches(selector) || node.closest(selector);
                } catch {
                    return false;
                }
            });
        };
        const headlineFromNode = (node) => {
            const candidates = toArray(node.querySelectorAll('h1, h2, h3, h4, a[href], [data-key*="headline"], [class*="headline"], [class*="title"]'))
                .map((element) => normalize(element.textContent ?? element.innerText ?? ''))
                .filter((value) => value.length >= 8)
                .filter((value) => value.length <= 240)
                .sort((left, right) => left.length - right.length);

            return candidates[0] ?? '';
        };
        const summaryFromNode = (node, headline) => {
            const candidates = toArray(node.querySelectorAll('p, .description, .summary, .dek, .excerpt, .entry-content, .content'))
                .map((element) => normalize(element.textContent ?? element.innerText ?? ''))
                .filter((value) => value.length >= 20)
                .filter((value) => value !== headline)
                .sort((left, right) => right.length - left.length);

            return candidates[0] ?? '';
        };
        const postedAtFromNode = (node) => {
            const timeNode = node.querySelector('time');

            if (timeNode) {
                return normalize(timeNode.getAttribute('datetime') ?? timeNode.textContent ?? '');
            }

            const explicitDate = toArray(node.querySelectorAll('[datetime], [data-date], .date, .timestamp, .entry-meta'))
                .map((element) => normalize(
                    element.getAttribute('datetime')
                    ?? element.getAttribute('data-date')
                    ?? element.textContent
                    ?? element.innerText
                    ?? ''
                ))
                .find(Boolean);

            return explicitDate ?? '';
        };
        const sourceLinksFromNode = (node) => {
            return collectUrls(toArray(node.querySelectorAll('a[href]')));
        };
        const pickPrimaryUrl = (links) => {
            const preferred = links.find((value) => {
                return !/\/(search|tag|topic|topics|category|categories|author|authors)(\/|$|\?)/i.test(value);
            });

            return preferred ?? links[0] ?? '';
        };
        const imageUrlsFromNode = (node) => collectUrls(toArray(node.querySelectorAll('img[src], source[srcset]')), 'src');
        const candidateNodes = uniqueElements(
            articleSelectors.flatMap((selector) => {
                try {
                    return toArray(document.querySelectorAll(selector));
                } catch {
                    return [];
                }
            }),
        )
            .filter((node) => !isExcluded(node))
            .filter((node) => {
                const headline = headlineFromNode(node);
                const summary = summaryFromNode(node, headline);
                const links = sourceLinksFromNode(node);
                const images = imageUrlsFromNode(node);
                const looksArticleLike = headline.length >= 25 || summary !== '' || images.length > 0;

                return headline !== '' && looksArticleLike && links.length > 0;
            });

        return candidateNodes
            .slice(0, limit)
            .map((node, index) => {
                const headline = headlineFromNode(node);
                const summary = summaryFromNode(node, headline);
                const sourceLinks = sourceLinksFromNode(node);
                const imageUrls = imageUrlsFromNode(node);

                return {
                    externalId: node.getAttribute('data-id') ?? node.id ?? `search-result-${index + 1}`,
                    postUrl: pickPrimaryUrl(sourceLinks),
                    displayName: siteLabel,
                    username: null,
                    captionText: summary || headline,
                    postedAt: postedAtFromNode(node),
                    sourceLinks,
                    imageUrls,
                    rawPayload: {
                        candidateIndex: index,
                        containerTag: node.tagName.toLowerCase(),
                        headline,
                        summary,
                        textLength: normalize(node.textContent ?? node.innerText ?? '').length,
                        sourceLinkCount: sourceLinks.length,
                        imageCount: imageUrls.length,
                    },
                };
            });
    }, {
        limit: config.postLimit,
        extraSelectors: config.extraSelectors,
    });

    const currentUrl = page.url();
    const posts = rawPosts
        .map((record) => normalizeRecord(record, currentUrl))
        .filter((record) => record.postUrl || record.captionText);

    logger?.info('Extracted article search results from target page', {
        count: posts.length,
        source: config.source,
    });

    return {
        posts,
        meta: {
            finalUrl: currentUrl,
            scrollsPerformed,
        },
    };
}
