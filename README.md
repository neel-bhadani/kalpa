# Kalpa Studio

All-in-one AI creative studio. See `CLAUDE.md` for the design and rules.

## Setup

Needs Docker with Compose (context `default`). Everything runs in containers.

```sh
cp .env.example .env                  # set UID/GID to `id -u` / `id -g` if not 1000
cp apps/api/.env.example apps/api/.env
make up                               # first start installs composer and npm packages
docker compose exec api php artisan key:generate
docker compose exec api php artisan migrate
make test
```

Open http://localhost:8089 (or the `KALPA_HTTP_PORT` you set in `.env`).
File storage is SeaweedFS (S3) behind the same address: the `kalpa` bucket is served at `/kalpa/*`,
so presigned URLs work from the browser without CORS. The api container creates the `kalpa` and
`kalpa-test` buckets when it starts (`php artisan kalpa:storage-setup`).

Background work runs in `kalpa-horizon` (Laravel queue), `kalpa-scheduler` (scheduled commands) and
`kalpa-reverb` (websockets, at `/app/*` on the same address). The Horizon dashboard is at `/horizon`
(local environment, workspace owners only). Set your own `REVERB_APP_*` values in `.env`.
The page shows green or red for app, db and redis. API health: `GET /api/v1/health`.

## Make targets

| Target | Does |
| --- | --- |
| `make up` / `make down` | Start / stop the stack |
| `make logs` / `make ps` | Follow logs / list services |
| `make sh-api` / `make sh-web` | Shell in the API / web container |
| `make test` | Run all tests: Pest (`make test-api`, database `kalpa_test`) and Vitest (`make test-web`) |
| `make module-keys` | Export the API's module keys to `packages/core/src/module-keys.json` (run by `make test`) |
| `make typecheck` | Type-check the web app and the packages it imports |
| `make fresh` | `migrate:fresh` on the dev database |
