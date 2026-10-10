.PHONY: up down logs ps sh-api sh-web test test-api test-web module-keys typecheck fresh web-preview web-dev e2e

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

typecheck:
	docker compose exec web npx nuxi typecheck

fresh:
	docker compose exec api php artisan migrate:fresh

# Build the web app and serve the build (offline app shell, like production). Back with make web-dev.
web-preview:
	KALPA_WEB_MODE=preview docker compose up -d web

web-dev:
	KALPA_WEB_MODE=dev docker compose up -d web

# End-to-end tests in a real browser (Playwright). Builds and serves the web app (offline needs the
# service worker), makes test users, runs the tests, then goes back to the dev server.
e2e:
	KALPA_WEB_MODE=preview docker compose up -d web
	@echo "Waiting for the web build…"
	@for i in $$(seq 1 120); do curl -sf http://localhost:8089/sw.js 2>/dev/null | grep -q precache && break; sleep 3; done
	docker compose exec -T api php artisan e2e:fixtures > e2e/.fixtures.json
	docker compose --profile e2e run --rm e2e; status=$$?; \
	KALPA_WEB_MODE=dev docker compose up -d web; exit $$status
