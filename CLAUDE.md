# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

Reproducible benchmark: **full cache purge** vs **tag-based invalidation** on a fake news site (Symfony 7.4 + Varnish 7). See README.md for the scenario, metrics and milestones. Weeks 1–5 are done (content model, seed data, all page types with ESI listings, editor update/publish, strategies A and B, k6 benchmark). Final runs and the README results are not done yet. Strategy C (CloudFront) was dropped for cost; see README "Later".

Strategies being compared, all triggered by an editor update or publish:
- **TTL only**: purge nothing, short TTLs.
- **A. Full purge**: ban the whole Varnish cache.
- **B. Tag-based**: purge only pages tagged with the changed article (Varnish xkey, via FOSHttpCacheBundle).

## Commands

Everything runs through Docker Compose, wrapped by the Makefile; there is no local PHP setup. `make` lists all targets.

```bash
make install                    # composer install (first time only)
make up                         # start app + Varnish (foreground); `make start` for background
make seed                       # drop + recreate schema, seed deterministic content (containers must run)
make console c="cache:clear"    # Symfony console
make composer c="require x/y"   # Composer in the app container
make check-cache                # show X-Cache / Cache-Control / Age from Varnish
make update-article id=42       # update one article and run the invalidation strategy
make publish-article            # publish a new article (optional category=3 author=7)
make bench strategy=tags        # one benchmark run; make bench-all runs every strategy
RATE=200 DURATION=40s WARMUP_DURATION=5s EDIT_EVERY=5 PROBE_TIMEOUT=30 make bench strategy=tags   # quick smoke run
make varnish-stats              # hits, misses, bans, objects
```

- http://localhost:8002: Symfony directly (origin, uncached)
- http://localhost:8082: through Varnish (`X-Cache: HIT|MISS` header added in `vcl_deliver`)

No test suite, linter or static analysis is configured yet.

## Architecture

