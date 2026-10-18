<script setup lang="ts">
import { formatBytes, type CatalogModel } from '@kalpa/core'
import { tokensPerSecond } from '@kalpa/ai-runtime'

// Settings → AI: the models Kalpa knows (and which fit this device), the models downloaded to
// this device, and my own providers (with a one-click Local Ollama).
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
type DeviceRow = { model: CatalogModel; engine: 'wllama' | 'webllm'; bytes: number; complete: boolean }

const OLLAMA_URL = 'http://localhost:11434'

const api = useApi()
const errors = useFormErrors()
const brains = useBrainsStore()

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
    await brains.loadProviders()
  } catch (error) {
    errors.show(error)
  }
}

async function removeProvider(provider: Provider) {
  await api(`/api/v1/ai/providers/${provider.id}`, { method: 'DELETE' })
  providers.value = providers.value.filter(item => item.id !== provider.id)
  await brains.loadProviders()
}

// Local Ollama (make ai-up): the browser asks it which models it has, then saves it as a provider
// without a key, so the browser keeps calling it directly.
const ollamaError = ref('')
const hasOllama = computed(() => providers.value.some(provider => provider.base_url.startsWith(OLLAMA_URL)))

async function addOllama() {
  ollamaError.value = ''
  let names: string[]
  try {
    const tags = await $fetch<{ models: { name: string }[] }>(`${OLLAMA_URL}/api/tags`)
    names = tags.models.map(model => model.name).sort((a, b) => Number(b.startsWith('qwen3')) - Number(a.startsWith('qwen3')))
  } catch {
    ollamaError.value = `Kalpa can't reach Ollama at ${OLLAMA_URL}. Start it with make ai-up.`
    return
  }
  if (!names.length) {
    ollamaError.value = 'Ollama has no models yet. Get one with make ai-pull MODEL=qwen3:1.7b.'
    return
  }
  const { data } = await api<{ data: Provider }>('/api/v1/ai/providers', {
    method: 'POST',
    body: { provider: 'ollama', name: 'Local Ollama', base_url: `${OLLAMA_URL}/v1`, models: names },
  })
  providers.value = [...providers.value, data]
  await brains.loadProviders()
}

// Models on this device: what's downloaded, its size on disk, Delete, and a Test that streams a
// short reply and shows the speed.
const deviceRows = ref<DeviceRow[]>([])
const tests = reactive<Record<string, { text: string; speed: number | null; error: string; running: boolean }>>({})

async function loadDeviceRows() {
  deviceRows.value = await brains.onDevice()
}

async function downloadRow(row: DeviceRow) {
  await brains.download(row.model).catch(() => {})
  await loadDeviceRows()
}

async function deleteRow(row: DeviceRow) {
  try {
    await brains.remove(row.model, row.engine)
  } catch (error) {
    tests[rowId(row)] = { text: '', speed: null, running: false, error: error instanceof Error ? error.message : 'Could not delete it.' }
  }
  await loadDeviceRows()
}

async function testRow(row: DeviceRow) {
  const id = rowId(row)
  const test = { text: '', speed: null as number | null, error: '', running: true }
  tests[id] = test
  try {
    const brain = brains.brainFor({ kind: row.engine, model: row.model })!
    const started = performance.now()
    for await (const chunk of brain.chat({ messages: [{ role: 'user', content: 'Say hello and name one colour, in one short sentence.' }], tools: [] })) {
      if (chunk.type === 'text') tests[id]!.text += chunk.text
      if (chunk.type === 'usage') tests[id]!.speed = tokensPerSecond(chunk)
    }
    tests[id]!.text ||= `(no text after ${((performance.now() - started) / 1000).toFixed(1)} s)`
  } catch (error) {
    tests[id]!.error = error instanceof Error ? error.message : 'The test failed.'
  } finally {
    tests[id]!.running = false
  }
}

function rowId(row: DeviceRow) {
  return `${row.engine}:${row.model.key}`
}

function size(mb: number) {
  return mb >= 1000 ? `${(mb / 1000).toFixed(1)} GB` : `${mb} MB`
}

await load()
onMounted(() => loadDeviceRows().catch(() => {}))
</script>

