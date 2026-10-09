<script setup lang="ts">
definePageMeta({ layout: false })

type Health = { app: string; db: string; redis: string }

// /api is routed to Laravel by Caddy.
const { data, error, pending, refresh } = await useFetch<Health>('/api/v1/health', {
  server: false,
})

const services = ['app', 'db', 'redis'] as const

function isUp(name: (typeof services)[number]): boolean {
  return data.value?.[name] === 'ok'
}
</script>

<template>
  <main class="min-h-screen flex items-center justify-center p-6">
    <section class="w-full max-w-sm rounded-xl bg-panel p-6">
      <h1 class="text-2xl font-semibold">Kalpa Studio</h1>
      <p class="mt-1 text-sm text-muted">Service health</p>

      <ul class="mt-6 space-y-3">
        <li v-for="name in services" :key="name" class="flex items-center justify-between">
          <span class="font-mono text-sm">{{ name }}</span>
          <span class="flex items-center gap-2 text-sm">
            <span
              class="size-2.5 rounded-full"
              :class="pending ? 'bg-muted' : isUp(name) ? 'bg-green-500' : 'bg-red-500'"
            />
            {{ pending ? 'checking' : isUp(name) ? 'ok' : 'down' }}
          </span>
        </li>
      </ul>

      <p v-if="error" class="mt-4 text-sm text-red-400">API not reachable.</p>

      <button
        class="mt-6 w-full rounded-lg bg-accent px-4 py-2 text-sm font-medium text-bg hover:opacity-90"
        @click="refresh()"
      >
        Check again
      </button>
    </section>
  </main>
</template>
