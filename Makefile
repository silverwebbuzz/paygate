# PayGate local development commands. Run `make help` to list them.

export UID := $(shell id -u)
export GID := $(shell id -g)

DC  := docker compose
PHP := $(DC) exec app
RUN := $(DC) run --rm --no-deps

.DEFAULT_GOAL := help
.PHONY: help setup build up down restart ps logs shell artisan composer npm migrate fresh test lint types check horizon-restart tinker hosts

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}'

setup: hosts build ## First-time setup: build image, install deps, migrate + demo users
	@test -f .env || cp .env.example .env
	$(RUN) app composer install
	$(RUN) app npm install
	$(DC) up -d
	@grep -q '^APP_KEY=base64:' .env || $(PHP) php artisan key:generate --no-interaction
	@grep -q '^PAYGATE_HASH_KEY=.' .env || sed -i.bak "s|^PAYGATE_HASH_KEY=.*|PAYGATE_HASH_KEY=$$(openssl rand -base64 32)|" .env && rm -f .env.bak
	$(PHP) php artisan migrate --seed --force
	@echo "\n  Ready: http://paygate.local  (login admin@paygate.local / password)"
	@echo "  Guide: Document/Developer-Guide.md\n"

build: ## Build the PHP image
	$(DC) build

up: ## Start all containers
	$(DC) up -d

down: ## Stop all containers
	$(DC) down

restart: ## Restart all containers
	$(DC) restart

ps: ## Show container status
	$(DC) ps

logs: ## Tail logs (make logs s=app)
	$(DC) logs -f --tail=100 $(s)

shell: ## Bash shell in the app container
	$(PHP) bash

artisan: ## Run artisan (make artisan cmd="migrate:status")
	$(PHP) php artisan $(cmd)

composer: ## Run composer (make composer cmd="require foo/bar")
	$(PHP) composer $(cmd)

npm: ## Run npm (make npm cmd="install")
	$(RUN) app npm $(cmd)

tinker: ## Laravel tinker
	$(PHP) php artisan tinker

migrate: ## Run migrations
	$(PHP) php artisan migrate

fresh: ## Drop everything, migrate and seed (local only!)
	$(PHP) php artisan migrate:fresh --seed

test: ## Run the PHP test suite (make test f=SomeTest)
	$(PHP) php artisan test $(if $(f),--filter=$(f),)

lint: ## Fix PHP code style (Pint)
	$(PHP) vendor/bin/pint --parallel

types: ## Static analysis (Larastan)
	$(PHP) vendor/bin/phpstan analyse --memory-limit=1G

check: ## Everything CI runs: style, static analysis, frontend checks, tests
	$(PHP) vendor/bin/pint --parallel --test
	$(PHP) vendor/bin/phpstan analyse --memory-limit=1G
	$(RUN) app npm run check
	$(RUN) app npm run types:check
	$(PHP) php artisan test

horizon-restart: ## Reload queue workers after code changes
	$(PHP) php artisan horizon:terminate

hosts: ## Check /etc/hosts has the local domains
	@grep -q "pay.paygate.local" /etc/hosts && echo "hosts: ok" || { \
		echo "Missing hosts entries. Run:"; \
		echo "  sudo sh -c 'echo \"127.0.0.1 paygate.local api.paygate.local pay.paygate.local\" >> /etc/hosts'"; \
		exit 1; }
