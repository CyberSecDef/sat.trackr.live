# =============================================================================
# sat.trackr.live — Single user-facing entry point for all dev/build/test/deploy
# =============================================================================
# Run `make` (no args) or `make help` for the full target list.
#
# All servers bind to 0.0.0.0 so the app is reachable from other devices on
# the LAN (e.g. for phone-based review).
# =============================================================================

# Default target: print help
.DEFAULT_GOAL := help

# Configurable knobs (override on the command line, e.g. `make serve PORT=9000`)
PORT       ?= 8000
VITE_PORT  ?= 5173
HOST       ?= 0.0.0.0
GROUP      ?=
NAME       ?=

# Detect LAN IP for the help message (best-effort)
LAN_IP := $(shell hostname -I 2>/dev/null | awk '{print $$1}')

# -----------------------------------------------------------------------------
# Help / introspection
# -----------------------------------------------------------------------------

.PHONY: help
help: ## Show this help (default target)
	@echo "sat.trackr.live — Makefile targets"
	@echo ""
	@echo "Usage: make <target> [VAR=value ...]"
	@echo ""
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | \
		awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-22s\033[0m %s\n", $$1, $$2}'
	@echo ""
	@echo "Servers bind to $(HOST). On this machine the LAN IP appears to be:"
	@echo "  http://$(LAN_IP):$(PORT)        (after \`make serve\` or \`make dev\`)"
	@echo "  http://$(LAN_IP):$(VITE_PORT)        (Vite dev server during \`make dev\`)"

# -----------------------------------------------------------------------------
# Install / clean
# -----------------------------------------------------------------------------

.PHONY: install
install: ## Install PHP and JS dependencies
	composer install
	npm install

.PHONY: install-prod
install-prod: ## Install for production (no dev deps, optimized autoloader)
	composer install --no-dev --optimize-autoloader
	npm ci

.PHONY: clean
clean: ## Remove build artifacts, vendor dirs, caches
	rm -rf public/build public/cesium vendor node_modules
	rm -rf .phpunit.cache .phpunit.result.cache .php-cs-fixer.cache .phpstan.cache
	@echo "Clean. Run \`make install\` to rebuild."

# -----------------------------------------------------------------------------
# Dev servers (bound to $(HOST), default 0.0.0.0)
# -----------------------------------------------------------------------------

.PHONY: dev
dev: ## Start PHP + Vite dev servers in parallel (Ctrl-C kills both)
	@echo "Starting PHP on $(HOST):$(PORT) and Vite on $(HOST):$(VITE_PORT)"
	@echo "LAN URL: http://$(LAN_IP):$(PORT)"
	@trap 'kill 0' EXIT INT TERM; \
		php -S $(HOST):$(PORT) -t public/ public/index.php & \
		npm run dev -- --host $(HOST) --port $(VITE_PORT) & \
		wait

.PHONY: serve
serve: ## Start PHP server only (serves the production build from public/)
	@echo "Serving on http://$(HOST):$(PORT)  (LAN: http://$(LAN_IP):$(PORT))"
	php -S $(HOST):$(PORT) -t public/ public/index.php

.PHONY: build
build: ## Production build of the SPA into public/build/
	npm run build

# -----------------------------------------------------------------------------
# Database migrations
# -----------------------------------------------------------------------------

.PHONY: migrate
migrate: ## Apply pending migrations
	php bin/console migrate

.PHONY: rollback
rollback: ## Roll back the most recent migration batch
	php bin/console migrate:rollback

.PHONY: migrate-status
migrate-status: ## Show applied vs pending migrations
	php bin/console migrate:status

.PHONY: make-migration
make-migration: ## Generate a new migration skeleton (NAME=add_foo)
	@if [ -z "$(NAME)" ]; then echo "Usage: make make-migration NAME=add_foo_table"; exit 1; fi
	php bin/console make:migration $(NAME)

# -----------------------------------------------------------------------------
# Data ingest
# -----------------------------------------------------------------------------

.PHONY: ingest
ingest: ## Run CelesTrak ingester for all configured groups
	php bin/console ingest:celestrak

.PHONY: ingest-group
ingest-group: ## Ingest a single CelesTrak group (GROUP=starlink)
	@if [ -z "$(GROUP)" ]; then echo "Usage: make ingest-group GROUP=starlink"; exit 1; fi
	php bin/console ingest:celestrak --group=$(GROUP)

.PHONY: health
health: ## Run the health CLI command (DB ping, row counts, last ingest)
	php bin/console health

# -----------------------------------------------------------------------------
# Quality gates
# -----------------------------------------------------------------------------

.PHONY: lint
lint: lint-php lint-js ## Run all linters (PHP + JS)

.PHONY: lint-php
lint-php: ## PHP-CS-Fixer in dry-run mode
	vendor/bin/php-cs-fixer fix --dry-run --diff

.PHONY: lint-js
lint-js: ## ESLint over resources/js
	npm run lint

.PHONY: lint-fix
lint-fix: ## Auto-fix all linters
	vendor/bin/php-cs-fixer fix
	npm run lint:fix

.PHONY: analyze
analyze: ## PHPStan static analysis
	vendor/bin/phpstan analyse --memory-limit=256M

.PHONY: typecheck
typecheck: ## TypeScript typecheck (no emit)
	npm run typecheck

.PHONY: test
test: test-php test-js ## Run all tests (PHPUnit + Vitest)

.PHONY: test-php
test-php: ## PHPUnit only
	vendor/bin/phpunit

.PHONY: test-js
test-js: ## Vitest only
	npm run test

.PHONY: ci
ci: lint analyze typecheck test ## Full quality gate (what CI would run)

# -----------------------------------------------------------------------------
# Deploy helpers
# -----------------------------------------------------------------------------

.PHONY: deploy-check
deploy-check: ## Sanity-check deploy prerequisites (env files, .htaccess, build artifacts)
	@echo "Checking deploy prerequisites..."
	@test -f .env       && echo "  ✓ .env present"      || echo "  ✗ .env missing"
	@test -f .env.prod  && echo "  ✓ .env.prod present" || echo "  ✗ .env.prod missing"
	@test -f public/.htaccess     && echo "  ✓ .htaccess present"     || echo "  ✗ .htaccess missing"
	@test -d public/build         && echo "  ✓ public/build/ present (run \`make build\` if stale)" || echo "  ✗ public/build/ missing — run \`make build\`"
	@test -d vendor               && echo "  ✓ vendor/ present"       || echo "  ✗ vendor/ missing — run \`make install-prod\`"
