import { scrapeArticleSearch } from './article-search-source.mjs';
import { scrapeGenericFeed } from './generic-feed-source.mjs';

const SOURCES = {
    'article-search': {
        key: 'article-search',
        label: 'Article search results',
        scrape: scrapeArticleSearch,
    },
    'generic-feed': {
        key: 'generic-feed',
        label: 'Generic social feed',
        scrape: scrapeGenericFeed,
    },
};

export function resolveSource(sourceKey) {
    return SOURCES[sourceKey] ?? SOURCES['generic-feed'];
}

export function listSources() {
    return Object.values(SOURCES).map((source) => ({
        key: source.key,
        label: source.label,
    }));
}
