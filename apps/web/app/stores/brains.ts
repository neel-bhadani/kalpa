import { defineStore } from 'pinia'
import wllamaWasm from '@wllama/wllama/esm/wasm/wllama.wasm?url'
import {
  collectDeviceProfile,
  computeTier,
  downloadModelFile,
  ModelManager,
  opfsModelStore,
  pickBrain,
  type BrainChoice,
  type BrainPlan,
  type CatalogModel,
  type DeviceProfile,
  type ModelFileStore,
} from '@kalpa/core'
import { hashFile } from '@kalpa/core/upload/hash'
import {
  directPost,
  FakeBrain,
  OpenAICompatibleBrain,
  WebLLMBrain,
  webllmDeleteModel,
  webllmHasModel,
  WllamaBrain,
  type Brain,
  type PostChat,
} from '@kalpa/ai-runtime'

// The assistant's brain on this device: the models it may run (model registry), the user's
// providers, the choice in the picker, model downloads, and the model manager that keeps loaded
// models inside the device's memory budget.

export type Provider = { id: string; provider: string; name: string; base_url: string; api_key_hint: string | null; models: string[]; enabled: boolean }

export type Download = { done: number; total: number; checking: boolean; error: string | null }

type DeviceBrain = (WllamaBrain | WebLLMBrain) & Brain

const CHOICE_KEY = 'kalpa.brain'

