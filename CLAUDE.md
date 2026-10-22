# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

Reproducible benchmark: **full cache purge** vs **tag-based invalidation** on a fake news site: a read-only Symfony 7.4 site behind Varnish 7, a NestJS editor service that writes articles and queues events, and an invalidator that consumes them (SQS API) and purges Varnish. See README.md for the scenario, metrics and milestones. Weeks 1–5 are done (content model, seed data, all page types with ESI listings, editor update/publish, strategies A and B, k6 benchmark). Final runs and the README results are not done yet. Strategy C (CloudFront) was dropped for cost; see README "Later".

Strategies being compared, all triggered by an editor update or publish:
- **TTL only**: purge nothing, short TTLs.
- **A. Full purge**: ban the whole Varnish cache.
- **B. Tag-based**: purge only pages tagged with the changed article (Varnish xkey, via FOSHttpCacheBundle).

## Commands

Everything runs through Docker Compose, wrapped by the Makefile; there is no local PHP setup. `make` lists all targets.

```bash
make install                    # composer install (the app's entrypoint also runs it, and seeds, on first start)
make up                         # start app, Varnish, editor, sqs, invalidator (foreground); `make start` for background
make seed                       # drop + recreate schema, seed deterministic content (containers must run)
make console c="cache:clear"    # Symfony console
make composer c="require x/y"   # Composer in the app container
make check-cache                # show X-Cache / Cache-Control / Age from Varnish
make update-article id=42       # update one article through the editor (the invalidator purges asynchronously)
make publish-article            # publish a new article (optional category=3 author=7)
make editor-build               # rebuild + restart the editor after a code change (no bind mount)
make editor-logs                # follow the editor's logs
make invalidator-build          # rebuild + restart the invalidator after a code change
make invalidator-logs           # one line per handled event
make queue-stats                # messages waiting / in flight in the queue and its DLQ
make bench strategy=tags        # one benchmark run; make bench-all runs every strategy
RATE=200 DURATION=40s WARMUP_DURATION=5s EDIT_EVERY=5 PROBE_TIMEOUT=30 make bench strategy=tags   # quick smoke run
make varnish-stats              # hits, misses, bans, objects
```

- http://localhost:8002: Symfony directly (origin, uncached)
- http://localhost:3002: editor (`POST /articles/{id}`, `POST /articles`)
- http://localhost:9334: SQS API (ElasticMQ), http://localhost:9335: its UI. Host ports are 9334/9335 because 9324 is often taken by other ElasticMQ containers.
- http://localhost:8082: through Varnish (`X-Cache: HIT|MISS` header added in `vcl_deliver`)

No test suite, linter or static analysis is configured yet.

## Architecture

