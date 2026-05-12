# FrankenPress site — common dev tasks.

IMAGE ?= site:dev
export FP_SITE_IMAGE ?= $(IMAGE)

.PHONY: setup build up down logs shell wp lint test ci clean reset snapshot

setup: ## composer install + initial WP install (idempotent)
	@if [ ! -f .env ]; then cp .env.example .env && echo "created .env from .env.example"; fi
	docker run --rm -v "$$PWD:/app" -w /app composer:2 install --prefer-dist --no-interaction --no-progress

build: ## Build the site image
	docker compose build site

up: ## Start the local stack (site + db + redis + minio)
	docker compose up -d

down: ## Stop the stack and drop volumes (destroys local DB + minio data)
	docker compose down -v

logs: ## Tail the site logs
	docker compose logs -f site

shell: ## Bash into the running site container
	docker compose exec site bash

wp: ## Run wp-cli inside the site container. Example: make wp ARGS="plugin list"
	docker compose exec site wp --allow-root --path=/app/web/wp $(ARGS)

lint: ## phpcs against config/ and any custom mu-plugins
	docker run --rm -v "$$PWD:/app" -w /app composer:2 install --prefer-dist --no-interaction --no-progress
	docker run --rm -v "$$PWD:/app" -w /app php:8.3-cli vendor/bin/phpcs

ci: build up ## Build, bring up, wait for healthcheck
	@printf "waiting for site healthcheck...\n"
	@for i in $$(seq 1 60); do \
		if curl -fsS http://localhost:8080/healthz >/dev/null 2>&1; then echo "site is up"; exit 0; fi; \
		sleep 2; \
	done; echo "site failed healthcheck"; exit 1

reset: down ## Wipe local state and rebuild from scratch
	docker compose build --no-cache site

clean: down ## Tear everything down + remove the dev image
	docker rmi $(IMAGE) 2>/dev/null || true

snapshot: ## DEPRECATED — use `fp snapshot` from the host instead. See README.
	@echo "Note: \`make snapshot\` is deprecated. The replacement is the host-side"
	@echo "      \`fp snapshot\` CLI:"
	@echo ""
	@echo "        brew install frankenpress/tap/fp"
	@echo "        fp snapshot"
	@echo ""
	@echo "      It captures + extracts the snapshot in one step (no quoting"
	@echo "      traps, no separate docker-cp, prompts for slug + note)."
	@echo "      See https://docs.frankenpress.com/designer-flow for the full"
	@echo "      designer workflow. This target will be removed in a future"
	@echo "      site-template release."
	@exit 1
