<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## Production deployment

TruthGuard includes a production Docker image and Compose services for the app, MySQL, queue worker, and scheduler. See [the deployment guide](docs/DEPLOYMENT.md) for environment setup, HTTPS, migrations, backups, and updates, and [the load-balancing preparation](docs/LOAD-BALANCING.md) for Dokploy scaling. Start from `.env.production.example`; do not use development credentials in production.

## Evidence-based fact checking (default)

New submissions use the version 2 evidence pipeline: original text extraction, atomic claims, bounded article discovery, validated claim/source relationships, and explicit abstention. Media and article-classifier scores remain separate from factual verdicts. See [implementation, live checks, setup, and rollback](docs/EVIDENCE-VERIFICATION.md) and [held-out evaluation instructions](automation/evaluation/README.md). Existing saved reports are preserved.

## API usage monitoring and saved-result reuse

Fact-check explanations reuse the OpenAI evidence review's validated explanations and identify the reviewed publisher, avoiding a second prose-writing request. Completed media-origin checks can use one additional text-only explanation request (`OPENAI_MEDIA_EXPLANATION_ENABLED=true`, `OPENAI_MEDIA_EXPLANATION_MAX_TOKENS=400`). It receives compact detector findings, never another copy of the image, and has no search tools or automatic retries. It cannot change the verdict, score or sources; unavailable narration leaves the original explanation intact. Successful eligible duplicate submissions reuse the saved explanation before inference. Old reports remain unchanged and do not generate explanations when opened.

The 400-token limit covers output for this narration request, not input tokens or the whole review. Claim extraction, evidence analysis and enabled research remain separate costs. The short narrator preserves the configured `OPENAI_MODEL`; it does not automatically switch models. Its token usage is included in the existing API ledger and report telemetry.

Routine image-text extraction, claim planning and web discovery inherit `OPENAI_MODEL` by default, so projects with access only to GPT-5.4 keep working. To use a cheaper model, explicitly set `OPENAI_EXTRACTION_MODEL`, `OPENAI_PLANNING_MODEL` and `OPENAI_WEB_SEARCH_MODEL` after confirming access for that project. Evidence review and explanations keep the configured main model. Routine models never decide the final factual verdict alone. A model-access error remains visible; there is no automatic retry on another model. Tight stage-specific limits still apply when all stages use GPT-5.4. Live access and per-report savings have not been tested with paid calls.

The planner has a separate `FACT_CHECK_PLAN_OUTPUT_TOKENS=1200` allowance; evidence review uses `FACT_CHECK_MODEL_OUTPUT_TOKENS=3000` and a `FACT_CHECK_MAX_MODEL_INPUT_BYTES=32768` input ceiling. Supported GPT-5.4/5.2 structured requests use reasoning effort `none`. Article extraction prefers the actual article over its surrounding page, and identical completed reviews within one submission are reused if a later search adds no new reviewable input. New or changed evidence still receives a fresh review, including earlier contrary sources.

Paid web discovery defaults to one request with one tool call, `OPENAI_WEB_SEARCH_CONTEXT_SIZE=low`, and `OPENAI_WEB_SEARCH_MAX_OUTPUT_TOKENS=600`. Other configured discovery providers and two-round current-status checks remain available. Lower limits can reduce coverage or leave a complex check unresolved; partial evidence is identified rather than converted into a confident verdict. Report token totals now include returned web-search model usage as well as extraction and evidence review. Search tool charges are separate from token counts, and these controls are not a fixed dollar cap per report. Opening a saved result makes no new explanation request.

The complete Dokploy environment template is `.env.production.example`. Replace its marked secret values and proxy CIDR on the server. Compose forwards billing, evidence, calibration, and reuse settings to the app, worker, and scheduler. Keep the existing deployed `APP_KEY` until a planned rotation, and update existing MySQL account passwords in MySQL when rotating database credentials.

Set `OPENAI_MONTHLY_DOLLAR_BUDGET=6` and a server-only `OPENAI_ADMIN_API_KEY` for cost alerts at 80% and 100%. `OPENAI_USAGE_PROJECT_ID` and `OPENAI_USAGE_API_KEY_ID` optionally scope billing; leaving both blank monitors the whole OpenAI organization. Admin **AI Usage** shows provider-reported request tokens from monitoring start, separately from OpenAI-reported monthly costs. Missing telemetry and unavailable/stale cost data are identified explicitly. The budget is an alert threshold, not a hard spending stop or prepaid-credit balance.

Deploy the new code, run `php artisan migrate --force`, and recreate app, worker, and scheduler. The singleton scheduler runs `truthguard:sync-openai-costs` every five minutes when the admin key is configured. For one billing-only check, run `php artisan truthguard:sync-openai-costs`. Admins need system notifications enabled; device push additionally requires Firebase configuration, device permission, and a queue worker. The default worker does not consume the `evidence` queue, so leave `FACT_CHECK_QUEUE_ENABLED=false` unless a dedicated evidence worker is configured.

Compatible, sufficiently fresh completed results can be reused before paid analysis; all reports predating the current `FACT_CHECK_RESULT_REUSE_GENERATION` are excluded. Changed content/questions/tasks, incompatible inference settings, explicit rechecks, failed analyses, and time-sensitive checks do not silently reuse a prior assessment. Cache hits retain the original assessment date and record zero new API tokens. Existing reports and uploads are preserved.

## Legacy canonical fact-check results (rollback path)

Run `php artisan migrate` when deploying the result engine. New submissions collect evidence before looking up a saved result. The canonical key includes the normalized claim, locale, source snapshot ID, and evidence cutoff. Opening a saved result preserves it unless evidence refresh is requested/configured. The authenticated result route also returns the exact result object with `Accept: application/json`.

`resources/prompts/fact-check-result.txt` defines the result-writing policy. `FactCheckResultSchema` defines the JSON contract, and `FactCheckResultService` checks citations and applies the UI rules. Results use TRUE, FALSE, MISLEADING, PARTLY_TRUE, UNVERIFIABLE, or OUTDATED. Local media/text risk scores are kept separately and cannot substantiate a factual verdict.

Evidence adapters must supply a retrieved record with `evidence_verified: true`, `evidence_text`, publisher `name`, a specific `title`, `url`, source `type`, and an actual `published_at` date (or null). Fact-check records also carry `claim_text` and the original `evidence_rating`. The approval flag is set by server-side adapters, never accepted from submission form input. Approved host/type pairs are configured in `config/fact_check.php`. Agency homepages, search routes/snippets, social posts, irrelevant records and records after the cutoff are excluded. News supplies context only. Existing Google/publisher feeds provide fact-check records; government/institution integrations must supply actual publications rather than directory links.

Internal callers may pass an immutable `source_snapshot_id` and `evidence_cutoff` to reuse a supplied snapshot. Otherwise the service hashes the approved records and records the retrieval cutoff. API failures, invalid output, unsupported citations, or insufficient evidence produce an explicit UNVERIFIABLE result. Tests use mocked API responses; they do not assess the live model's factual accuracy.

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
