# invalidate-less

A reproducible benchmark comparing **full cache purges** with **tag-based cache invalidation** on a content-heavy website, using Symfony and Varnish.

> Status: 🚧 in progress. Results will be published here once the benchmarks are done.

## The question

When content changes on a busy content site, how much origin load, latency and cache efficiency does tag-based invalidation save compared with purging everything?

## The scenario

A fake news site, seeded with fixtures:

- about 5,000 articles, 20 categories and 100 authors
- page types: home, category, author and article pages
- each page displays several pieces of content (the home page shows the latest articles, a category page lists its articles, and so on), so one article change affects several pages
- every article listing is an automatic list (latest articles by query), rendered as an ESI fragment that Varnish caches and tags separately from the page around it

During each benchmark run:

- simulated readers browse the site continuously
- an "editor" updates one article at a regular interval (for example, every 10 seconds), and publishes new articles that enter the automatic listings

## Strategies compared

| Strategy | On article update |
|---|---|
| **TTL only** (baseline) | Purge nothing; pages and listings expire after a short TTL (60 s) |
| **A. Full purge** | Ban the entire Varnish cache |
| **B. Tag-based purge** | Purge only what is tagged with the change (Varnish xkey): the article and listing fragments showing it on update, the listings it enters on publish |

A and B keep long TTLs (1 hour for articles, 1 day elsewhere) because purges keep content fresh. TTL only trades freshness for simplicity: readers can see content up to 60 s old, plus Varnish's 10 s default grace. The benchmark measures this staleness (see "visible after" below).

## Metrics

- origin requests per second
- cache hit ratio (from `varnishstat`)
- response time, p50 and p95 (from k6)
- origin CPU usage

## Stack

- PHP 8.3, Symfony 7, FOSHttpCacheBundle
- Varnish 7 with the xkey module
- SQLite (kept simple on purpose)
- Docker Compose for local runs
- k6 for load testing

## Getting started

Requirements: Docker with Docker Compose, and `make`.

```bash
# 1. Install PHP dependencies (first time only)
make install

# 2. Start the app and Varnish in the background
make start

# 3. Create the database and seed the content (same data on every run)
make seed
```

Then open:

- http://localhost:8002: Symfony directly (the origin, never cached)
- http://localhost:8082: the same site through Varnish

Check that caching works:

```bash
make check-cache
```

The first request shows `X-Cache: MISS`, the next ones `X-Cache: HIT` (pages stay cached for 1 hour for articles and 1 day for the other pages, unless invalidated).

Update an article and watch the cache get purged. Pick the strategy first:

- `full` (default): strategy A, bans the whole Varnish cache
- `tags`: strategy B, purges only the pages showing that article (Varnish xkey)
- `ttl`: no purge; the change shows up once the 60 s TTL expires

```bash
make strategy-tags   # or make strategy-full / make strategy-ttl; make strategy shows the active one

make update-article id=1
# or, as the k6 editor will do:
curl -X POST http://localhost:8002/editor/articles/1

make check-cache    # MISS again, then HIT (article 1 is on the home page)
make varnish-stats  # hits, misses, bans
```

### Make commands

Run `make` to list them all.

| Command | Description |
|---|---|
| `make install` | Install PHP dependencies |
| `make up` | Build and start app + Varnish in the foreground |
| `make start` | Build and start app + Varnish in the background |
| `make down` | Stop and remove containers |
| `make restart` | Restart containers in the background |
| `make logs` | Follow container logs |
| `make shell` | Open a shell in the app container |
| `make console c="cache:clear"` | Run a Symfony console command |
| `make composer c="require foo/bar"` | Run a Composer command |
| `make check-cache` | Show cache headers from Varnish |
| `make seed` | Recreate the schema and seed deterministic content |
| `make update-article id=42` | Update one article and invalidate the cache |
| `make publish-article` | Publish a new article (optional `category=3 author=7`) and invalidate the listings it enters |
| `make varnish-stats` | Show Varnish hit, miss, ban and object counters |
| `make strategy-full` | Switch to strategy A (full purge) |
| `make strategy-tags` | Switch to strategy B (tag-based purge) |
| `make strategy-ttl` | Switch to TTL only (no purge, short TTL) |
| `make strategy` | Show the active invalidation strategy |
| `make bench strategy=tags` | Run one benchmark for a strategy |
| `make bench-all` | Run the benchmark for every strategy |

