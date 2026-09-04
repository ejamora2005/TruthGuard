# TruthGuard Playwright Automation

This module adds a reusable Playwright-based scraping layer to TruthGuard.

## What it does

- opens a target page in a real browser
- waits for dynamic content
- scrolls for additional feed content
- extracts post text, media links, timestamps, usernames, and source links
- optionally saves a screenshot
- returns structured JSON that Laravel can store and analyze

## Main files

- `automation/playwright/cli.mjs`
- `automation/playwright/src/config.mjs`
- `automation/playwright/src/runner.mjs`
- `automation/playwright/src/sources/generic-feed-source.mjs`
- `automation/playwright/fixtures/sample-feed.html`

## Quick local test

1. Install browsers:
   `npm run automation:install-browsers`
2. Build a local fixture URL:
   `file:///C:/laragon/www/TruthGuard/automation/playwright/fixtures/sample-feed.html`
3. Run the scraper:
   `node automation/playwright/cli.mjs --target-url=file:///C:/laragon/www/TruthGuard/automation/playwright/fixtures/sample-feed.html --take-screenshot=true`

The script prints a JSON payload to stdout so Laravel, n8n, or another process can consume it.
