# Kalpa Studio

An all-in-one AI creative studio: story and script, characters (2D, 3D, realistic), 3D assets, CGI and VFX, voice, music and songs, video editing and upscaling, ads, a player, and import/export. Each module works alone or together through AI assistants. Web first, then desktop (Tauri), then mobile (Capacitor), all on one shared core.

Full design: https://claude.ai/code/artifact/10ea28ab-05fa-4f70-ac42-2c36fb1f9d0a

Design summary (modules, tables, phases): docs/design-summary.md — read it when a step touches a module, table or phase you don't know yet.

## Core ideas

- **Zero budget.** Only open-source tools, models and data whose licence allows commercial use. Record every model, asset and dataset in `docs/licences.md`. Never use MusicGen weights, F5-TTS or XTTS (non-commercial).
- **Device first.** Heavy work runs on the user's device (browser WebGL2/WebGPU/WASM, Web Workers) or on a local worker the user runs. The server stores, syncs, queues and does small conversions. It never needs a GPU.
- **One core, many modules.** Core = accounts, projects, tasks, assets, files, jobs, sync, AI runtime. Each module (story, character, assets3d, cgi, voice, music, video, ads, player, io) plugs in through one manifest. The core never imports module code directly.
- **Everything is an asset.** One `assets` table for every kind, with append-only `asset_versions` (spec as jsonb, changes as JSON Patch) and content-addressed `files` (SHA-256).
- **AI decides, code builds.** Assistants call the same module commands the UI uses. Every AI action is an `ai_steps` row the user can see, edit and undo.
- **Nothing lost.** Editors save checkpoints locally (IndexedDB) and sync in the background. Every task resumes where it stopped.
- **Light on resources.** Lazy-load modules, unload unused models, stream media, use Web Workers, never run idle render loops.

## Stack

| Part | Choice |
| --- | --- |
| API | Laravel 13, PHP 8.5 (FrankenPHP image), Sanctum |
| Queues / realtime | Redis queue + Horizon, Reverb (added in P0 step 7) |
| Web | Nuxt 4, Vue 3, TypeScript, Pinia, Tailwind CSS 4 |
| Node | 24 LTS |
| Database | PostgreSQL 18, Redis 8 |
| Files | S3 API: SeaweedFS locally (added in P0 step 6) |
| Gateway | Caddy 2 on port 8089 (Apache uses port 80, another project uses 8088 on this machine) |
| Tests | Pest (API, on PostgreSQL), Vitest (TS), Playwright (e2e, later) |
| Dev | Docker Compose, Makefile |

## Repo layout

```
kalpa/
├── compose.yaml, Makefile, .env.example
├── docker/{caddy,api,web}/
├── apps/
│   ├── api/          Laravel — app/Core/* and app/Modules/<Module>/*
│   ├── web/          Nuxt shell — dashboard, sidebar, settings; loads module editors lazily
│   └── worker/       Python local worker (later)
├── packages/
│   ├── core/         TS: API client, stores, sync, event bus, job queue, model manager, device profile
│   ├── platform/     web / desktop / mobile adapters
│   ├── schemas/      JSON Schemas per asset kind + generated types
│   ├── ai-runtime/   assistant loop, tools, steps, providers
│   ├── ui/           shared Vue components and design tokens
│   └── modules/      story/ character/ assets3d/ cgi/ voice/ music/ video/ ads/ player/ io/
└── docs/             ADRs, licences.md, credits.md
```

Services are prefixed `kalpa-`. Local URL: `http://localhost:8089`.

## Rules

- Everything runs in Docker. Run artisan, composer, npm and tests inside containers (`docker compose exec api ...`), never on the host. Docker context must be `default`.
- Tests use PostgreSQL, never SQLite.
- UUIDv7 primary keys. Timestamps on every table; soft deletes on user-facing tables. Text enums with CHECK constraints, mirrored as PHP backed enums.
- Tables marked append-only (`*_versions`, `task_events`, `job_logs`, `change_log`, `audit_log`) reject UPDATE, DELETE and TRUNCATE through one shared trigger function.
- Every workspace-owned table has `workspace_id`. Every foreign key is indexed.
- API routes live under `/api/v1`. Long work returns `202` with a job id and reports progress over Reverb.
- Business logic goes in `app/Actions` (one use case per class), not in controllers.
- The web app is a client-only SPA (ssr: false) so the same build runs in Tauri and Capacitor.
- The API never handles large file bytes: browsers and workers use signed URLs to storage.
- Every AI or heavy job goes through a `jobs` row with an idempotency key.
- Don't add packages, services or tables beyond the current step without asking. For any new model, asset or dataset, state its licence first.
- Never commit `.env` files. Never run `docker volume prune`.

## Developer machine (keep it light)

- Intel i5-9400F (6 cores, no integrated GPU), 16 GB RAM (about 6 GB free while working), NVIDIA GT 710 (2 GB).
- Target WebGL2; don't assume WebGPU or CUDA locally.
- Give every container a memory limit in `compose.yaml`; the whole stack should stay under about 3 GB.
- Local AI that runs here: small LLMs (Qwen3 0.6B–4B, 4-bit) on CPU, Kokoro/Piper voices, whisper.cpp small. Not here: Chatterbox cloning, ACE-Step songs, Real-ESRGAN video, TRELLIS — those need a GPU worker.

## Working style

- Work one step at a time. Finish the step, run it, show me how to check it, then stop.
- Explain what you changed and why in a few plain lines. I review every change.
- Keep code simple and readable. No clever abstractions before they're needed.

## Phases

P0 Core platform (current) → P1 Story & Script + AI runtime → P2 Character → P3 Voice + local worker → P4 3D Assets + Import/Export → P5 Music → P6 Video + Player → P7 CGI & VFX → P8 Ads → P9 Desktop → P10 Mobile.

### P0 steps

1. Docker stack + empty Laravel and Nuxt apps + health check + Makefile + tests running.
2. Auth (Sanctum cookie for web, tokens for apps), users, workspaces, members; personal workspace on sign-up; login and register pages.
3. Shared DB conventions: UUIDv7, append-only trigger, enum helper; `user_devices` + device capability profile from the browser.
4. Projects, tasks (8 statuses, 3 modes), task_events, task_dependencies, task_outputs, project_memory + API.
5. Module manifest contract + registry; dashboard UI from the sketch (sidebar, task rows with status and project, filters).
6. Assets, asset_versions (JSON Patch), files (SHA-256), asset_files, file_derivatives, asset_links, project_assets; SeaweedFS + chunked signed uploads.
7. Jobs, job_logs, compute_workers; Horizon + Reverb; live task and job updates; browser-side job queue.
8. Task checkpoints, offline drafts in IndexedDB, change_log sync; resume a task on another device.
9. model_registry + AI runtime skeleton (ai_sessions, ai_messages, ai_steps, ai_handoffs) with a fake brain; visible steps panel.
10. P0 check: end-to-end test — create a task, edit, close, reopen in another browser, resume.

## UI look

Dark and calm. Background `#07080C`, panels `#0F1118`, text `#ECEEF5`, muted `#8A90A6`, accent violet `#8B7CFF` (primary actions, assistants), cyan `#45E3FF` (media, progress). Fonts: Inter (UI), JetBrains Mono (timecodes, ids).
