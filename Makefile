.DEFAULT_GOAL := help
.PHONY: help install up start down restart logs shell console composer check-cache seed update-article editor-build editor-logs invalidator-build invalidator-logs queue-stats varnish-stats strategy-full strategy-tags strategy-ttl strategy publish-article bench bench-all

COMPOSE := docker compose

help: ## List available commands
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}'

install: ## Install PHP dependencies
	$(COMPOSE) run --rm app composer install

up: ## Build and start the whole stack (app, Varnish, editor, queue, invalidator) in the foreground
	$(COMPOSE) up --build

start: ## Build and start the whole stack in the background
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

update-article: ## Update one article through the editor (the invalidator purges asynchronously), e.g. make update-article id=42
	@curl -s -X POST http://localhost:3002/articles/$(id); echo

editor-build: ## Rebuild and restart the NestJS editor after a code change
	$(COMPOSE) up -d --build editor

editor-logs: ## Follow the editor's logs
	$(COMPOSE) logs -f editor

invalidator-build: ## Rebuild and restart the invalidator after a code change
	$(COMPOSE) up -d --build invalidator

invalidator-logs: ## Follow the invalidator's logs (one line per handled event)
	$(COMPOSE) logs -f invalidator

queue-stats: ## Show messages waiting / in flight in the article-events queue and its DLQ
	@for q in article-events article-events-dlq; do \
		printf '%-20s ' $$q; \
		curl -s "http://localhost:9334/000000000000/$$q?Action=GetQueueAttributes&AttributeName.1=All" \
			| tr -d ' \n' | grep -oE 'ApproximateNumberOfMessages(NotVisible)?</Name><Value>[0-9]+' \
			| sed -e 's#</Name><Value>#=#' -e 's#ApproximateNumberOfMessagesNotVisible#in_flight#' -e 's#ApproximateNumberOfMessages#waiting#' | tr '\n' ' '; \
		echo; \
	done

varnish-stats: ## Show Varnish hit, miss, ban and object counters
	$(COMPOSE) exec varnish varnishstat -1 -f MAIN.cache_hit -f MAIN.cache_miss -f MAIN.bans -f MAIN.n_object

strategy-full: ## Switch to strategy A: ban the whole Varnish cache on article update
	INVALIDATION_STRATEGY=full $(COMPOSE) up -d app invalidator

strategy-tags: ## Switch to strategy B: purge only pages tagged with the updated article
	INVALIDATION_STRATEGY=tags $(COMPOSE) up -d app invalidator

strategy-ttl: ## Switch to TTL-only: no purge, pages and listings expire after SHORT_TTL seconds (default 60)
	INVALIDATION_STRATEGY=ttl $(COMPOSE) up -d app invalidator

strategy: ## Show the active invalidation strategy
	@$(COMPOSE) exec invalidator printenv INVALIDATION_STRATEGY

publish-article: ## Publish a new article through the editor (the invalidator purges asynchronously), e.g. make publish-article (optional category=3 author=7)
	@curl -s -X POST "http://localhost:3002/articles?$(if $(category),category=$(category)&)$(if $(author),author=$(author))"; echo

bench: ## Run one benchmark, e.g. make bench strategy=tags (env: RATE, DURATION, WARMUP_DURATION, EDIT_EVERY, PUBLISH_EVERY, PROBE_TIMEOUT, SEED, SHORT_TTL)
	bench/run.sh $(or $(strategy),full)

bench-all: ## Run the benchmark for every strategy, one after the other
	bench/run.sh full
	bench/run.sh tags
	bench/run.sh ttl
