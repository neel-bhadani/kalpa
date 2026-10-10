# Kalpa Studio — System Design v1 (final vision, 2026-10-09)

Product name: **Kalpa Studio** (Sanskrit kalpa: imagination, creation). Repo: `kalpa`.
Supersedes the Cutwise, Castforge and Character & Asset Studio designs.
Full doc: https://claude.ai/code/artifact/10ea28ab-05fa-4f70-ac42-2c36fb1f9d0a

## Shape
- **Apps:** one shared TypeScript core under three apps, built in this order:
  1. Web: Nuxt 4 SPA/PWA.
  2. Desktop: Tauri 2 with a built-in local worker.
  3. Mobile: Capacitor.
- **Server:** Laravel 13, PostgreSQL, Redis, Reverb and SeaweedFS (S3). It needs no GPU.
- **10 modules,** each a plug-in with a manifest (asset kinds, task kinds, commands, assistant, job types, importers/exporters, routes, lazy editor):
  - Story & Script
  - Character
  - 3D Assets
  - CGI & VFX
  - Voice
  - Music & Songs
  - Video editor
  - Ads
  - Player
  - Import/Export
- **9 assistants:** one per creative module, plus a Producer that coordinates them. Every step is visible and can be undone.
- **3 modes per task:** fully automatic, semi-automatic, manual.

## CGI & VFX module (added 2026-10-10)
- **Scene:** a fully CG shot. Camera, lights, characters, objects, animation and particles are rendered to video.
- **VFX shot:** CG mixed into real footage.
  1. A `cgi.track` job solves the camera move and a depth pass.
  2. CG characters or objects are placed into the tracked view.
  3. Lighting is matched from an HDRI, with shadows on a ground plane.
  4. SAM 2 masks or green-screen keying separate people in front.
  5. A `cgi.render` job composites the result, which becomes a `video` asset.
- **Asset kinds:** `scene`, `vfx_shot`.
- **Engines:**
  - three.js preview
  - three-gpu-pathtracer (MIT)
  - COLMAP (BSD)
  - SAM 2 (Apache 2.0)
  - MediaPipe segmentation (Apache 2.0)
  - Depth Anything V2 Small (Apache 2.0; the larger versions are non-commercial)
  - Blender Cycles, run as a separate program on the worker (GPL)
- **Limit:** previews run in real time on any device. Realistic final renders need a GPU worker; on the GT 710 they are slow.

## Ads module
- **Flow:** a brief (or a template) → copy → a video ad plus image ads, built from the brand kit, Voice, Music, Video and CGI → A/B variants → every platform size exported in one job.
- **Tables:** brand_kits, ad_campaigns, ad_variants.
- **Not in v1:** publishing straight to ad accounts. v1 exports files.
- **Claims:** only from the brief.

## Dashboard (from the user's sketch)
- Task rows show module, task, status and project. Clicking a row resumes the task from its checkpoint.
- Statuses: draft, in_progress, waiting_job, needs_review, paused, failed, completed, archived.
- "Unsaved" is a flag, not a status.

## Compute routing (cheapest first)
1. This device
2. The user's local worker
3. A studio GPU pool (later)
4. The user's own API key

## Database (50 tables, 8 groups; * = append-only)
1. **Accounts:** users, workspaces, workspace_members, user_devices, personal_access_tokens
2. **Work:** projects, tasks, task_dependencies, task_outputs, task_events*, project_memory, project_memory_versions*
3. **Assets:**
   - assets, asset_versions*, files, asset_files, file_derivatives, asset_links, project_assets, asset_library
   - Also uploads, a table added in P0 step 6a.
4. **Story:** script_scenes, script_lines, character_relationships
5. **Media and ads:** voice_profiles, voice_consents, voice_takes, music_stems, renders, upscales, brand_kits, ad_campaigns, ad_variants, playback_positions, review_comments
6. **AI:** model_registry, ai_provider_configs, ai_sessions, ai_messages, ai_steps, ai_handoffs
7. **Compute:** compute_workers, jobs, job_logs*, imports, exports
8. **Platform:** notifications, change_log*, usage_counters, audit_log*, module_settings

## Phases
- P0 Core platform (in progress)
- P1 Story & Script + AI runtime
- P2 Character
- P3 Voice + local worker
- P4 3D Assets + Import/Export
- P5 Music
- P6 Video + Player
- P7 CGI & VFX
- P8 Ads
- P9 Desktop
- P10 Mobile

## Open questions
- Domain and trademark check for "Kalpa Studio"
- Access to a stronger GPU
- First voice languages
- Public product or personal tool
- Minimum target devices
