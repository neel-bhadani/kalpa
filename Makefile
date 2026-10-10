.PHONY: up down logs ps sh-api sh-web test test-api test-web module-keys typecheck fresh web-preview web-dev

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
