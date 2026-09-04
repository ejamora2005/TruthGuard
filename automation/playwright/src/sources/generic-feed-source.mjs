import { absoluteUrl, autoScroll, toIsoTimestamp, uniqueStrings, waitForDynamicContent } from '../page-utils.mjs';

function normalizeText(value) {
    if (typeof value !== 'string') {
        return null;
    }

    const normalized = value.replace(/\s+/g, ' ').trim();

    return normalized !== '' ? normalized : null;
}

function normalizePost(record, pageUrl) {
    const imageUrls = uniqueStrings(
        (record.imageUrls ?? [])
            .map((entry) => absoluteUrl(entry, pageUrl))
            .filter(Boolean),
    );
    const videoUrls = uniqueStrings(
        (record.videoUrls ?? [])
            .map((entry) => absoluteUrl(entry, pageUrl))
            .filter(Boolean),
    );
    const sourceLinks = uniqueStrings(
        (record.sourceLinks ?? [])
            .map((entry) => absoluteUrl(entry, pageUrl))
            .filter(Boolean),
    );
    const postUrl = absoluteUrl(record.postUrl, pageUrl);
    const mediaUrls = uniqueStrings([...imageUrls, ...videoUrls]);

    return {
        externalId: normalizeText(record.externalId) ?? postUrl ?? mediaUrls[0] ?? null,
        postUrl,
        displayName: normalizeText(record.displayName),
        username: normalizeText(record.username),
        captionText: normalizeText(record.captionText),
        postedAt: toIsoTimestamp(record.postedAt) ?? normalizeText(record.postedAt),
        imageUrls,
        videoUrls,
        mediaUrls,
        sourceLinks,
        rawPayload: record.rawPayload ?? {},
    };
}

export async function scrapeGenericFeed(page, config, logger) {
    await waitForDynamicContent(page, config, logger);

    const scrollsPerformed = await autoScroll(page, config.scrollLimit, config.scrollPauseMs, logger);

    const rawPosts = await page.evaluate(({ limit, extraSelectors }) => {
        const postSelectors = [
            'article',
            '[role="article"]',
            '[data-testid*="post"]',
            '[data-e2e*="post"]',
            '[data-e2e*="feed-unit"]',
            '.post',
            '.feed-item',
            '.timeline-item',
            '.story',
        ];

        if (Array.isArray(extraSelectors?.postContainers)) {
            postSelectors.push(...extraSelectors.postContainers);
        }

        const toArray = (value) => Array.from(value ?? []);
        const uniqueElements = (elements) => [...new Set(elements)];
        const normalize = (value) => {
            if (typeof value !== 'string') {
                return '';
            }

            return value.replace(/\s+/g, ' ').trim();
        };
        const pickLongest = (values) => {
            const sorted = values
                .filter(Boolean)
                .sort((left, right) => right.length - left.length);

            return sorted[0] ?? '';
        };
        const collectUrls = (elements, attribute = 'href') => {
            return [...new Set(
                elements
                    .map((element) => element.getAttribute(attribute) ?? element.getAttribute('src') ?? '')
                    .map((value) => normalize(value))
                    .filter((value) => value !== '' && ! value.startsWith('javascript:') && ! value.startsWith('data:'))
            )];
        };
        const collectCandidateNodes = () => {
            const nodes = uniqueElements(postSelectors.flatMap((selector) => toArray(document.querySelectorAll(selector))));
            const filtered = nodes.filter((node) => normalize(node.innerText).length >= 20 || node.querySelector('img, video, a, time'));

            return filtered.length > 0 ? filtered : [document.body];
        };
        const extractLinks = (node) => collectUrls(toArray(node.querySelectorAll('a[href]')));
        const extractImages = (node) => collectUrls(toArray(node.querySelectorAll('img[src], source[srcset]')), 'src');
        const extractVideos = (node) => collectUrls(toArray(node.querySelectorAll('video[src], source[src], a[href$=".mp4"], a[href$=".webm"]')), 'src');
        const extractText = (node) => {
            const fragments = toArray(node.querySelectorAll('p, figcaption, [lang], .caption, .message, .content, .entry-content'))
                .map((element) => normalize(element.innerText))
                .filter((value) => value.length >= 15);

            return pickLongest(fragments) || normalize(node.innerText).slice(0, 4000);
        };
        const extractDisplayName = (node) => {
            const candidates = toArray(node.querySelectorAll('h1, h2, h3, h4, strong, b, [data-testid*="user"], [data-e2e*="user"]'))
                .map((element) => normalize(element.innerText))
                .filter((value) => value.length >= 3);

            return candidates[0] ?? '';
        };
        const extractUsername = (node) => {
            const textCandidate = toArray(node.querySelectorAll('a[href], .username, [data-testid*="user"], [data-e2e*="user"]'))
                .map((element) => normalize(element.innerText))
                .find((value) => value.startsWith('@'));

            if (textCandidate) {
                return textCandidate;
            }

            const linkCandidate = toArray(node.querySelectorAll('a[href]'))
                .map((element) => normalize(element.getAttribute('href') ?? ''))
                .find((value) => value.includes('/@') || /\/(profile|user|users|u|channel|c)\/[A-Za-z0-9._-]+/i.test(value));

            return linkCandidate ?? '';
        };
        const extractTimestamp = (node) => {
            const timeNode = node.querySelector('time');

            if (timeNode) {
                return normalize(timeNode.getAttribute('datetime') ?? timeNode.innerText);
            }

            const explicit = toArray(node.querySelectorAll('[data-time], [title]'))
                .map((element) => normalize(element.getAttribute('data-time') ?? element.getAttribute('title') ?? ''))
                .find(Boolean);

            return explicit ?? '';
        };
        const extractPermalink = (links) => {
            return links.find((value) => /\/(status|posts|videos|photo|photos|watch|reel|p\/|tv\/)/i.test(value))
                ?? links.find((value) => value.startsWith('/'))
                ?? links[0]
                ?? '';
        };

        return collectCandidateNodes()
            .slice(0, limit)
            .map((node, index) => {
                const sourceLinks = extractLinks(node);
                const imageUrls = extractImages(node);
                const videoUrls = extractVideos(node);

                return {
                    externalId: node.getAttribute('data-id') ?? node.id ?? `candidate-${index + 1}`,
                    postUrl: extractPermalink(sourceLinks),
                    displayName: extractDisplayName(node),
                    username: extractUsername(node),
                    captionText: extractText(node),
                    postedAt: extractTimestamp(node),
                    sourceLinks,
                    imageUrls,
                    videoUrls,
                    rawPayload: {
                        candidateIndex: index,
                        containerTag: node.tagName.toLowerCase(),
                        textLength: normalize(node.innerText).length,
                        sourceLinkCount: sourceLinks.length,
                        imageCount: imageUrls.length,
                        videoCount: videoUrls.length,
                    },
                };
            });
    }, {
        limit: config.postLimit,
        extraSelectors: config.extraSelectors,
    });

    const currentUrl = page.url();
    const posts = rawPosts
        .map((record) => normalizePost(record, currentUrl))
        .filter((record) => record.captionText || record.mediaUrls.length > 0 || record.postUrl);

    logger?.info('Extracted posts from target page', {
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
