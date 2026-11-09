<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## Local development

With PHP 8.3 or newer selected in your terminal, run `php artisan migrate` after updating the code, then `composer run dev` to start the web server, frontend, general queue worker, and dedicated fact-check worker together. Pending database changes can prevent a completed analysis from being saved. When `FACT_CHECK_QUEUE_ENABLED=true`, fact checks use the separate `evidence` connection and queue; the ordinary queue worker does not process them.

If you start only `php artisan serve`, run `composer run dev:evidence` in a second terminal and keep it running while testing queued fact checks. Without that worker, submissions remain waiting until they time out. `FACT_CHECK_QUEUE_ENABLED=false` instead runs checks within the web request. Reports that were already stopped or timed out must be explicitly retried or submitted again; starting a worker does not revive them.

## Production deployment

TruthGuard includes a production Docker image and Compose services for the app, MySQL, queue worker, and scheduler. See [the deployment guide](docs/DEPLOYMENT.md) for environment setup, HTTPS, migrations, backups, and updates, and [the load-balancing preparation](docs/LOAD-BALANCING.md) for Dokploy scaling. Start from `.env.production.example`; do not use development credentials in production.

## Evidence-based fact checking (default)

The [research expansion update](docs/RESEARCH-EXPANSION-2026-11-09.md) sends unresolved claims to targeted discovery before citation expansion, skips pagination that adds no evidence progress, and prevents automatic crawling through references of references while retaining cited records and direct full-text downloads.

The [AI-request routing update](docs/AI-REQUEST-ROUTING-2026-11-09.md) sends direct AI-generation checks to media analysis even after a long guide or with Fact Check mode selected, without starting text extraction or factual research for an AI-only request.

The [progressive research update](docs/PROGRESSIVE-RESEARCH-2026-11-09.md) reviews matching evidence as it arrives, reuses completed reviews, and avoids repeated paid searches for unchanged evidence gaps. `FACT_CHECK_PROGRESSIVE_RESEARCH=true` enables this flow without restoring fixed overall source caps. The [source coverage update](docs/RESEARCH-SOURCE-COVERAGE.md) adds a default goal of 10 distinct relevant cited sources when available; resolution alone does not skip this additional coverage pass.

The [research completion update](docs/RESEARCH-COMPLETION-2026-11-09.md) preserves usable discovery links from truncated search responses and supports `FACT_CHECK_UNRESTRICTED_RESEARCH=true`, removing fixed research count and output-token caps while retaining evidence validation, cancellation and network timeouts.

The [published fact-check discovery update](docs/FACT-CHECK-DISCOVERY-2026-11-09.md) searches caption allegations alongside specific user questions, looks up related collected publications before external discovery, reads reference links alongside uploads, and retains useful contextual citations when an exact answer remains unknown. Readable publisher claim-review metadata is collected for future discovery without creating approved reusable verdicts. Unreadable and unrelated candidates no longer appear as ordinary evidence cards.

The [answer-quality enhancement](docs/ANSWER-QUALITY-ENHANCEMENT.md) preserves open factual questions, protects reviewed-claim matching against currency/percentage collisions, and checks generated explanation sentences against validated passages. Rejected wording falls back to attributed source excerpts while preserving the evidence verdict. The checks are bounded diagnostics, not a measured accuracy improvement or a complete semantic verifier. Restart evidence workers after updating these services; no new dependency or migration is required.

New submissions use the version 2 evidence pipeline: original text extraction, atomic claims, bounded article discovery, validated claim/source relationships, and explicit abstention. Media and article-classifier scores remain separate from factual verdicts. See [implementation, live checks, setup, and rollback](docs/EVIDENCE-VERIFICATION.md) and [held-out evaluation instructions](automation/evaluation/README.md). Existing saved reports are preserved.

The composer guides cover factual claims, supporting evidence, date/context, and AI origin. Factual and context guides work across supported text, image, video-frame, PDF, and accessible-link inputs; they do not promise audio transcription or access to private pages. Research guidance distinguishes events, quotations, statistics, research claims, historical records, announcements, and attributed predictions, using the relevant jurisdiction instead of assuming every topic requires a Philippine agency.

Web discovery is open; the publisher list supplies a priority order rather than an exhaustive list of usable evidence. The enhanced pipeline reviews preferred sources first, then reviews other retrieved pages when claims remain unresolved. Fallback pages are labeled `other_web`, require the same literal-passage and claim-scope validation, and cannot inherit an approved primary-record or fact-check-rating status. A cited fallback source carries a source-quality limitation and review advisory. Sources that are unreadable, unrelated, outside the cutoff, or merely repeat unsupported assertions still cannot establish a verdict. Search snippets are not evidence. Article references prioritize approved research and institutional records while retaining other public references within the existing retrieval budget.

`ResearchGuidance` is shared by claim planning, web discovery, and evidence review. A prediction's source attribution may appear in the surrounding upload rather than in the user's exact question; that basis remains eligible for research without rewriting the question or treating a future event as established. Changing the guide or research policy invalidates incompatible saved-result reuse and evidence calibration identities. Deploy these files and restart long-running workers for new submissions to use the change; opening a saved report does not run fresh paid research. Software tests check behavior, not population-level factual accuracy; use independent reviewed cases and evidence-quality evaluation before making an accuracy claim.

Image and video-frame extraction keeps a separate `visual_context` describing clearly visible objects. Claim planning, discovery and evidence review can use that description to understand captions such as an amount “each” over pictured vehicles. It is never appended to the verbatim OCR or treated as proof of an object's identity, price, ownership, location, event or authenticity. Inferred claim paraphrases remain separate, and decisive factual findings still require matching retrieved passages. A visible subject can make a claim searchable without establishing its truth.

The [evaluation and feedback loop](docs/PIPELINE-EVALUATION.md) reviews extraction, claims, retrieval, evidence, verdicts and explanation/citations separately. Report owners can flag a stage and add a note; feedback remains unverified. Export selected saved reports with `truthguard:export-pipeline-review`, prepare a frozen human review sheet with `automation/evaluation/evaluate_pipeline.py`, then generate stage counts, failure findings and review coverage. This offline workflow makes no API calls and cannot change a verdict or turn software tests into an accuracy claim.

## API usage monitoring and saved-result reuse

The [exact-image reuse update](docs/IMAGE-REPORT-REUSE-2026-11-09.md) opens the account's first matching retained report before queueing another analysis. Duplicate active uploads join their existing job. Eligible cross-account analysis is copied into a private report owned by the recipient, preserving the original assessment date and using zero new API tokens. A different question or an explicit Retry starts a new assessment.

When generated wording is unavailable, factual explanations can reuse the already reviewed correction and reasoning instead of displaying a raw source-table row. This local fallback retains authenticated citations and checks source/claim correspondence, numbers and named entities; it does not start another model request. Old extractive wording is refreshed for display while valid generated narratives and stored verdicts remain intact. Article extraction now separates adjacent table cells and paragraphs to keep evidence readable.

The existing evidence-review call now requests a fuller public explanation, normally 100–180 words across four to six grounded sentences when the evidence supports that detail. The display accepts up to 240 words and retains up to six reviewed sentences, including material qualifications. Individual evidence notes remain concise. This adds no separate prose-writing call, does not reprocess saved reports, and does not force padding for simple claims. In unrestricted mode the API output cap is already omitted; increasing the configured review allowance alone would not overcome a short-writing instruction.

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
