// https://nuxt.com/docs/api/configuration/nuxt-config
import tailwindcss from '@tailwindcss/vite'

const packagesDir = new URL('../../packages', import.meta.url).pathname

export default defineNuxtConfig({
  compatibilityDate: '2025-07-15',
  // Client-side app: auth uses the browser's session cookie, and the same build
  // will later run inside Tauri and Capacitor.
  ssr: false,
  devtools: { enabled: false },
  modules: ['@pinia/nuxt'],
  css: ['~/assets/css/main.css'],
  alias: {
    '@kalpa/core': `${packagesDir}/core/src`,
  },
  vite: {
    plugins: [tailwindcss()],
    // Let the dev server read the shared packages outside apps/web.
    server: { fs: { allow: [packagesDir] } },
  },
})
