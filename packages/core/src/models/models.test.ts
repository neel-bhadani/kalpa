import { describe, expect, it, vi } from 'vitest'
import type { DeviceTier } from '../device-profile'
import type { CatalogModel } from './catalog'
import { downloadModelFile, type ModelFileStore } from './download'
import { ModelManager, type LoadableModel } from './manager'
import { pickBrain, type BrainChoice, type ProviderOption } from './select'

const qwen = (key: string, minTier: DeviceTier, cpuRam: number, gpuRam: number): CatalogModel => ({
  key, display_name: key, capability: 'llm', min_tier: minTier, enabled: true,
  builds: {
    wllama: { file: `${key}.gguf`, url: `https://hf.test/${key}.gguf`, bytes: 1000, ram_mb: cpuRam },
    webllm: { model_id: `${key}-MLC`, url: `https://hf.test/${key}-MLC`, bytes: 1000, ram_mb: gpuRam },
  },
})
const models = [qwen('qwen3-1.7b', 'mid', 1800, 2037), qwen('qwen3-0.6b', 'low', 1000, 1404), { ...qwen('qwen3-4b', 'high', 3500, 3432), enabled: false }]
const ollama: ProviderOption = { id: 'p1', name: 'Local Ollama', enabled: true, models: ['qwen3:1.7b'] }

function pick(tier: DeviceTier, webgpu: boolean, choice: BrainChoice = 'auto', providers: ProviderOption[] = [], devTools = false) {
  const plan = pickBrain({ choice, tier, webgpu, models, providers, devTools })
  switch (plan.kind) {
    case 'webllm':
    case 'wllama': return `${plan.kind}:${plan.model.key}`
    case 'provider': return `provider:${plan.model}`
    default: return plan.kind
  }
}

describe('pickBrain', () => {
  it.each([
    // tier, WebGPU, providers, expected
    ['low', false, [], 'wllama:qwen3-0.6b'],
    ['mid', false, [], 'wllama:qwen3-0.6b'], // smallest that fits on a CPU
    ['high', false, [], 'wllama:qwen3-0.6b'],
    ['low', true, [], 'wllama:qwen3-0.6b'], // the GPU builds need more than the low budget
    ['mid', true, [], 'webllm:qwen3-1.7b'], // largest that fits on a GPU
    ['high', true, [], 'webllm:qwen3-1.7b'], // 4B is switched off
    ['mid', false, [ollama], 'provider:qwen3:1.7b'],
    ['mid', true, [ollama], 'webllm:qwen3-1.7b'], // WebGPU first
    ['low', false, [{ ...ollama, enabled: false }], 'wllama:qwen3-0.6b'],
  ] as const)('auto on %s, WebGPU %s', (tier, webgpu, providers, expected) => {
    expect(pick(tier, webgpu, 'auto', [...providers])).toBe(expected)
  })

  it('"This device" never uses a provider', () => {
    expect(pick('mid', false, 'device', [ollama])).toBe('wllama:qwen3-0.6b')
  })

  it('uses the chosen provider, and says why not when it cannot', () => {
    expect(pick('low', false, { provider: 'p1' }, [ollama])).toBe('provider:qwen3:1.7b')
    expect(pickBrain({ choice: { provider: 'p1' }, tier: 'low', webgpu: false, models, providers: [{ ...ollama, models: [] }], devTools: false }))
      .toEqual({ kind: 'none', reason: 'Local Ollama is switched off or lists no model.' })
    expect(pick('low', false, { provider: 'gone' }, [ollama])).toBe('none')
  })

  it('offers the fake brain only with dev tools', () => {
    expect(pick('low', false, 'fake', [], true)).toBe('fake')
    expect(pick('low', false, 'fake', [], false)).toBe('none')
  })

  it('finds nothing when no model fits', () => {
    expect(pickBrain({ choice: 'device', tier: 'low', webgpu: false, models: [models[0]!], providers: [], devTools: false }).kind).toBe('none')
  })
})

function fakeModel(key: string, ramMb: number, log: string[]): LoadableModel {
  return {
    key,
    ramMb,
    load: async () => { log.push(`load ${key}`) },
    unload: async () => { log.push(`unload ${key}`) },
  }
}