<template>
  <main class="mx-auto max-w-6xl p-6">
    <h1 class="text-2xl font-semibold">Settings</h1>
    <SettingsTabs class="mt-4" />

    <h2 class="mt-6 font-medium">Models</h2>
    <p class="mt-1 text-sm text-muted">Open models Kalpa can use. Each is switched on in the phase that wires it; "Fits" compares it with this device. Sizes are the on-device download.</p>

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

    <h2 class="mt-8 font-medium">Models on this device</h2>
    <p class="mt-1 text-sm text-muted">
      Downloaded once into this browser's storage. The CPU builds run everywhere; the GPU builds need WebGPU.
      Memory for models on this device ({{ brains.tier }} tier): one model at a time, inside the budget.
    </p>
    <ul class="mt-3 divide-y divide-muted/15 rounded-xl bg-panel" data-test="device-models">
      <li v-for="row in deviceRows" :key="rowId(row)" class="px-4 py-3 text-sm" data-test="device-model">
        <div class="flex flex-wrap items-center gap-3">
          <span class="min-w-0 flex-1">{{ row.model.display_name }} <span class="text-xs text-muted">{{ row.engine === 'wllama' ? 'CPU · GGUF' : 'GPU · MLC' }}</span></span>
          <span class="font-mono text-xs text-muted">
            <template v-if="brains.downloads[row.model.key] && !brains.downloads[row.model.key]!.error">
              {{ brains.downloads[row.model.key]!.checking ? 'checking…' : `${formatBytes(brains.downloads[row.model.key]!.done)} / ${formatBytes(brains.downloads[row.model.key]!.total)}` }}
            </template>
            <template v-else-if="row.complete">{{ formatBytes(row.bytes) }} on disk</template>
            <template v-else-if="row.bytes">{{ formatBytes(row.bytes) }} of {{ formatBytes(row.engine === 'wllama' ? row.model.builds.wllama!.bytes : row.model.builds.webllm!.bytes) }} (paused)</template>
            <template v-else>not downloaded · {{ formatBytes(row.engine === 'wllama' ? row.model.builds.wllama!.bytes : row.model.builds.webllm!.bytes) }}</template>
          </span>
          <template v-if="row.complete">
            <button class="text-xs text-accent hover:underline disabled:opacity-50" :disabled="tests[rowId(row)]?.running" data-test="test-model" @click="testRow(row)">Test</button>
            <button class="text-xs text-red-400 hover:underline" data-test="delete-model" @click="deleteRow(row)">Delete</button>
          </template>
          <button v-else-if="row.engine === 'wllama' && (!brains.downloads[row.model.key] || brains.downloads[row.model.key]!.error)" class="text-xs text-accent hover:underline" data-test="download-model" @click="downloadRow(row)">
            Download {{ formatBytes(row.model.builds.wllama!.bytes - row.bytes) }}
          </button>
          <button v-else-if="brains.downloads[row.model.key]" class="text-xs text-muted hover:text-text" @click="brains.stopDownload(row.model)">Pause</button>
        </div>
        <p v-if="brains.downloads[row.model.key]?.error" class="mt-1 text-xs text-red-400">{{ brains.downloads[row.model.key]!.error }}</p>
        <div v-if="tests[rowId(row)]" class="mt-2 rounded-lg bg-bg px-3 py-2 text-xs" data-test="model-test">
          <p v-if="tests[rowId(row)]!.error" class="text-red-400">{{ tests[rowId(row)]!.error }}</p>
          <p v-else>{{ tests[rowId(row)]!.text || (tests[rowId(row)]!.running ? 'Loading the model…' : '') }}</p>
          <p v-if="tests[rowId(row)]!.speed" class="mt-1 font-mono text-media" data-test="model-speed">{{ tests[rowId(row)]!.speed!.toFixed(1) }} tokens per second</p>
        </div>
      </li>
      <li v-if="!deviceRows.length" class="px-4 py-6 text-center text-sm text-muted">No models for this device.</li>
    </ul>

    <h2 class="mt-8 font-medium">Your providers</h2>
    <p class="mt-1 text-sm text-muted">
      Your own API endpoints (OpenAI-compatible or Ollama). Keys are stored encrypted and never sent to the browser:
      with a key, chats go through the Kalpa server. Without one (a local endpoint), the browser calls it directly.
    </p>
    <div v-if="!hasOllama" class="mt-3 flex flex-wrap items-center gap-3 rounded-xl bg-panel px-4 py-3 text-sm">
      <span class="min-w-0 flex-1">Local Ollama <span class="text-xs text-muted">kalpa-ollama on {{ OLLAMA_URL }} (make ai-up)</span></span>
      <button class="rounded-lg bg-accent px-3 py-1.5 text-xs font-medium text-bg" data-test="add-ollama" @click="addOllama">Add Local Ollama</button>
      <p v-if="ollamaError" class="w-full text-xs text-amber-300" data-test="ollama-error">{{ ollamaError }}</p>
    </div>

    <ul class="mt-3 divide-y divide-muted/15 rounded-xl bg-panel">
      <li v-for="provider in providers" :key="provider.id" class="flex flex-wrap items-center gap-3 px-4 py-3 text-sm" data-test="provider-row">
        <span class="min-w-0 flex-1">{{ provider.name }} <span class="text-xs text-muted">{{ provider.provider }} · {{ provider.base_url }} · {{ provider.models.join(', ') }}</span></span>
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
