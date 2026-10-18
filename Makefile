.PHONY: up down logs ps sh-api sh-web test test-api test-web module-keys typecheck fresh web-build web-preview web-dev e2e wait-preview ai-up ai-down ai-pull

up:
	docker compose up -d --build

down:
	docker compose down

logs:
	docker compose logs -f --tail=100

ps:
	docker compose ps

sh-api:
	docker compose exec api bash

sh-web:
	docker compose exec web sh

test: test-api module-keys test-web

test-api:
	docker compose exec api php artisan test

# Module keys from the API, for the web registry test (packages/core/src/modules.test.ts).
module-keys:
	docker compose exec -T api php artisan modules:keys > packages/core/src/module-keys.json

test-web:
	docker compose exec web npx vitest run

# Clears leftover build output first (a stale build cache made vue-tsc fail without a message).
# Runs in its own container: vue-tsc and the dev server don't fit in one container's memory.
typecheck:
	docker compose run --rm --no-deps -T web sh scripts/typecheck.sh

fresh:
	docker compose exec api php artisan migrate:fresh

# Production build of the web app (nuxi generate → apps/web/.output/public). The dev server is stopped
# while it runs (both together don't fit in the container's memory). Stops with the build's own error if
# it fails or takes more than 10 minutes; the dev server comes back either way.
web-build:
	docker compose stop web
	@timeout 600 docker compose run --rm --no-deps -T web sh -c 'rm -rf .output && npx nuxi generate'; status=$$?; \
	if [ $$status -ne 0 ]; then \
		[ $$status -eq 124 ] && echo "The web build took more than 10 minutes and was stopped."; \
		echo "The web build failed (exit $$status). Going back to the dev server."; \
		KALPA_WEB_MODE=dev docker compose up -d web; exit 1; \
	fi

# Build the web app and serve the build (offline app shell, like production). Back with make web-dev.
web-preview: web-build
	KALPA_WEB_MODE=preview docker compose up -d web
	@$(MAKE) --no-print-directory wait-preview

web-dev:
	KALPA_WEB_MODE=dev docker compose up -d web

# The built app answers (its service worker is served), within a minute.
wait-preview:
	@for i in $$(seq 1 60); do curl -sf http://localhost:8089/sw.js 2>/dev/null | grep -q precache && exit 0; sleep 1; done; \
	echo "The built app did not start:"; docker compose logs --tail 20 web; KALPA_WEB_MODE=dev docker compose up -d web; exit 1

# End-to-end tests in a real browser (Playwright). Builds and serves the web app (offline needs the
# service worker), makes test users, runs the tests, then goes back to the dev server.
# On low-RAM machines run make ai-down first.
e2e: web-preview
	docker compose exec -T api php artisan e2e:fixtures > e2e/.fixtures.json
	docker compose --profile e2e run --rm e2e; status=$$?; \
	KALPA_WEB_MODE=dev docker compose up -d web; exit $$status

# Optional local LLM server (kalpa-ollama, compose profile "ai"; not started by make up).
ai-up:
	docker compose --profile ai up -d ollama

ai-down:
	docker compose --profile ai stop ollama

# Download a model into the ollama-models volume, e.g. make ai-pull MODEL=qwen3:1.7b
MODEL ?= qwen3:1.7b
ai-pull:
	docker compose --profile ai exec ollama ollama pull $(MODEL)
