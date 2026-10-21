.DEFAULT_GOAL := help
.PHONY: help install up start down restart logs shell console composer check-cache seed

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
	@curl -sI http://localhost:8080/ | grep -iE "x-cache|cache-control|age"

seed: ## Recreate the schema and seed deterministic content
	$(COMPOSE) exec app php bin/console app:seed
