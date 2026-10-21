# invalidate-less

A reproducible benchmark comparing **full cache purges** with **tag-based cache invalidation** on a content-heavy website, using Symfony, Varnish and CloudFront.

> Status: 🚧 in progress. Results will be published here once the benchmarks are done.

## The question

When content changes on a busy content site, how much origin load, latency and cache efficiency does tag-based invalidation save compared with purging everything?

## The scenario

A fake news site, seeded with fixtures:

- about 5,000 articles, 20 categories and 100 authors
- page types: home, category, author and article pages
- each page displays several pieces of content (the home page shows the latest articles, a category page lists its articles, and so on), so one article change affects several pages

During each benchmark run:

- simulated readers browse the site continuously
- an "editor" updates one article at a regular interval (for example, every 10 seconds)

## Strategies compared

| Strategy | On article update |
|---|---|
| **A. Full purge** | Ban the entire Varnish cache |
| **B. Tag-based purge** | Purge only the pages tagged with the changed article (Varnish xkey) |
| **C. Tag-based + CDN** | Strategy B, plus translating tags into the URL list sent to a CloudFront invalidation |

## Metrics

- origin requests per second
- cache hit ratio (from `varnishstat`)
- response time, p50 and p95 (from k6)
- origin CPU usage
- for strategy C: number of CloudFront invalidation paths per update

## Stack

- PHP 8.3, Symfony 7, FOSHttpCacheBundle
- Varnish 7 with the xkey module
- SQLite (kept simple on purpose)
- Docker Compose for local runs
- k6 for load testing
- CloudFront for strategy C only

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

The first request shows `X-Cache: MISS`, the next ones `X-Cache: HIT` for 60 seconds.

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

## Project structure

```
├── config/                 Symfony configuration
├── docker/varnish/         Varnish configuration (VCL)
├── public/index.php        Symfony front controller
├── src/Command/            Console commands (`app:seed`)
├── src/Controller/         Page controllers (home, category, author, article)
├── src/Entity/             Doctrine entities (Article, Category, Author)
├── src/EventSubscriber/    Cache headers for every page
├── templates/              Twig templates
├── docker-compose.yml      app (Symfony on FrankenPHP) + Varnish
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
| 6 | Strategy C: CloudFront in front, tag-to-URL invalidation |
| 7–8 | Final benchmark runs, results table and charts in this README |

## Definition of done

Anyone can clone this repository, run `docker compose up`, run a single benchmark command, and reproduce the results table below.

## Results

_Coming soon._

## Later

_Ideas parked until the definition of done is met:_

-

## Cost note

Strategy C uses real AWS resources. Tear them down after each benchmark session.
