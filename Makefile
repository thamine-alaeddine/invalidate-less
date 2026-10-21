.DEFAULT_GOAL := help
.PHONY: help install up start down restart logs shell console composer check-cache seed update-article varnish-stats strategy-full strategy-tags strategy-ttl strategy publish-article bench bench-all

COMPOSE := docker compose

help: ## List available commands
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}'

install: ## Install PHP dependencies
	$(COMPOSE) run --rm app composer install

up: ## Build and start app + Varnish in the foreground
	$(COMPOSE) up --build

start: ## Build and start app + Varnish in the background
	$(COMPOSE) up --build -d

down: ## Stop and remove containers
	$(COMPOSE) down

restart: down start ## Restart containers in the background

logs: ## Follow container logs
	$(COMPOSE) logs -f

shell: ## Open a shell in the app container
	$(COMPOSE) exec app bash

console: ## Run a Symfony console command, e.g. make console c="cache:clear"
	$(COMPOSE) exec app php bin/console $(c)

composer: ## Run a Composer command, e.g. make composer c="require foo/bar"
	$(COMPOSE) exec app composer $(c)

check-cache: ## Show cache headers from Varnish
	@curl -sI http://localhost:8082/ | grep -iE "x-cache|cache-control|age"

seed: ## Recreate the schema and seed deterministic content
	$(COMPOSE) exec app php bin/console app:seed

update-article: ## Update one article and invalidate the cache, e.g. make update-article id=42
	$(COMPOSE) exec app php bin/console app:article:update $(id)

varnish-stats: ## Show Varnish hit, miss, ban and object counters
	$(COMPOSE) exec varnish varnishstat -1 -f MAIN.cache_hit -f MAIN.cache_miss -f MAIN.bans -f MAIN.n_object

strategy-full: ## Switch to strategy A: ban the whole Varnish cache on article update
	INVALIDATION_STRATEGY=full $(COMPOSE) up -d app

strategy-tags: ## Switch to strategy B: purge only pages tagged with the updated article
	INVALIDATION_STRATEGY=tags $(COMPOSE) up -d app

strategy-ttl: ## Switch to TTL-only: no purge, pages and listings expire after SHORT_TTL seconds (default 60)
	INVALIDATION_STRATEGY=ttl $(COMPOSE) up -d app

strategy: ## Show the active invalidation strategy
	@$(COMPOSE) exec app printenv INVALIDATION_STRATEGY

publish-article: ## Publish a new article and invalidate the cache, e.g. make publish-article (optional category=3 author=7)
	$(COMPOSE) exec app php bin/console app:article:publish $(if $(category),--category=$(category)) $(if $(author),--author=$(author))

bench: ## Run one benchmark, e.g. make bench strategy=tags (env: RATE, DURATION, WARMUP_DURATION, EDIT_EVERY, PUBLISH_EVERY, PROBE_TIMEOUT, SEED, SHORT_TTL)
	bench/run.sh $(or $(strategy),full)

bench-all: ## Run the benchmark for every strategy, one after the other
	bench/run.sh full
	bench/run.sh tags
	bench/run.sh ttl
