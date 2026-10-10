<script setup lang="ts">
// Settings → AI: the models Kalpa knows (and which fit this device), and my own provider keys.
type Model = {
  key: string
  display_name: string
  capability: string
  licence: string
  commercial_ok: boolean
  size_mb: number
  min_tier: string
  targets: string[]
  enabled: boolean
  fits_device: boolean | null
}
type Provider = { id: string; provider: string; name: string; base_url: string; api_key_hint: string | null; models: string[]; enabled: boolean }

const api = useApi()
const errors = useFormErrors()

const models = ref<Model[]>([])
const providers = ref<Provider[]>([])
const form = reactive({ provider: 'openai_compatible', name: '', base_url: '', api_key: '', models: '' })

async function load() {
  const [modelList, providerList] = await Promise.all([
    api<{ data: Model[] }>('/api/v1/models'),
    api<{ data: Provider[] }>('/api/v1/ai/providers'),
  ])
  models.value = modelList.data
  providers.value = providerList.data
}

async function addProvider() {
  errors.clear()
  try {
    const { data } = await api<{ data: Provider }>('/api/v1/ai/providers', {
      method: 'POST',
      body: { ...form, models: form.models.split(',').map(model => model.trim()).filter(Boolean) },
    })
    providers.value = [...providers.value, data]
    Object.assign(form, { name: '', base_url: '', api_key: '', models: '' })
  } catch (error) {
    errors.show(error)
  }
}

async function removeProvider(provider: Provider) {
  await api(`/api/v1/ai/providers/${provider.id}`, { method: 'DELETE' })
  providers.value = providers.value.filter(item => item.id !== provider.id)
}

function size(mb: number) {
  return mb >= 1000 ? `${(mb / 1000).toFixed(1)} GB` : `${mb} MB`
}

await load()
</script>

<template>
  <main class="mx-auto max-w-6xl p-6">
    <h1 class="text-2xl font-semibold">Settings</h1>
    <SettingsTabs class="mt-4" />

    <h2 class="mt-6 font-medium">Models</h2>
    <p class="mt-1 text-sm text-muted">Open models Kalpa can use. Each is switched on in the phase that wires it; "Fits" compares it with this device.</p>

    <div class="mt-3 overflow-x-auto rounded-xl bg-panel">
      <table class="w-full text-left text-sm">
        <thead class="text-muted">
          <tr>
            <th class="px-3 py-2 font-normal">Model</th>
            <th class="px-3 py-2 font-normal">Capability</th>
            <th class="px-3 py-2 font-normal">Licence</th>
            <th class="px-3 py-2 font-normal">Size</th>
            <th class="px-3 py-2 font-normal">Runs on</th>
            <th class="px-3 py-2 font-normal">This device</th>
            <th class="px-3 py-2 font-normal">Status</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="model in models" :key="model.key" class="border-t border-muted/15 align-top" data-test="model-row">
            <td class="px-3 py-2">
              {{ model.display_name }}
              <span class="block font-mono text-[11px] text-muted">{{ model.key }}</span>
            </td>
            <td class="px-3 py-2 text-muted">{{ model.capability }}</td>
            <td class="max-w-72 px-3 py-2 text-xs" :class="model.commercial_ok ? 'text-muted' : 'text-amber-300'">{{ model.licence }}</td>
            <td class="px-3 py-2 font-mono text-xs text-muted">{{ size(model.size_mb) }}</td>
            <td class="px-3 py-2 text-xs text-muted">{{ model.targets.join(', ') }} · {{ model.min_tier }}+</td>
            <td class="px-3 py-2 text-xs">
              <span v-if="model.fits_device === null" class="text-muted">—</span>
              <span v-else-if="model.fits_device" class="text-green-400">Fits</span>
              <span v-else class="text-muted">Needs a worker</span>
            </td>
            <td class="px-3 py-2 text-xs">
              <span v-if="model.enabled" class="text-green-400">On</span>
              <span v-else-if="!model.commercial_ok" class="text-amber-300">On hold</span>
              <span v-else class="text-muted">Later phase</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <h2 class="mt-8 font-medium">Your providers</h2>
    <p class="mt-1 text-sm text-muted">Your own API endpoints (OpenAI-compatible or Ollama), used from P1. Keys are stored encrypted; only the last 4 characters are ever shown.</p>

    <ul class="mt-3 divide-y divide-muted/15 rounded-xl bg-panel">
      <li v-for="provider in providers" :key="provider.id" class="flex flex-wrap items-center gap-3 px-4 py-3 text-sm" data-test="provider-row">
        <span class="min-w-0 flex-1">{{ provider.name }} <span class="text-xs text-muted">{{ provider.provider }} · {{ provider.base_url }}</span></span>
        <span class="font-mono text-xs text-muted">{{ provider.api_key_hint ?? 'no key' }}</span>
        <button class="text-xs text-red-400 hover:underline" @click="removeProvider(provider)">Remove</button>
      </li>
      <li v-if="!providers.length" class="px-4 py-6 text-center text-sm text-muted">No providers yet.</li>
    </ul>

    <form class="mt-4 grid gap-3 rounded-xl bg-panel p-4 sm:grid-cols-2" @submit.prevent="addProvider">
      <label class="text-sm text-muted">Type
        <select v-model="form.provider" class="field">
          <option value="openai_compatible">OpenAI-compatible</option>
          <option value="ollama">Ollama</option>
        </select>
      </label>
      <label class="text-sm text-muted">Name <input v-model="form.name" required class="field"></label>
      <label class="text-sm text-muted">Base URL <input v-model="form.base_url" required type="url" placeholder="https://…/v1" class="field"></label>
      <label class="text-sm text-muted">API key <input v-model="form.api_key" type="password" autocomplete="off" class="field"></label>
      <label class="text-sm text-muted sm:col-span-2">Models (comma-separated) <input v-model="form.models" class="field"></label>
      <p v-for="(message, field) in errors.fields.value" :key="field" class="text-sm text-red-400 sm:col-span-2">{{ message }}</p>
      <div class="sm:col-span-2">
        <button class="rounded-lg bg-accent px-4 py-2 text-sm font-medium text-bg">Add provider</button>
      </div>
    </form>
  </main>
</template>
