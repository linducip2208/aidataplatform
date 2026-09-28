# AIDataPlatform Makefile (Windows PowerShell / Git Bash / Linux / macOS compatible via `make`)
# Usage: make up | make logs | make migrate | make health ...

COMPOSE ?= docker compose
LARAVEL  ?= $(COMPOSE) exec laravel
FASTAPI  ?= $(COMPOSE) exec fastapi

.PHONY: up down restart ps logs build pull migrate seed fresh test test-laravel test-ai health backup restore lint format shell-laravel shell-ai docs clean

up: ## Start all services detached
	$(COMPOSE) up -d --build
	@$(MAKE) ps

down: ## Stop all services
	$(COMPOSE) down

restart: ## Restart all services
	$(COMPOSE) restart

ps: ## Show container status
	$(COMPOSE) ps

logs: ## Tail logs of all services
	$(COMPOSE) logs -f --tail=200

build: ## Build laravel + fastapi images
	$(COMPOSE) build laravel fastapi celery-worker celery-beat

pull: ## Pull base images
	$(COMPOSE) pull nginx postgres redis prometheus grafana

migrate: ## Run Laravel migrations + ai-engine alembic (if any)
	$(LARAVEL) php artisan migrate --force
	$(FASTAPI) alembic upgrade head || echo "[fastapi] no alembic migrations - skipping"

seed: ## Seed demo data (Laravel seeders)
	$(LARAVEL) php artisan db:seed --force

fresh: ## Fresh migrate + seed (DANGER: wipes DB)
	$(FASTAPI) alembic downgrade base || echo "[fastapi] alembic downgrade skipped"
	$(LARAVEL) php artisan migrate:fresh --seed --force
	$(FASTAPI) alembic upgrade head || echo "[fastapi] alembic upgrade skipped"

test: test-laravel test-ai ## Run all test suites

test-laravel: ## Laravel tests (Pest/PHPUnit)
	$(LARAVEL) php artisan test --parallel || $(LARAVEL) ./vendor/bin/phpunit

test-ai: ## FastAPI tests (pytest)
	$(COMPOSE) exec fastapi pytest -q

health: ## Healthcheck all core services
	bash infrastructure/scripts/healthcheck.sh || powershell -ExecutionPolicy Bypass -File infrastructure/scripts/healthcheck.ps1 || echo "run: bash infrastructure/scripts/healthcheck.sh"

backup: ## Backup postgres + datasets volume manifest
	bash infrastructure/scripts/backup.sh

restore: ## Restore (usage: make restore FILE=backups/aidata_YYYYmmdd_HHMMSS.sql.gz)
	bash infrastructure/scripts/restore.sh "$(FILE)"

lint: ## Lint (php + python hints)
	$(LARAVEL) ./vendor/bin/pint --test || echo "[laravel] pint not installed - skipping"
	$(COMPOSE) exec fastapi ruff check app || echo "[fastapi] ruff not installed - skipping"

format: ## Auto-format
	$(LARAVEL) ./vendor/bin/pint || echo "[laravel] pint not installed"
	$(COMPOSE) exec fastapi ruff format app || true

shell-laravel: ## Shell into laravel container
	$(COMPOSE) exec laravel bash

shell-ai: ## Shell into fastapi container
	$(COMPOSE) exec fastapi bash

docs: ## Print docs URLs
	@echo "Laravel API : http://localhost:8080/api/docs (or /api/documentation)"
	@echo "FastAPI Docs: http://localhost:8001/docs  | ReDoc: http://localhost:8001/redoc"
	@echo "Prometheus  : http://localhost:9090"
	@echo "Grafana     : http://localhost:3000 (admin / see .env)"

clean: ## Remove stopped containers + dangling images (safe)
	$(COMPOSE) down --remove-orphans
	docker image prune -f
