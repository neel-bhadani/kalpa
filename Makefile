.PHONY: up down logs ps sh-api sh-web test fresh

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

test:
	docker compose exec api php artisan test

fresh:
	docker compose exec api php artisan migrate:fresh
