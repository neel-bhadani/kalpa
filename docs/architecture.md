# Architecture (end of P0)

One page on how the pieces fit. The rules are in `CLAUDE.md`; modules, tables and phases are in `docs/design-summary.md`.

## Pieces

```
browser (Nuxt SPA, one build for web / Tauri / Capacitor)
  apps/web ── packages/core (API client, sync, uploads, browser jobs, module registry)
           ── packages/ai-runtime (assistant loop, brains, steps)
           ── packages/modules/<module> (manifest + editor, loaded lazily)
        │  HTTP /api/v1 (cookie or token)     │ websocket /app (Reverb)     │ signed URLs /kalpa/* (S3)
        ▼                                     ▼                             ▼
Caddy :8089 ──► kalpa-api (Laravel, FrankenPHP) ── PostgreSQL, Redis ── SeaweedFS
                kalpa-horizon (queue), kalpa-scheduler, kalpa-reverb
local worker (later, P3) ── pairs once (/api/v1/workers/pair), then claims jobs with its worker token
```

## Core

- **Accounts and workspaces.** Sanctum: session cookie for the web app, tokens for apps and workers. Every
  workspace-owned row has `workspace_id`. The `workspace` middleware reads `X-Workspace-Id`, checks
  membership, and policies check the role (owner, editor, viewer).
- **Projects and tasks.** Tasks have 8 statuses and 3 modes. Each status change writes an append-only
  `task_events` row. A task's `checkpoint` (jsonb: `view` + `data`) is what the editor shows; it has a
  `row_version` for optimistic locking.
- **Assets.** One `assets` table for every kind. `asset_versions` are append-only (spec + JSON Patch from
  the previous version). `files` are content-addressed (SHA-256) and uploaded straight to S3 in chunks
  with signed URLs. The API never sees the bytes.
- **Use cases.** Each one is a class in `app/Actions`. Controllers only validate and call them.

## Modules

Each module has a manifest on both sides: `app/Modules/<Module>` (API: key, kinds, job types) and
`packages/modules/<module>/manifest.ts` (web: name, icon, commands, editor). The registries load the
manifests. The core never imports module code. An editor is loaded only when a task of its module is
opened. Module **commands** (e.g. `view.selectTab`, `notes.append`) are pure functions on a checkpoint.
The editor's buttons and the assistant call the same commands.

## Jobs

All heavy or AI work is a `jobs` row with an idempotency key. `POST` returns `202` and the job id.

1. A runner claims the job: Horizon for server jobs (small conversions such as `file.thumbnail`), the
   browser's job queue (Web Worker), or a paired local worker.
2. It reports a heartbeat and progress; each line goes to `job_logs` (append-only).
3. It finishes or fails. A job whose lease expires is put back in the queue.
4. Progress and results go out over Reverb on the workspace channel, and the task row and the bell update
   live.

## Sync (nothing lost)

```
editor change ─► IndexedDB local copy (at once) ─► outbox op (2 s after the last change, or on hide)
     ─► POST /sync (op_id, base_row_version) ─► ApplySyncOp ─► change_log (seq) ─► Reverb ─► other devices
                                                                                   └─► GET /sync?after=seq
```

- Every write goes through `ChangeRecorder` into `change_log` (append-only; per-workspace `seq`). Devices
  pull everything after their last `seq`.
- `sync_ops` remembers each (device, op_id) result, so a retried op is never applied twice.
- An outbox entry is removed only by the exact `op_id` the server acknowledged. A newer edit never merges
  into an op that was already sent; it gets a new `op_id`. A stale `base_row_version` gives a
  **conflict**: the user picks "Keep mine" or "Use theirs".
- Reopening a task loads the local copy when it has unsent edits, otherwise the server copy. That is how
  a task resumes on another device.

## AI runtime

```
user message ─► AssistantRunner ─► Brain (FakeBrain in dev; real models from P1) ─► text + tool calls
   each tool call ─► ai_steps row ─► module command on the checkpoint ─► before/after kept ─► Undo
```

- Sessions, messages, steps and handoffs are rows (`ai_sessions`, `ai_messages`, `ai_steps`,
  `ai_handoffs`). The user sees every step in the steps panel. Other devices see them live.
- Modes:
  - **Auto** runs each step as soon as it is created, one at a time.
  - **Semi** waits for Approve or Reject.
  - **Manual** only suggests steps.
- `model_registry` lists models with their licence. Dev-only entries (fake brain, `demo.count`, the
  Producer demo plan) exist only when `APP_ENV=local`.

## Main data flow (one edit, two devices)

1. Device A types in the editor. The checkpoint is saved to IndexedDB at once, then queued in the outbox.
2. The outbox sends the op. `ApplySyncOp` checks the row version, saves the checkpoint, and writes
   `change_log` and `sync_ops` in one transaction.
3. Reverb tells the workspace channel. Device B pulls the change and updates its open editor.
4. If A was offline, steps 2–3 happen when it reconnects. A reload while offline uses the service worker's
   app shell and the IndexedDB copy.