- `app` service: FrankenPHP (PHP 8.3) serving `public/index.php`, repo bind-mounted at `/app` (code changes apply without rebuild). `SERVER_NAME=":80"` disables automatic HTTPS.
- `varnish` service: `docker/varnish/default.vcl`, backend `app:80`; handles purges and assembles ESI.
- Caching is driven entirely by origin response headers, set in each controller: `#[Cache(public: true, maxage: …)]` (pages `max-age=60`, browsers keep them 1 minute and purges cannot reach them; `ListingController` class-level `max-age=0`), plus an explicit `->setSharedMaxAge($ttl->shell()|article()|listing())` from `src/Cache/CacheTtl.php`. `CacheTtl` returns long TTLs (shells/listings 86400, articles 3600) so invalidation drives freshness, or `SHORT_TTL` (default 60) for all when the strategy is `ttl`. Don't put `smaxage` back in `#[Cache]`: an explicitly set s-maxage wins over the attribute, but keeping both is confusing. New controllers must follow the same pattern; the editor endpoints have none.
- Page controllers are invokable, attribute-routed (`config/routes.yaml` loads `src/Controller/`), resolve entities by slug with `#[MapEntity]`, and render page *shells* only: no list queries. The category nav comes from the `nav_categories()` Twig function (`src/Twig/NavigationExtension.php`) and stays inline.
- ESI listings ("automatic listings", built by query): `ListingController` serves `/_esi/latest`, `/_esi/category/{id}`, `/_esi/author/{id}` (latest 20) and `/_esi/category/{id}/related/{exclude}` (latest 5, the article page's "More in …"). Templates include them with `render_esi(path(...))`. `framework.esi: true`; Varnish sends `Surrogate-Capability` and sets `do_esi` when Symfony answers with `Surrogate-Control`. Without a surrogate (origin on :8002) fragments render inline, and their tags then land on the shell response. On :8082, `X-Cache` and `X-Cache-Tags` describe the shell only; curl a `/_esi/...` URL to inspect a fragment. Each fragment shows its own "List generated at" timestamp.
- Data: Doctrine ORM on SQLite at `var/data.db` (gitignored). Entities `Article` → `Category`, `Author` (ManyToOne). List queries live in `ArticleRepository` and join category/author to avoid N+1.
- No migrations: `app:seed` (`src/Command/SeedCommand.php`) drops and recreates the schema with `SchemaTool`, then inserts 20 categories / 100 authors / 5,000 articles. Output is deterministic (seeded `Mt19937` + fixed base date) so benchmark runs are reproducible; keep any change to it deterministic.
- Fan-out per editor event: **update** changes an article but never list membership (publishedAt is fixed), so it affects its article shell and every listing fragment showing it; **publish** adds an article to the latest list, its category lists (incl. related fragments) and its author list. Page shells (home/category/author) never change.
- No Symfony Flex: bundles go in `config/bundles.php` and config files are written by hand.
- Editor (`src/Editor/ArticleEditor.php`, no auth, frozen scope): `update` (`POST /editor/articles/{id}` → `{id, revision}`, `app:article:update <id>`) bumps `revision`/`updatedAt`; `publish` (`POST /editor/articles[?category=&author=]` → 201 `{id, slug}`, `app:article:publish [--category --author]`) creates an article dated now, category/author round-robin on the article number and text from `src/Content/TextGenerator.php` seeded by that number (deterministic). Both flush, then invalidate synchronously. Revision is shown wherever an article is listed, so stale content is visible. `make seed` does not purge Varnish: restart it (`docker compose restart varnish`) after reseeding.
- Invalidation seam: `App\Cache\ArticleInvalidator` (`articleUpdated`, `articlePublished`), aliased in `config/services.yaml` to `ConfiguredInvalidator`, which picks the strategy from the `INVALIDATION_STRATEGY` env var at runtime (`full` = A, `tags` = B, `ttl` = no purge with short TTLs; unknown value throws before the editor writes). `NoopInvalidator` does nothing; `FullPurgeInvalidator` bans everything on both events; `TagInvalidator` purges `article-{id}` on update and `list-latest` + `list-category-{id}` + `list-author-{id}` on publish. Both call `CacheManager::flush()` so purges are synchronous. Switch with `make strategy-full` / `make strategy-tags` / `make strategy-ttl` (recreates the app container with the env var, passed through `docker-compose.yml`, default `full`); `make strategy` shows the active one. A plain `make start`/`make up` resets to `full`.
- Tagging: `src/Cache/CacheTags.php` owns all tag names (`article-{id}`, `list-latest`, `list-category-{id}`, `list-author-{id}`) for both tagging and purging. Each listing fragment tags itself with its list tag + the ids of the articles it renders; the article shell tags itself `article-{id}`; other shells are untagged.
- Varnish (`docker/varnish/default.vcl`, `import xkey`): `BAN` (strategy A) and `PURGEKEYS` (strategy B, hard `xkey.purge` on the `xkey-purge` header) accepted from Docker private ranges (which includes the host via Docker Desktop). Bans match `obj.http.X-Url`/`X-Host`/`Content-Type` (lurker-friendly); the app sends tags in an `xkey` response header (FOS `tag_mode: purgekeys`). `X-Url` and `X-Host` are stripped in `vcl_deliver`; `xkey` is renamed to `X-Cache-Tags` there for debugging. VCL changes need `docker compose up -d --force-recreate varnish`.
- FOSHttpCacheBundle needs an HTTPlug async client: provided by `symfony/http-client` + `php-http/httplug` + `guzzlehttp/promises` + `nyholm/psr7`. Its debug header is disabled.
- Benchmark (`bench/`): `run.sh <strategy>` recreates app + Varnish with `INVALIDATION_STRATEGY` and `SHORT_TTL` exported (every `docker compose` call must keep it, or compose recreates the app on `full`), reseeds, exports `bench/urls.json` (`app:bench:urls`, newest articles first), restarts Varnish (empty cache), runs a readers-only warm-up, then the measured k6 run. k6 runs as the `k6` compose service (profile `bench`) with `run --no-deps` (otherwise compose re-creates the app). Readers hit `http://varnish`, the editor hits `http://app` directly. Readers use `constant-arrival-rate` (open model, `RATE` req/s) so strategies serve the same load; watch `dropped_iterations`. The editor (also arrival-rate, deterministic action sequence via `exec.scenario.iterationInTest`) probes each change through Varnish until visible (`update_visible_after`, `publish_visible_after` Trends, `probe_timeouts`), with `gracefulStop` so the last probes finish after readers stop. Metrics: k6 reader-only submetrics (exist because thresholds reference them), Varnish counter deltas (`varnishstat -j`) and `docker stats` CPU, both captured over the readers' window only (`DURATION`), not the probe tail. Output: `bench/results/` (gitignored), one table row per run in `results.md`. `randomSeed(SEED*1000+__VU)` keeps per-VU page sequences identical across runs; timing is not deterministic.

## Scope rule

Scope is frozen (see README "Scope (frozen)"). Out of scope on purpose: auth/admin UI, visual design, IaC, CI/CD, Kubernetes, monitoring dashboards, a second framework. New ideas go in the README "Later" list, not into code, until the definition of done is met.

## Conventions

- Conventional commits: `feat(topic): …`, `fix(topic): …`, `test(topic): …`.
- Commit messages are a single sentence (subject line only, no body).
- Never add a `Co-Authored-By: Claude …` / Anthropic trailer to commits or PR descriptions.
- Main branch is `main`.
