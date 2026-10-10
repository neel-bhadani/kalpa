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