- `app` service: FrankenPHP (PHP 8.3) serving `public/index.php`, repo bind-mounted at `/app` (code changes apply without rebuild). `docker/app/entrypoint.sh` runs `composer install` when `vendor/` is missing and `app:seed` when the `article` table is missing (checks the schema, not the file: the editor may create an empty DB file first), so a fresh clone works with `docker compose up`. `SERVER_NAME=":80"` disables automatic HTTPS.
- `varnish` service: `docker/varnish/default.vcl`, backend `app:80`; handles purges and assembles ESI.
- `editor` service: NestJS 11 on Node 24 (`editor/`), built into its image (`make editor-build` after changes). Owns every write and queues an event per change; the Symfony app is read-only.
- `sqs` service: ElasticMQ (`docker/elasticmq/elasticmq.conf`), in-memory, queues `article-events` (visibility 30 s) and `article-events-dlq` (after 5 receives). Editor and invalidator share the `x-sqs-env` compose anchor (endpoint, queue URL, dummy credentials).
- `invalidator` service: TypeScript on Node 24, no framework (`invalidator/`), built into its image (`make invalidator-build`).
- Caching is driven entirely by origin response headers, set in each controller: `#[Cache(public: true, maxage: …)]` (pages `max-age=60`, browsers keep them 1 minute and purges cannot reach them; `ListingController` class-level `max-age=0`), plus an explicit `->setSharedMaxAge($ttl->shell()|article()|listing())` from `src/Cache/CacheTtl.php`. `CacheTtl` returns long TTLs (shells/listings 86400, articles 3600) so invalidation drives freshness, or `SHORT_TTL` (default 60) for all when the strategy is `ttl`. Don't put `smaxage` back in `#[Cache]`: an explicitly set s-maxage wins over the attribute, but keeping both is confusing. New controllers must follow the same pattern.
- Page controllers are invokable, attribute-routed (`config/routes.yaml` loads `src/Controller/`), resolve entities by slug with `#[MapEntity]`, and render page *shells* only: no list queries. The category nav comes from the `nav_categories()` Twig function (`src/Twig/NavigationExtension.php`) and stays inline.
- ESI listings ("automatic listings", built by query): `ListingController` serves `/_esi/latest`, `/_esi/category/{id}`, `/_esi/author/{id}` (latest 20) and `/_esi/category/{id}/related/{exclude}` (latest 5, the article page's "More in …"). Templates include them with `render_esi(path(...))`. `framework.esi: true`; Varnish sends `Surrogate-Capability` and sets `do_esi` when Symfony answers with `Surrogate-Control`. Without a surrogate (origin on :8002) fragments render inline, and their tags then land on the shell response. On :8082, `X-Cache` and `X-Cache-Tags` describe the shell only; curl a `/_esi/...` URL to inspect a fragment. Each fragment shows its own "List generated at" timestamp.
- Data: Doctrine ORM on SQLite at `var/data/data.db`, a named Docker volume `data` shared with the editor (mounted at `/data`; bind mounts are unsafe for SQLite locks across containers). Not visible on the host. Entities `Article` → `Category`, `Author` (ManyToOne). List queries live in `ArticleRepository` and join category/author to avoid N+1.
- No migrations: `app:seed` (`src/Command/SeedCommand.php`) drops and recreates the schema with `SchemaTool`, then inserts 20 categories / 100 authors / 5,000 articles. Output is deterministic (seeded `Mt19937` + fixed base date) so benchmark runs are reproducible; keep any change to it deterministic.
- Fan-out per editor event: **update** changes an article but never list membership (publishedAt is fixed), so it affects its article shell and every listing fragment showing it; **publish** adds an article to the latest list, its category lists (incl. related fragments) and its author list. Page shells (home/category/author) never change.
- No Symfony Flex: bundles go in `config/bundles.php` and config files are written by hand.
- Editor (`editor/`, no auth): `ArticlesController` → `ArticleEditor` → `ArticleRepository` (plain SQL with built-in `node:sqlite`, no ORM; schema owned by `app:seed`, the editor never creates tables). `update` (`POST /articles/{id}` → `{id, revision}`) bumps `revision`/`updated_at`; `publish` (`POST /articles[?category=&author=]` → 201 `{id, slug}`) creates an article dated now (UTC, Doctrine's `Y-m-d H:i:s`), category/author round-robin on the article number and text from `editor/src/content/text-generator.ts` seeded by that number (deterministic, but not the PHP generator's output). Both write, then send an event through the `ArticleEvents` port (`editor/src/events/`, adapter `SqsArticleEvents`) and answer once SQS acknowledges it. Revision is shown wherever an article is listed, so stale content is visible. `make seed` does not purge Varnish: restart it (`docker compose restart varnish`) after reseeding.
- Events: CloudEvents 1.0 JSON, `type` `article.updated` | `article.published`, `data` `{id, categoryId, authorId}`; built in `editor/src/events/sqs-article-events.ts`, validated by `invalidator/src/core/article-event.ts`. This is the contract between the two projects.
- Invalidator (ports and adapters): `src/core/` is platform-free. `strategies.ts` maps an event to a `Purge` (`full` = ban all, `tags` = `article-{id}` on update / `list-latest` + `list-category-{id}` + `list-author-{id}` on publish, `ttl` = none; `strategyFromEnv` throws on an unknown `INVALIDATION_STRATEGY`), and `handleEvent` applies it through the `Cache` port (`src/varnish/varnish-client.ts`: `BAN` `.*`, `PURGEKEYS` on `xkey-purge`, 5 s timeout, throws if not 2xx). Entry adapters: `src/adapters/sqs-worker.ts` (long-poll, deletes a message only after a successful purge, so failures retry after the visibility timeout and then go to the DLQ) and `src/adapters/lambda.ts` (SQS trigger, `batchItemFailures`; `npm run bundle:lambda`, try it with `node dist/scripts/invoke-lambda.js [id] [categoryId] [authorId] [type]` in the container). Both adapters share the same core, so keep logic out of them. The app also reads `INVALIDATION_STRATEGY`, only for `CacheTtl`. Switch with `make strategy-full` / `make strategy-tags` / `make strategy-ttl` (recreates app + invalidator); `make strategy` shows the invalidator's. A plain `make start`/`make up` resets to `full`.
- Varnish (`docker/varnish/default.vcl`, `import xkey`): `BAN` (strategy A) and `PURGEKEYS` (strategy B, hard `xkey.purge` on the `xkey-purge` header) accepted from Docker private ranges (which includes the host via Docker Desktop). Bans match `obj.http.X-Url`/`X-Host`/`Content-Type` (lurker-friendly); the app sends tags in an `xkey` response header (FOS `tag_mode: purgekeys`). `X-Url` and `X-Host` are stripped in `vcl_deliver`; `xkey` is renamed to `X-Cache-Tags` there for debugging. VCL changes need `docker compose up -d --force-recreate varnish`.
- FOSHttpCacheBundle is used only for response tagging (`ResponseTagger`); it still needs an HTTPlug async client: provided by `symfony/http-client` + `php-http/httplug` + `guzzlehttp/promises` + `nyholm/psr7`. Its debug header is disabled.
- Benchmark (`bench/`): `run.sh <strategy>` rebuilds and recreates app, Varnish, editor, sqs and invalidator (empty cache and queue) with `INVALIDATION_STRATEGY` and `SHORT_TTL` exported (every `docker compose` call must keep it, or compose recreates app/invalidator on `full`), reseeds, exports `bench/urls.json` (`app:bench:urls`, newest articles first), restarts Varnish (empty cache), runs a readers-only warm-up, then the measured k6 run. k6 runs as the `k6` compose service (profile `bench`) with `run --no-deps` (otherwise compose re-creates the app). Readers hit `http://varnish`, the k6 editor hits the editor service (`http://editor:3000`). Readers use `constant-arrival-rate` (open model, `RATE` req/s) so strategies serve the same load; watch `dropped_iterations`. The editor (also arrival-rate, deterministic action sequence via `exec.scenario.iterationInTest`) probes each change through Varnish until visible, timed from the editor's answer, so the queue and invalidator delay is included (`update_visible_after`, `publish_visible_after` Trends, `probe_timeouts`), with `gracefulStop` so the last probes finish after readers stop. Metrics: k6 reader-only submetrics (exist because thresholds reference them), Varnish counter deltas (`varnishstat -j`) and `docker stats` CPU, both captured over the readers' window only (`DURATION`), not the probe tail. Output: `bench/results/` (gitignored), one table row per run in `results.md`. `randomSeed(SEED*1000+__VU)` keeps per-VU page sequences identical across runs; timing is not deterministic.

## Scope rule

Scope is frozen (see README "Scope (frozen)"); past deliberate changes are listed in its "Scope changes" table, so record any new one there with its reason. Out of scope on purpose: auth/admin UI, visual design, IaC, CI/CD, Kubernetes, monitoring dashboards, cloud deployment (the Lambda adapter is not deployed), a third framework or language. New ideas go in the README "Later" list, not into code, until the definition of done is met.

## Conventions

- Conventional commits: `feat(topic): …`, `fix(topic): …`, `test(topic): …`.
- Commit messages are a single sentence (subject line only, no body).
- Never add a `Co-Authored-By: Claude …` / Anthropic trailer to commits or PR descriptions.
- Main branch is `main`.