describe('ModelManager', () => {
  it('keeps one model loaded on a mid device and loads each only once', async () => {
    const log: string[] = []
    const manager = ModelManager.forTier('mid')
    const small = fakeModel('small', 1000, log)
    const big = fakeModel('big', 1800, log)

    ;(await manager.use(small))()
    ;(await manager.use(small))()
    ;(await manager.use(big))()

    expect(log).toEqual(['load small', 'unload small', 'load big'])
    expect(manager.loaded.map(model => model.key)).toEqual(['big'])
  })

  it('refuses a model bigger than the budget', async () => {
    const manager = ModelManager.forTier('low')
    await expect(manager.use(fakeModel('big', 1800, []))).rejects.toThrow('this device allows 1200 MB')
  })

  it('unloads the least recently used first, within the RAM budget', async () => {
    let clock = 0
    const log: string[] = []
    const manager = new ModelManager({ ramMb: 3000, maxLoaded: 3, now: () => clock++ })
    const a = fakeModel('a', 1000, log)
    const b = fakeModel('b', 1000, log)
    const c = fakeModel('c', 1500, log)

    ;(await manager.use(a))()
    ;(await manager.use(b))()
    ;(await manager.use(a))() // a is now the most recent
    ;(await manager.use(c))() // 3500 MB would be too much: b goes

    expect(log).toEqual(['load a', 'load b', 'unload b', 'load c'])
    expect(manager.loaded.map(model => model.key).sort()).toEqual(['a', 'c'])
  })

  it('never unloads a model while it is answering', async () => {
    const log: string[] = []
    const manager = ModelManager.forTier('mid')
    const release = await manager.use(fakeModel('a', 1000, log))

    await expect(manager.use(fakeModel('b', 1000, log))).rejects.toThrow('all answering')
    await expect(manager.relieve()).resolves.toBe(false)
    release()
    ;(await manager.use(fakeModel('b', 1000, log)))()
    expect(log).toEqual(['load a', 'unload a', 'load b'])
  })

  it('unloads idle models after 5 minutes hidden, not before', async () => {
    vi.useFakeTimers()
    const log: string[] = []
    const manager = ModelManager.forTier('mid')
    ;(await manager.use(fakeModel('a', 1000, log)))()

    manager.setHidden(true)
    await vi.advanceTimersByTimeAsync(4 * 60_000)
    manager.setHidden(false) // came back in time
    await vi.advanceTimersByTimeAsync(10 * 60_000)
    expect(manager.loaded).toHaveLength(1)

    manager.setHidden(true)
    await vi.advanceTimersByTimeAsync(5 * 60_000)
    expect(manager.loaded).toHaveLength(0)
    expect(log).toEqual(['load a', 'unload a'])
    vi.useRealTimers()
  })

  it('frees memory and retries once when a load fails', async () => {
    const log: string[] = []
    const manager = new ModelManager({ ramMb: 4096, maxLoaded: 2 })
    ;(await manager.use(fakeModel('a', 1000, log)))()
    let tries = 0
    const flaky: LoadableModel = { key: 'b', ramMb: 1000, unload: async () => {}, load: async () => { if (tries++ === 0) throw new RangeError('out of memory') } }

    ;(await manager.use(flaky))()
    expect(log).toEqual(['load a', 'unload a'])
    expect(manager.loaded.map(model => model.key)).toEqual(['b'])
  })
})

// An in-memory ModelFileStore.
function memoryStore() {
  const files = new Map<string, Uint8Array>()
  const complete = new Set<string>()
  const store: ModelFileStore = {
    size: async name => files.get(name)?.byteLength ?? 0,
    appender: async (name) => ({
      write: async (chunk) => {
        const old = files.get(name) ?? new Uint8Array()
        const next = new Uint8Array(old.byteLength + chunk.byteLength)
        next.set(old)
        next.set(chunk, old.byteLength)
        files.set(name, next)
      },
      close: async () => {},
    }),
    read: async name => (files.has(name) ? new Blob([files.get(name)! as Uint8Array<ArrayBuffer>]) : null),
    isComplete: async name => complete.has(name),
    markComplete: async (name) => { complete.add(name) },
    remove: async (name) => { files.delete(name); complete.delete(name) },
    list: async () => [...files].map(([name, data]) => ({ name, bytes: data.byteLength, complete: complete.has(name) })),
  }
  return { store, files, complete }
}

