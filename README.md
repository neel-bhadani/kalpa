# Kalpa Studio

All-in-one AI creative studio. See `CLAUDE.md` for the design and rules.

## Setup

Needs Docker with Compose (context `default`). Everything runs in containers.

```sh
cp .env.example .env                  # set UID/GID to `id -u` / `id -g` if not 1000
cp apps/api/.env.example apps/api/.env
make up                               # first start installs composer and npm packages
docker compose exec api php artisan key:generate
docker compose exec api php artisan migrate --seed   # seed: model registry and a test user
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
| `make web-build` | Production build of the web app (`nuxi generate`); stops with the build error if it fails or runs over 10 minutes |
| `make web-preview` / `make web-dev` | Build and serve the built app (works offline after the first visit) / back to the dev server |
| `make typecheck` | Type-check the web app and the packages it imports (clears leftover build output first) |
| `make fresh` | `migrate:fresh` on the dev database |
| `make e2e` | Browser tests (Playwright, `kalpa-e2e`): builds and serves the web app (stops at once if the build fails), makes test users, runs `e2e/tests`, then goes back to the dev server |
| `make ai-up` / `make ai-down` / `make ai-pull MODEL=…` | Optional local Ollama (`kalpa-ollama`, 3 GB limit) |

## Offline and sync

Tasks, projects and asset metadata are cached per device in IndexedDB, and edits are queued while offline
(header pill: Synced / Syncing / Offline). To try a second device on this machine, open
http://127.0.0.1:8089 next to http://localhost:8089: different origins keep separate storage.
Offline reloads need the built app (`make web-preview`); the dev server can't work offline.

## End-to-end tests

Run `make ai-down` before `make e2e` on low-RAM machines: the build and the browser need the memory a
loaded Ollama model takes.

`make e2e` runs the Playwright tests in `e2e/tests` against the running stack (`make up` first). The
`kalpa-e2e` service is in the `e2e` compose profile, so `make up` never starts it. Each test uses two
browser contexts as two devices. `php artisan e2e:fixtures` (local only) makes an owner, a viewer in the
owner's workspace and a user in another workspace, with a random password written to
`e2e/.fixtures.json` (git-ignored).

## Dev tools

The fake brain (assistant "demo"), the `demo.count` job, "Run demo job" and "Plan with Producer (demo)"
exist only when `APP_ENV=local` (and in tests). Elsewhere the API hides or refuses them, and `/api/v1/me`
reports `features.dev_tools: false`, so the web app hides their buttons.
