# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

Reproducible benchmark: **full cache purge** vs **tag-based invalidation** on a fake news site (Symfony 7.4 + Varnish 7 + CloudFront). See README.md for the scenario, metrics and milestones. Weeks 1–2 are done (content model, seed data, all page types); purge strategies, k6 and CloudFront are not built yet.

Three strategies being compared, all triggered by an article update:
- **A. Full purge**: ban the whole Varnish cache.
- **B. Tag-based**: purge only pages tagged with the changed article (Varnish xkey, via FOSHttpCacheBundle).
- **C. Tag-based + CDN**: B, plus translating tags into a URL list for a CloudFront invalidation.

## Commands

Everything runs through Docker Compose, wrapped by the Makefile; there is no local PHP setup. `make` lists all targets.

```bash
make install                    # composer install (first time only)
make up                         # start app + Varnish (foreground); `make start` for background
make seed                       # drop + recreate schema, seed deterministic content (containers must run)
make console c="cache:clear"    # Symfony console
make composer c="require x/y"   # Composer in the app container
make check-cache                # show X-Cache / Cache-Control / Age from Varnish
```

- http://localhost:8002: Symfony directly (origin, uncached)
- http://localhost:8082: through Varnish (`X-Cache: HIT|MISS` header added in `vcl_deliver`)

No test suite, linter or static analysis is configured yet.

## Architecture

- `app` service: FrankenPHP (PHP 8.3) serving `public/index.php`, repo bind-mounted at `/app` (code changes apply without rebuild). `SERVER_NAME=":80"` disables automatic HTTPS.
- `varnish` service: `docker/varnish/default.vcl`, backend `app:80`. Strategy A/B purge logic is meant to live in this VCL (placeholders comment marks where).
- Caching is driven entirely by origin response headers, set in one place: `src/EventSubscriber/CacheHeadersSubscriber.php` adds `public`, `max-age=0` (no browser cache) and `s-maxage=60` (shared caches) to every successful GET. Controllers must not set cache headers themselves; this split is what makes Varnish/CloudFront the only caches measured.
- Controllers are invokable, attribute-routed (`config/routes.yaml` loads `src/Controller/`), and resolve entities by slug with `#[MapEntity]`. They render Twig templates in `templates/`; article lists share `_article_list.html.twig`, and the category nav comes from the `nav_categories()` Twig function (`src/Twig/NavigationExtension.php`).
- Data: Doctrine ORM on SQLite at `var/data.db` (gitignored). Entities `Article` → `Category`, `Author` (ManyToOne). List queries live in `ArticleRepository` and join category/author to avoid N+1.
- No migrations: `app:seed` (`src/Command/SeedCommand.php`) drops and recreates the schema with `SchemaTool`, then inserts 20 categories / 100 authors / 5,000 articles. Output is deterministic (seeded `Mt19937` + fixed base date) so benchmark runs are reproducible; keep any change to it deterministic.
- Fan-out of one article change (what purge strategies must handle): its article page, the home page if among latest 20, its category and author pages if among their latest 20, and article pages of the same category showing it under "More in …" (latest 5).
- No Symfony Flex: bundles go in `config/bundles.php` and config files are written by hand.
- Content updates (week 3+) go through a CLI command or a simple endpoint; no auth or admin UI.

## Scope rule

Scope is frozen (see README "Scope (frozen)"). Out of scope on purpose: auth/admin UI, visual design, IaC, CI/CD, Kubernetes, monitoring dashboards, a second framework. New ideas go in the README "Later" list, not into code, until the definition of done is met.

Strategy C uses real AWS resources; they must be torn down after each benchmark session.

## Conventions

- Conventional commits: `feat(topic): …`, `fix(topic): …`, `test(topic): …`.
- Commit messages are a single sentence (subject line only, no body).
- Never add a `Co-Authored-By: Claude …` / Anthropic trailer to commits or PR descriptions.
- Main branch is `main`.
