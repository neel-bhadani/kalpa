.PHONY: up down logs ps sh-api sh-web test test-api test-web typecheck fresh

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

test: test-api test-web

test-api:
	docker compose exec api php artisan test

test-web:
	docker compose exec web npx vitest run

typecheck:
	docker compose exec web npx nuxi typecheck

fresh:
	docker compose exec api php artisan migrate:fresh
