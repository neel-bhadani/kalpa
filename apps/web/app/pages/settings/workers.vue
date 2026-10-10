<script setup lang="ts">
import type { Worker } from '~/types/api'

// Where my jobs can run: this and other browsers, and paired local workers.
const api = useApi()
const errors = useFormErrors()

const workers = ref<Worker[]>([])
const pairing = ref<{ code: string; expires_at: string } | null>(null)

async function load() {
  workers.value = (await api<{ data: Worker[] }>('/api/v1/workers')).data
}

async function createCode() {
  errors.clear()
  try {
    pairing.value = (await api<{ data: { code: string; expires_at: string } }>('/api/v1/workers/pairing-codes', { method: 'POST' })).data
  } catch (error) {
    errors.show(error)
  }
}

async function remove(worker: Worker) {
  errors.clear()
  try {
    await api(`/api/v1/workers/${worker.id}`, { method: 'DELETE' })
    workers.value = workers.value.filter(item => item.id !== worker.id)
  } catch (error) {
    errors.show(error)
  }
}

const origin = computed(() => (import.meta.client ? location.origin : ''))

await load()
</script>

<template>
  <main class="mx-auto max-w-4xl p-6">
    <h1 class="text-2xl font-semibold">Settings</h1>
    <SettingsTabs class="mt-4" />

    <p class="mt-6 text-sm text-muted">
      Your workers run jobs for you. Each browser tab counts as one; a local worker (P3) is paired with a one-time code.
    </p>
    <p v-if="errors.message.value" class="mt-2 text-sm text-red-400">{{ errors.message.value }}</p>

    <ul class="mt-4 divide-y divide-muted/15 rounded-xl bg-panel">
      <li v-for="worker in workers" :key="worker.id" class="flex flex-wrap items-center gap-3 px-4 py-3">
        <span class="h-2 w-2 rounded-full" :class="worker.status === 'online' ? 'bg-green-500' : 'bg-muted/50'" :title="worker.status" />
        <span class="min-w-0 flex-1 truncate">{{ worker.name }}</span>
        <span class="text-xs text-muted">{{ worker.kind }}</span>
        <span class="font-mono text-xs text-muted">{{ worker.capabilities.join(', ') || '—' }}</span>
        <span class="w-24 text-right font-mono text-xs text-muted">{{ timeAgo(worker.last_heartbeat_at) }}</span>
        <button class="text-xs text-red-400 hover:underline" @click="remove(worker)">Remove</button>
      </li>
      <li v-if="!workers.length" class="px-4 py-8 text-center text-sm text-muted">No workers yet.</li>
    </ul>

    <section class="mt-6 rounded-xl bg-panel p-4">
      <h2 class="font-medium">Pair a local worker</h2>
      <p class="mt-1 text-sm text-muted">Create a code, then give it to the worker within 10 minutes.</p>
      <button class="mt-3 rounded-lg bg-accent px-3 py-1.5 text-sm font-medium text-bg hover:opacity-90" @click="createCode">Create pairing code</button>
      <div v-if="pairing" class="mt-4">
        <p class="font-mono text-2xl tracking-widest text-media" data-test="pairing-code">{{ pairing.code }}</p>
        <p class="mt-1 text-xs text-muted">Valid until {{ new Date(pairing.expires_at).toLocaleTimeString() }}. The worker sends:</p>
        <pre class="mt-2 overflow-x-auto rounded-lg bg-bg p-3 font-mono text-xs">POST {{ origin }}/api/v1/workers/pair
{"code": "{{ pairing.code }}", "name": "My PC", "kind": "local", "capabilities": ["cpu"]}</pre>
      </div>
    </section>
  </main>
</template>