### Running a benchmark

```bash
make bench-all                                  # full, tags, then ttl, default settings
make bench strategy=tags                        # one strategy
RATE=2000 DURATION=5m make bench-all            # tuned
```

Each run is isolated and reproducible: it recreates the containers on the chosen strategy, reseeds the same data, empties Varnish, warms the cache with readers only, then measures. Readers send a fixed number of requests per second (open model), whatever the response times, so every strategy serves the same load. k6 runs in Docker (`grafana/k6`), so nothing else needs installing.

| Setting (env) | Default | Meaning |
|---|---|---|
| `RATE` | 1000 | reader requests per second through Varnish |
| `DURATION` | 3m | measured run length |
| `WARMUP_DURATION` | 30s | readers-only warm-up before measuring |
| `EDIT_EVERY` | 10 | seconds between editor actions (on the origin) |
| `PUBLISH_EVERY` | 6 | every Nth editor action publishes a new article; the others update one |
| `PROBE_TIMEOUT` | 120 | seconds a freshness probe waits for a change to show up before giving up |
| `SEED` | 42 | random seed for the readers' page sequence |
| `SHORT_TTL` | 60 | shared TTL in seconds for the `ttl` strategy |

Readers' page mix: 15% home, 20% category, 10% author, 55% article; 80% of article views (and of updates) target the 200 newest articles.

Results print at the end and are appended to `bench/results/results.md` (raw k6 summary JSON next to it):

- **Reader req/s, p50, p95**: k6, reader requests only. **Dropped** counts reader requests k6 could not start in time; if it is not 0, the target rate was too high for the machine
- **Update / publish visible p95**: after each editor action, the editor polls the page through Varnish every 0.5 s until the change shows (the article's own page for an update, the home page's latest list for a publish) and records the delay. This is the staleness readers experience; **probe timeouts** count changes not visible within `PROBE_TIMEOUT`
- **Origin req/s**: Varnish `MAIN.backend_req` delta over the readers' window (page shells, ESI fragments and background refreshes)
- **Hit ratio**: `cache_hit / (cache_hit + cache_miss)` delta, counting every ESI fragment lookup
- **Origin CPU**: average of `docker stats` samples for the app container (100% = one core)

## Project structure

```
├── bench/                  k6 scenario (scenario.js) and benchmark runner (run.sh)
├── config/                 Symfony configuration
├── docker/varnish/         Varnish configuration (VCL)
├── public/index.php        Symfony front controller
├── src/Command/            Console commands (seed, update, publish)
├── src/Content/            Deterministic fake text generator
├── src/Controller/         Page controllers (home, category, author, article)
├── src/Entity/             Doctrine entities (Article, Category, Author)
├── src/Cache/              Invalidation strategies
├── src/Editor/             Article update and publish service (endpoints + CLI)
├── templates/              Twig templates
├── docker-compose.yml      app (Symfony on FrankenPHP) + Varnish, k6 (bench profile)
├── Dockerfile              PHP image for the app
└── Makefile                Shortcuts for common commands
```

## Scope (frozen)

**In scope:** everything above.

**Out of scope, on purpose:**

- authentication and admin UI (content updates happen through a CLI command or a simple endpoint)
- visual design beyond readable HTML
- Infrastructure as Code, CI/CD, Kubernetes
- monitoring dashboards (k6 output and `varnishstat` are enough)
- a second framework (NestJS) or a Lambda migration; these could be separate future projects

**Scope rule:** any new idea goes into the "Later" list below. It does not get built before the definition of done is met.

## Milestones

| Week | Milestone |
|---|---|
| 1 | Scope frozen (this document), repo created, Symfony app runs in Docker |
| 2 | Content model, fixtures, all page types render |
| 3 | Varnish in front of the app with proper cache headers; strategy A works |
| 4 | Strategy B: pages tagged, xkey purges on article update |
| 5 | k6 scenario written; first local benchmark runs |
| 6 | Final benchmark runs, results table and charts in this README |

## Definition of done

Anyone can clone this repository, run `docker compose up`, run a single benchmark command, and reproduce the results table below.

## Results

_Coming soon._

## Later

_Ideas parked until the definition of done is met:_

- Strategy C: CloudFront in front of Varnish with tag-to-URL invalidation. Dropped because invalidations cost $0.005 per path after 1,000 free per month, and an edit can fan out to about 260 article pages through the "More in category" fragment.