export const useBrainsStore = defineStore('brains', () => {
  const api = useApi()
  const auth = useAuthStore()

  const models = ref<CatalogModel[]>([])
  const providers = ref<Provider[]>([])
  const profile = ref<DeviceProfile | null>(null)
  const choice = ref<BrainChoice>(readChoice())
  const downloads = reactive<Record<string, Download>>({})
  // Generation speed of the last reply, for the status line.
  const tokensPerSecond = ref<number | null>(null)
  // WebLLM's own loading progress (it downloads its shards itself).
  const gpuProgress = ref<{ fraction: number; text: string } | null>(null)

  const tier = computed(() => (profile.value ? computeTier(profile.value) : 'low'))

  const plan = computed<BrainPlan>(() => pickBrain({
    choice: choice.value,
    tier: tier.value,
    webgpu: profile.value?.webgpu ?? false,
    models: models.value,
    providers: providers.value,
    devTools: auth.devTools,
  }))

  let manager: ModelManager | null = null
  let files: ModelFileStore | null = null
  let ready: Promise<void> | null = null
  const deviceBrains = new Map<string, DeviceBrain>()
  const stops = new Map<string, AbortController>()

  function load(): Promise<void> {
    ready ??= (async () => {
      const [list] = await Promise.all([
        api<{ data: CatalogModel[] }>('/api/v1/models'),
        loadProviders(),
        collectDeviceProfile().then((value) => { profile.value = value }),
      ])
      models.value = list.data.filter(model => model.capability === 'llm' && model.enabled)
      manager = ModelManager.forTier(tier.value)
      manager.watch(document)
      files = await opfsModelStore().catch(() => null)
    })().catch((error) => {
      ready = null
      throw error
    })
    return ready
  }

  async function loadProviders() {
    providers.value = (await api<{ data: Provider[] }>('/api/v1/ai/providers')).data
  }

  function setChoice(next: BrainChoice) {
    choice.value = next
    localStorage.setItem(CHOICE_KEY, JSON.stringify(next))
  }

  // A brain for a plan. Device brains are kept, so a loaded model is reused.
  function brainFor(target: BrainPlan): Brain | null {
    switch (target.kind) {
      case 'fake': return new FakeBrain()
      case 'provider': return new OpenAICompatibleBrain({ model: target.model, post: postFor(target.provider.id) })
      case 'wllama':
      case 'webllm': return managed(deviceBrain(target.kind, target.model))
      default: return null
    }
  }

  // Keys stored on the server go through its proxy; keyless (local) endpoints are called directly.
  function postFor(providerId: string): PostChat {
    const provider = providers.value.find(item => item.id === providerId)!
    if (!provider.api_key_hint) return directPost(provider.base_url)
    return (body, signal) => fetch(`/api/v1/ai/providers/${provider.id}/chat`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'text/event-stream', 'X-XSRF-TOKEN': xsrfToken() },
      body: JSON.stringify(body),
      signal,
    })
  }

  function deviceBrain(kind: 'wllama' | 'webllm', model: CatalogModel): DeviceBrain {
    const id = `${kind}:${model.key}`
    let brain = deviceBrains.get(id)
    if (!brain) {
      if (kind === 'wllama') {
        const build = model.builds.wllama!
        brain = new WllamaBrain({
          key: model.key,
          ramMb: build.ram_mb,
          wasmUrl: wllamaWasm,
          file: async () => {
            const file = (await files?.isComplete(build.file)) ? await files!.read(build.file) : null
            if (!file) throw new Error(`${model.display_name} is not downloaded yet.`)
            return file
          },
        })
      } else {
        const build = model.builds.webllm!
        brain = new WebLLMBrain({
          key: model.key,
          ramMb: build.ram_mb,
          modelId: build.model_id,
          createWorker: () => new Worker(new URL('../workers/webllm.worker.ts', import.meta.url), { type: 'module' }),
          onProgress: (fraction, text) => { gpuProgress.value = fraction < 1 ? { fraction, text } : null },
        })
      }
      deviceBrains.set(id, brain)
    }
    return brain
  }

  // Loads the model through the manager (inside the budget) and holds it while it answers.
  function managed(brain: DeviceBrain): Brain {
    return {
      async *chat(request) {
        await load()
        const release = await manager!.use(brain)
        try {
          yield* brain.chat(request)
        } finally {
          release()
        }
      },
    }
  }

  // Bytes still to download before this model runs here (0 when it's ready).
  async function missingBytes(kind: 'wllama' | 'webllm', model: CatalogModel): Promise<number> {
    // The plan can change before the file store is open.
    await load()
    if (kind === 'webllm') {
      return (await webllmHasModel(model.builds.webllm!.model_id)) ? 0 : model.builds.webllm!.bytes
    }
    const build = model.builds.wllama!
    if (!files) return build.bytes
    return (await files.isComplete(build.file)) ? 0 : build.bytes - (await files.size(build.file))
  }

  // Download a model's GGUF once (resumes where it stopped). WebLLM models download on first load.
  async function download(model: CatalogModel): Promise<void> {
    const build = model.builds.wllama!
    if (!files) throw new Error('This browser has no private file storage (OPFS) for models.')
    const stop = new AbortController()
    stops.set(model.key, stop)
    downloads[model.key] = { done: await files.size(build.file), total: build.bytes, checking: false, error: null }
    // Ask the browser not to clear big files when space runs low.
    await navigator.storage.persist?.().catch(() => false)
    try {
      await downloadModelFile({
        store: files,
        name: build.file,
        url: build.url,
        bytes: build.bytes,
        sha256: build.sha256,
        signal: stop.signal,
        onProgress: (done, total) => {
          downloads[model.key] = { done, total, checking: done === total, error: null }
        },
        freeBytes: async () => {
          const { quota, usage } = await navigator.storage.estimate()
          return quota === undefined ? null : quota - (usage ?? 0)
        },
        hash: file => hashFile(file, () => {}),
      })
      delete downloads[model.key]
    } catch (error) {
      const message = stop.signal.aborted ? 'Paused. Download again to continue.' : error instanceof Error ? error.message : 'The download failed.'
      downloads[model.key] = { ...downloads[model.key]!, checking: false, error: message }
      throw error
    } finally {
      stops.delete(model.key)
    }
  }

  function stopDownload(model: CatalogModel) {
    stops.get(model.key)?.abort()
  }

  // Models on this device, with what's on disk (Settings → AI).
  async function onDevice(): Promise<{ model: CatalogModel; engine: 'wllama' | 'webllm'; bytes: number; complete: boolean }[]> {
    await load()
    const stored = (await files?.list()) ?? []
    const rows: { model: CatalogModel; engine: 'wllama' | 'webllm'; bytes: number; complete: boolean }[] = models.value.filter(model => model.builds.wllama).map((model) => {
      const file = stored.find(item => item.name === model.builds.wllama!.file)
      return { model, engine: 'wllama', bytes: file?.bytes ?? 0, complete: file?.complete ?? false }
    })
    if (profile.value?.webgpu) {
      for (const model of models.value.filter(item => item.builds.webllm)) {
        const cached = await webllmHasModel(model.builds.webllm!.model_id).catch(() => false)
        rows.push({ model, engine: 'webllm', bytes: cached ? model.builds.webllm!.bytes : 0, complete: cached })
      }
    }
    return rows
  }

  async function remove(model: CatalogModel, engine: 'wllama' | 'webllm') {
    await manager?.unload(model.key)
    if (engine === 'wllama') await files?.remove(model.builds.wllama!.file)
    else await webllmDeleteModel(model.builds.webllm!.model_id)
  }

  // Name and place for the status line, e.g. "Qwen3 1.7B" · "CPU".
  function describe(target: BrainPlan): { name: string; where: string } | null {
    switch (target.kind) {
      case 'wllama': return { name: target.model.display_name, where: 'CPU' }
      case 'webllm': return { name: target.model.display_name, where: 'GPU' }
      case 'provider': return { name: target.model, where: target.provider.name }
      case 'fake': return { name: 'Test brain', where: 'scripted' }
      default: return null
    }
  }

  return {
    models, providers, profile, tier, choice, plan, downloads, tokensPerSecond, gpuProgress,
    load, loadProviders, setChoice, brainFor, missingBytes, download, stopDownload, onDevice, remove, describe,
  }
})

function readChoice(): BrainChoice {
  try {
    return JSON.parse(localStorage.getItem(CHOICE_KEY) ?? '"auto"') as BrainChoice
  } catch {
    return 'auto'
  }
}

function xsrfToken(): string {
  return decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/)?.[1] ?? '')
}
