SHELL := /bin/bash
COMPOSE := HOST_UID=$$(id -u) HOST_GID=$$(id -g) docker compose --env-file .env -f infra/compose/docker-compose.yml
GATEWAY_PORT ?= 8080

.DEFAULT_GOAL := help
.PHONY: help init build up down restart logs ps install migrate fresh health \
        test test-api test-web test-ai lint lint-api lint-web lint-ai format

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  %-12s %s\n", $$1, $$2}'

init: ## Create .env with generated secrets
	@bash scripts/init-env.sh

build: ## Build all images
	$(COMPOSE) build

up: ## Start the whole stack in the background
	$(COMPOSE) up -d

down: ## Stop the stack (data volumes are kept)
	$(COMPOSE) down

restart: ## Restart the stack
	$(COMPOSE) restart

logs: ## Follow logs (make logs s=api for one service)
	$(COMPOSE) logs -f --tail=100 $(s)

ps: ## Show service status and health
	$(COMPOSE) ps

install: ## Install Laravel dependencies inside the api container (first run)
	$(COMPOSE) run --rm --no-deps -u $$(id -u):$$(id -g) api composer install

migrate: ## Run database migrations
	$(COMPOSE) exec api php artisan migrate --force

fresh: ## DESTROY all data volumes and rebuild from scratch
	$(COMPOSE) down -v
	$(COMPOSE) up -d --build

health: ## Check every service through the public gateway
	@echo "gateway:"; curl -fsS http://127.0.0.1:$(GATEWAY_PORT)/nginx-health
	@echo "api live:"; curl -fsS http://127.0.0.1:$(GATEWAY_PORT)/api/v1/health; echo
	@echo "api ready:"; curl -sS http://127.0.0.1:$(GATEWAY_PORT)/api/v1/health/ready; echo
	@echo "ai ready (private, via docker network):"; $(COMPOSE) exec -T ai-api python -c "import urllib.request; print(urllib.request.urlopen('http://127.0.0.1:8000/readyz', timeout=3).read().decode())"

test: test-api test-web test-ai ## Run every test suite

test-api: ## Laravel tests (PHPUnit)
	$(COMPOSE) exec -T api php artisan test

test-web: ## Frontend tests (Vitest), no Docker required
	cd apps/web && npm test

test-ai: ## Python tests (pytest), no Docker required
	cd services/ai && python -m pytest

lint: lint-api lint-web lint-ai ## Lint everything

lint-api: ## Pint (check only)
	$(COMPOSE) exec -T api composer lint

lint-web: ## ESLint + Prettier + TypeScript
	cd apps/web && npm run lint && npm run format:check && npm run typecheck

lint-ai: ## Ruff + mypy
	cd services/ai && python -m ruff check . && python -m ruff format --check . && python -m mypy app

format: ## Auto-format all code
	cd apps/web && npm run format
	cd services/ai && python -m ruff format . && python -m ruff check --fix .
	$(COMPOSE) exec -T api composer format
