// Keeps loaded models inside the device tier's budget: at most `maxLoaded` at once, with their RAM
// together within `ramMb`. Room is made by unloading the least recently used model that isn't
// working. Everything idle is unloaded after the app has been hidden for 5 minutes, and the least
// recently used one on memory pressure (a failed load, or relieve() from the platform).

import type { DeviceTier } from '../device-profile'
import { TIER_BUDGETS } from './catalog'

export type LoadableModel = {
  key: string
  ramMb: number
  load(): Promise<void>
  unload(): Promise<void>
}

export type ModelManagerOptions = {
  ramMb: number
  maxLoaded: number
  hiddenUnloadMs?: number
  now?: () => number
}

type Entry = { model: LoadableModel; lastUsed: number; holds: number }

export const HIDDEN_UNLOAD_MS = 5 * 60 * 1000

export class ModelManager {
  private entries = new Map<string, Entry>()
  private queue: Promise<unknown> = Promise.resolve()
  private hiddenTimer: ReturnType<typeof setTimeout> | null = null

  constructor(private options: ModelManagerOptions) {}

  static forTier(tier: DeviceTier, options: Partial<ModelManagerOptions> = {}): ModelManager {
    return new ModelManager({ ...TIER_BUDGETS[tier], ...options })
  }

  get budgetMb(): number {
    return this.options.ramMb
  }

  get loaded(): { key: string; ramMb: number; lastUsed: number; working: boolean }[] {
    return [...this.entries.values()].map(({ model, lastUsed, holds }) => ({ key: model.key, ramMb: model.ramMb, lastUsed, working: holds > 0 }))
  }

  // Load the model if needed and hold it while it works. Call the returned release() when done.
  use(model: LoadableModel): Promise<() => void> {
    return this.serial(async () => {
      if (model.ramMb > this.options.ramMb) {
        throw new Error(`${model.key} needs about ${model.ramMb} MB of memory; this device allows ${this.options.ramMb} MB for models.`)
      }

      let entry = this.entries.get(model.key)
      if (!entry) {
        await this.makeRoom(model.ramMb)
        try {
          await model.load()
        } catch (error) {
          // Perhaps out of memory: free one more model and try once again.
          if (!(await this.evictOne())) throw error
          await model.load()
        }
        entry = { model, lastUsed: 0, holds: 0 }
        this.entries.set(model.key, entry)
      }

      const held = entry
      held.lastUsed = this.now()
      held.holds++
      let released = false
      return () => {
        if (released) return
        released = true
        held.holds--
        held.lastUsed = this.now()
      }
    })
  }

  unload(key: string): Promise<void> {
    return this.serial(async () => {
      const entry = this.entries.get(key)
      if (!entry) return
      if (entry.holds > 0) throw new Error('The model is answering right now. Try again when it has finished.')
      this.entries.delete(key)
      await entry.model.unload()
    })
  }

  // Unload every model that isn't working.
  unloadIdle(): Promise<void> {
    return this.serial(async () => {
      while (await this.evictOne()) { /* until none is idle */ }
    })
  }

  // Memory pressure: unload the least recently used idle model. False if there was none.
  relieve(): Promise<boolean> {
    return this.serial(() => this.evictOne())
  }

  // The app was hidden (tab in the background) or shown again.
  setHidden(hidden: boolean): void {
    if (this.hiddenTimer) clearTimeout(this.hiddenTimer)
    this.hiddenTimer = hidden ? setTimeout(() => void this.unloadIdle(), this.options.hiddenUnloadMs ?? HIDDEN_UNLOAD_MS) : null
  }

  // Follow the page's visibility. Returns a function that stops following it.
  watch(doc: Document): () => void {
    const onChange = () => this.setHidden(doc.visibilityState === 'hidden')
    doc.addEventListener('visibilitychange', onChange)
    return () => doc.removeEventListener('visibilitychange', onChange)
  }

  private async makeRoom(ramMb: number): Promise<void> {
    while (this.entries.size >= this.options.maxLoaded || this.usedMb() + ramMb > this.options.ramMb) {
      if (!(await this.evictOne())) throw new Error('The models in memory are all answering. Try again when they have finished.')
    }
  }

  private async evictOne(): Promise<boolean> {
    const idle = [...this.entries.values()].filter(entry => entry.holds === 0).sort((a, b) => a.lastUsed - b.lastUsed)[0]
    if (!idle) return false
    this.entries.delete(idle.model.key)
    await idle.model.unload()
    return true
  }

  private usedMb(): number {
    return [...this.entries.values()].reduce((sum, entry) => sum + entry.model.ramMb, 0)
  }

  private now(): number {
    return (this.options.now ?? Date.now)()
  }

  // One change at a time, so two loads never race each other.
  private serial<T>(work: () => Promise<T>): Promise<T> {
    const run = this.queue.then(work, work)
    this.queue = run.catch(() => {})
    return run
  }
}