const FILE = new Uint8Array(Array.from({ length: 100 }, (_, i) => i))

// A server for FILE that honours Range, optionally cutting the connection after `cutAfter` bytes.
function server(options: { cutAfter?: number; ignoreRange?: boolean; body?: Uint8Array } = {}) {
  const body = options.body ?? FILE
  const ranges: (string | null)[] = []
  const fetch = vi.fn(async (_url: string | URL | Request, init?: RequestInit) => {
    const range = new Headers(init?.headers).get('Range')
    ranges.push(range)
    const from = range && !options.ignoreRange ? Number(range.match(/bytes=(\d+)-/)![1]) : 0
    const part = body.slice(from)
    let sent = 0
    const stream = new ReadableStream<Uint8Array>({
      pull(controller) {
        if (sent >= part.byteLength) return controller.close()
        if (options.cutAfter !== undefined && from + sent >= options.cutAfter) return controller.error(new TypeError('network error'))
        const chunk = part.slice(sent, sent + 10)
        sent += chunk.byteLength
        controller.enqueue(chunk)
      },
    })
    const headers = from > 0
      ? { 'Content-Range': `bytes ${from}-${body.byteLength - 1}/${body.byteLength}`, 'Content-Length': String(part.byteLength) }
      : { 'Content-Length': String(part.byteLength) }
    return new Response(stream, { status: from > 0 ? 206 : 200, headers })
  })
  return { fetch: fetch as unknown as typeof globalThis.fetch, ranges }
}

const base = { name: 'model.gguf', url: 'https://hf.test/model.gguf', bytes: 100 }

describe('downloadModelFile', () => {
  it('resumes from the bytes already on disk', async () => {
    const { store, files, complete } = memoryStore()
    const progress: number[] = []

    const first = server({ cutAfter: 40 })
    await expect(downloadModelFile({ ...base, store, fetch: first.fetch })).rejects.toThrow('network error')
    expect(files.get('model.gguf')!.byteLength).toBe(40)
    expect(complete.size).toBe(0)

    const second = server()
    await downloadModelFile({ ...base, store, fetch: second.fetch, onProgress: done => progress.push(done) })
    expect(second.ranges).toEqual(['bytes=40-'])
    expect(progress[0]).toBe(50)
    expect(progress.at(-1)).toBe(100)
    expect(files.get('model.gguf')).toEqual(FILE)
    expect(complete.has('model.gguf')).toBe(true)

    // Downloaded once: not fetched again.
    const third = server()
    await downloadModelFile({ ...base, store, fetch: third.fetch })
    expect(third.ranges).toEqual([])
  })

  it('starts over when the server ignores Range', async () => {
    const { store, files } = memoryStore()
    await downloadModelFile({ ...base, store, fetch: server({ cutAfter: 30 }).fetch }).catch(() => {})
    await downloadModelFile({ ...base, store, fetch: server({ ignoreRange: true }).fetch })
    expect(files.get('model.gguf')).toEqual(FILE)
  })

  it('checks the size before writing anything', async () => {
    const { store, files } = memoryStore()
    const bigger = server({ body: new Uint8Array(150) })
    await expect(downloadModelFile({ ...base, store, fetch: bigger.fetch })).rejects.toThrow('was expected. Nothing was downloaded.')
    expect(files.size).toBe(0)
  })

  it('refuses when there is not enough free storage', async () => {
    const { store } = memoryStore()
    const { fetch, ranges } = server()
    await expect(downloadModelFile({ ...base, store, fetch, freeBytes: async () => 50 })).rejects.toThrow('Not enough storage')
    expect(ranges).toEqual([])
  })

  it('removes a file whose SHA-256 does not match', async () => {
    const { store, files, complete } = memoryStore()
    const hash = async () => 'aa'.repeat(32)
    await expect(downloadModelFile({ ...base, store, fetch: server().fetch, sha256: 'bb'.repeat(32), hash })).rejects.toThrow('damaged')
    expect(files.size).toBe(0)
    expect(complete.size).toBe(0)

    await downloadModelFile({ ...base, store, fetch: server().fetch, sha256: 'AA'.repeat(32), hash })
    expect(complete.has('model.gguf')).toBe(true)
  })
})
