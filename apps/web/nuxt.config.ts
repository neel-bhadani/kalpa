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
  modules: ['@pinia/nuxt'],
  css: ['~/assets/css/main.css'],
  alias: {
    '@kalpa/core': `${packagesDir}/core/src`,
  },
  typescript: {
    // Let files in packages/ find the types of packages installed here (paths are relative to .nuxt/).
    tsConfig: {
      compilerOptions: {
        paths: { 'vue': ['../node_modules/vue'], 'hash-wasm': ['../node_modules/hash-wasm'] },
      },
    },
  },
  vite: {
    plugins: [tailwindcss()],
    // Let the dev server read the shared packages outside apps/web.
    server: { fs: { allow: [packagesDir] } },
    // Files in packages/ import these too; resolve them from apps/web/node_modules.
    resolve: { dedupe: ['vue', 'hash-wasm'] },
  },
})
