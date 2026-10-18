// https://nuxt.com/docs/api/configuration/nuxt-config
import tailwindcss from '@tailwindcss/vite'

const packagesDir = new URL('../../packages', import.meta.url).pathname

export default defineNuxtConfig({
  compatibilityDate: '2025-07-15',
  // Client-side app: auth uses the browser's session cookie, and the same build
  // will later run inside Tauri and Capacitor.
  ssr: false,
  runtimeConfig: {
    public: {
      // Reverb's public app key (set NUXT_PUBLIC_REVERB_KEY; compose passes REVERB_APP_KEY).
      reverbKey: '',
    },
  },
  devtools: { enabled: false },
  modules: ['@pinia/nuxt', '@vite-pwa/nuxt'],
  // Offline app shell: a service worker precaches the built app (shell and every module chunk).
  // API calls are never cached by it; data comes from IndexedDB (packages/core/src/sync).
  // Only in builds (make web-preview); the dev server can't work offline anyway.
  pwa: {
    registerType: 'autoUpdate',
    manifest: {
      name: 'Kalpa Studio',
      short_name: 'Kalpa',
      theme_color: '#07080C',
      background_color: '#07080C',
      display: 'standalone',
    },
    workbox: {
      globPatterns: ['**/*.{js,css,html,svg,png,ico,woff2,webmanifest}'],
      // The app shell; @vite-pwa/nuxt precaches index.html as "/".
      navigateFallback: '/',
      // Never answer these from the app shell: API, auth, storage, websockets, Horizon.
      navigateFallbackDenylist: [/^\/api\//, /^\/sanctum\//, /^\/kalpa\//, /^\/app\//, /^\/horizon/],
      runtimeCaching: [],
    },
    // The model engines (WebLLM, about 6 MB; wllama.wasm is not matched by globPatterns) are over the
    // 2 MiB precache limit: leave them out with a warning, so the app shell stays small. They are
    // loaded only when a model runs.
    showMaximumFileSizeToCacheInBytesWarning: true,
    client: { installPrompt: false },
    devOptions: { enabled: false },
  },
  css: ['~/assets/css/main.css'],
  alias: {
    '@kalpa/core': `${packagesDir}/core/src`,
    '@kalpa/ai-runtime': `${packagesDir}/ai-runtime/src`,
  },
  typescript: {
    // Let files in packages/ find the types of packages installed here (paths are relative to .nuxt/).
    tsConfig: {
      compilerOptions: {
        paths: {
          'vue': ['../node_modules/vue'],
          'hash-wasm': ['../node_modules/hash-wasm'],
          'idb': ['../node_modules/idb'],
          '@wllama/wllama/*': ['../node_modules/@wllama/wllama/*'],
          '@mlc-ai/web-llm': ['../node_modules/@mlc-ai/web-llm'],
        },
      },
    },
  },
  vite: {
    plugins: [tailwindcss()],
    // Let the dev server read the shared packages outside apps/web.
    server: { fs: { allow: [packagesDir] } },
    // Files in packages/ import these too; resolve them from apps/web/node_modules.
    resolve: { dedupe: ['vue', 'hash-wasm', 'idb', '@wllama/wllama', '@mlc-ai/web-llm'] },
    // The model engines are large and loaded only when a model runs; don't pre-bundle them.
    optimizeDeps: { exclude: ['@wllama/wllama', '@mlc-ai/web-llm'] },
  },
})
