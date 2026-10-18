# Licences

Every model, asset, dataset and third-party library chosen for Kalpa Studio (framework defaults aside). Only licences that allow commercial use.

| Name | Kind | Version | Licence | Commercial use | Source | Used by |
| --- | --- | --- | --- | --- | --- | --- |
| swaggest/json-diff | PHP library (JSON Patch, RFC 6902) | 3.12 | MIT | Yes | https://github.com/swaggest/json-diff | API: project memory patches |
| @lucide/vue | JS library (icons; the renamed lucide-vue-next) | 1.54 | ISC | Yes | https://github.com/lucide-icons/lucide | Web: sidebar, dashboard, module icons |
| chrislusf/seaweedfs | Service (S3-compatible object storage, Docker image) | 4.48 | Apache-2.0 | Yes | https://github.com/seaweedfs/seaweedfs | kalpa-seaweedfs: file storage |
| league/flysystem-aws-s3-v3 | PHP library (S3 filesystem adapter) | 3.35 | MIT | Yes | https://github.com/thephpleague/flysystem-aws-s3-v3 | API: s3 disk |
| aws/aws-sdk-php | PHP library (S3 client; brought by the adapter, with aws/aws-crt-php Apache-2.0 and mtdowling/jmespath.php MIT) | 3.400 | Apache-2.0 | Yes | https://github.com/aws/aws-sdk-php | API: multipart uploads, presigned URLs |
| hash-wasm | JS library (SHA-256 in WebAssembly) | 4.12 | MIT | Yes | https://github.com/Daninet/hash-wasm | Web: hashing files in a Web Worker before upload |
| opis/json-schema | PHP library (JSON Schema validation, draft 2020-12; brings opis/string and opis/uri, both Apache-2.0) | 2.6 | Apache-2.0 | Yes | https://github.com/opis/json-schema | API: validating asset specs against packages/schemas |
| laravel/horizon | PHP library (Redis queue workers + dashboard; brings laravel/sentinel, MIT) | 5.50 | MIT | Yes | https://github.com/laravel/horizon | API: kalpa-horizon, server jobs and broadcasts |
| laravel/reverb | PHP library (websocket server; brings pusher/pusher-php-server, react/*, ratchet/rfc6455, clue/redis-*, evenement, all MIT) | 1.12 | MIT | Yes | https://github.com/laravel/reverb | kalpa-reverb: live updates |
| PHP GD extension (with libwebp) | PHP extension in the api image | bundled | PHP-3.01 / libgd (BSD-style), libwebp BSD-3-Clause | Yes | https://www.php.net/manual/en/book.image.php | API: file.thumbnail |
| laravel-echo | JS library (websocket client for Laravel broadcasting) | 2.6 | MIT | Yes | https://github.com/laravel/echo | Web: live task, job and notification updates |
| pusher-js | JS library (Pusher protocol client, used by Echo for Reverb) | 8.6 | MIT | Yes | https://github.com/pusher/pusher-js | Web: websocket connection |
| idb | JS library (promise wrapper for IndexedDB) | 8.0 | ISC | Yes | https://github.com/jakearchibald/idb | Web: offline cache and outbox (packages/core/src/sync) |
| fake-indexeddb | JS library (IndexedDB for tests; dev only) | 6.2 | Apache-2.0 | Yes | https://github.com/dumbmatter/fakeIndexedDB | Vitest: sync tests |
| @vite-pwa/nuxt | JS library (service worker / offline app shell; brings vite-plugin-pwa and Workbox, both MIT; build time only) | 1.1 | MIT | Yes | https://github.com/vite-pwa/nuxt | Web: offline app shell |
| fast-check | JS library (property-based tests; dev only) | 4.10 | MIT | Yes | https://github.com/dubzzz/fast-check | Vitest: outbox safety property |
| @playwright/test + mcr.microsoft.com/playwright | JS library and Docker image (browser e2e tests; dev only; image bundles Chromium, BSD-3-Clause) | 1.64 | Apache-2.0 | Yes | https://github.com/microsoft/playwright | kalpa-e2e: make e2e |
| @wllama/wllama | JS library (llama.cpp compiled to WebAssembly, runs GGUF models on the CPU in its own Web Worker; includes llama.cpp, MIT) | 3.8.1 | MIT | Yes | https://github.com/ngxson/wllama | Web: on-device assistant brain (WllamaBrain). On browsers without JSPI/Memory64 (Safari) it loads its compat build from jsDelivr (@wllama/wllama-compat, MIT) |
| @mlc-ai/web-llm | JS library (runs MLC model builds on WebGPU; brings loglevel, MIT) | 0.2.86 | Apache-2.0 | Yes | https://github.com/mlc-ai/web-llm | Web: on-device assistant brain on WebGPU devices (WebLLMBrain). Loads each model's WebGPU kernel (.wasm) from github.com/mlc-ai/binary-mlc-llm-libs (Apache-2.0) |
| ollama/ollama | Service (local LLM server, Docker image; optional, compose profile "ai") | 0.40.6 | MIT | Yes | https://github.com/ollama/ollama | kalpa-ollama: Local Ollama provider |

## Models

Every model in `model_registry` (seeded from `apps/api/config/models.php`, same licence text).
Mark: **registered, not used yet** until a phase wires the model; then **in use**. A model without
commercial use can't be switched on (database CHECK: enabled implies commercial_ok).

| Model | Capability | Licence | Commercial use | Source | Mark |
| --- | --- | --- | --- | --- | --- |
| fake-brain | llm | Kalpa Studio code (dev and tests only) | Yes | this repo | in use (dev) |
| qwen3-0.6b | llm | Apache-2.0 | Yes | https://huggingface.co/Qwen/Qwen3-0.6B | in use (assistant). Builds: GGUF unsloth/Qwen3-0.6B-GGUF Qwen3-0.6B-Q4_K_M.gguf (Apache-2.0, 396,705,472 bytes, sha256 ac2d9771…524a); MLC mlc-ai/Qwen3-0.6B-q4f16_1-MLC (no licence stated on the repo; a conversion of the Apache-2.0 weights); Ollama qwen3:0.6b (Apache-2.0) |
| qwen3-1.7b | llm | Apache-2.0 | Yes | https://huggingface.co/Qwen/Qwen3-1.7B | in use (assistant). Builds: GGUF unsloth/Qwen3-1.7B-GGUF Qwen3-1.7B-Q4_K_M.gguf (Apache-2.0, 1,107,409,472 bytes, sha256 b139949c…1897); MLC mlc-ai/Qwen3-1.7B-q4f16_1-MLC (no licence stated on the repo; a conversion of the Apache-2.0 weights); Ollama qwen3:1.7b (Apache-2.0) |
| qwen3-4b | llm | Apache-2.0 | Yes | https://huggingface.co/Qwen/Qwen3-4B | registered, not used yet |
| kokoro-82m | tts | Apache-2.0 | Yes | https://huggingface.co/hexgrad/Kokoro-82M | registered, not used yet |
| piper | tts | unclear: original rhasspy/piper (MIT) archived Oct 2025; successor piper1-gpl is GPL-3.0; each voice has its own licence | No (on hold; Kokoro for English, Chatterbox for other languages) | https://github.com/rhasspy/piper | registered, not used yet |
| chatterbox-multilingual | voice_clone | MIT | Yes | https://github.com/resemble-ai/chatterbox | registered, not used yet |
| ace-step-1.5 | music | MIT (code and weights; model card: generated music may be used commercially) | Yes | https://github.com/ace-step/ACE-Step | registered, not used yet |
| real-esrgan-x4plus | upscale | BSD-3-Clause | Yes | https://github.com/xinntao/Real-ESRGAN | registered, not used yet |
| whisper-small | stt | MIT | Yes | https://huggingface.co/openai/whisper-small | registered, not used yet |
| sam2 | segment | Apache-2.0 | Yes | https://github.com/facebookresearch/sam2 | registered, not used yet |
| depth-anything-v2-small | depth | Apache-2.0 (Small only; Base and Large are CC-BY-NC) | Yes | https://huggingface.co/depth-anything/Depth-Anything-V2-Small | registered, not used yet |
| flux1-schnell | image | Apache-2.0 (schnell only; FLUX.1 [dev] is non-commercial) | Yes | https://huggingface.co/black-forest-labs/FLUX.1-schnell | registered, not used yet |
| trellis | asset3d | unclear: models MIT, but needs nvdiffrast (NVIDIA Source Code License, non-commercial only) and diffoctreerast (derived from diff-gaussian-rasterization) | No (on hold until P4 checks alternatives) | https://github.com/microsoft/TRELLIS | registered, not used yet |
