// A browser tab as a compute worker: it claims ONE job at a time (only when idle), keeps the
// job's lease alive with a heartbeat every 15 s, and stops claiming while the tab is hidden.
// API calls are injected (WorkerApi), so this stays framework-free.

export type ClaimedJob = { id: string; type: string; input: unknown }

export type WorkerLog = { level?: 'debug' | 'info' | 'warning' | 'error'; message: string }

export interface WorkerApi {
  claim(capabilities: string[]): Promise<ClaimedJob | null>
  // The reply's status says whether to carry on ("running") or stop (paused, cancelled, lost).
  heartbeat(jobId: string, body: { progress?: number; log?: WorkerLog }): Promise<{ status: string }>
  complete(jobId: string, output: unknown): Promise<void>
  fail(jobId: string, error: string): Promise<void>
}

export type JobContext = {
  // 0..100. Sent at most once per second (and always with a log message).
  report(progress: number, message?: string): void
  // Aborted when the job is paused, cancelled or taken away, or the worker stops.
  signal: AbortSignal
}

export type JobHandler = (input: unknown, context: JobContext) => Promise<unknown>

export type Visibility = {
  isHidden(): boolean
  // Calls the listener when the tab is shown or hidden; returns an unsubscribe function.
  onChange(listener: () => void): () => void
}

export type BrowserWorkerOptions = {
  api: WorkerApi
  handlers: Record<string, JobHandler>
  capabilities?: string[]
  // How often an idle worker asks for work.
  pollMs?: number
  heartbeatMs?: number
  // Smallest gap between progress reports.
  progressMs?: number
  visibility?: Visibility
  onError?: (error: unknown) => void
}

const KEEP_RUNNING = ['running', 'assigned']

export const documentVisibility: Visibility = {
  isHidden: () => typeof document !== 'undefined' && document.visibilityState === 'hidden',
  onChange(listener) {
    document.addEventListener('visibilitychange', listener)
    return () => document.removeEventListener('visibilitychange', listener)
  },
}

export class BrowserWorker {
  private started = false
  private busy = false
  private timer: ReturnType<typeof setTimeout> | undefined
  private current: AbortController | null = null
  private unsubscribe: (() => void) | null = null

  constructor(private options: BrowserWorkerOptions) {}

  get isBusy(): boolean {
    return this.busy
  }

  start(): void {
    if (this.started) return
    this.started = true
    // When the tab comes back, look for work straight away.
    this.unsubscribe = this.visibility().onChange(() => {
      if (!this.visibility().isHidden() && !this.busy) this.schedule(0)
    })
    this.schedule(0)
  }

  // Look for work now instead of at the next poll (e.g. this user just queued a device job).
  poke(): void {
    if (this.started && !this.busy && !this.visibility().isHidden()) this.schedule(0)
  }

  stop(): void {
    this.started = false
    clearTimeout(this.timer)
    this.current?.abort()
    this.unsubscribe?.()
    this.unsubscribe = null
  }

  private visibility(): Visibility {
    return this.options.visibility ?? documentVisibility
  }

  private schedule(ms: number): void {
    clearTimeout(this.timer)
    this.timer = setTimeout(() => void this.tick(), ms)
  }

  private async tick(): Promise<void> {
    if (!this.started || this.busy) return

    if (this.visibility().isHidden()) {
      // Paused while hidden; the visibility listener restarts the loop.
      return
    }

    let job: ClaimedJob | null = null
    try {
      job = await this.options.api.claim(this.options.capabilities ?? [])
    } catch (error) {
      this.options.onError?.(error)
    }

    if (!this.started) return

    if (job === null) {
      this.schedule(this.options.pollMs ?? 5000)
      return
    }

    await this.run(job)
    // There may be more queued: ask again at once.
    if (this.started) this.schedule(0)
  }

  private async run(job: ClaimedJob): Promise<void> {
    this.busy = true
    const controller = new AbortController()
    this.current = controller
    let progress = 0
    let lastReport = 0

    const beat = async (log?: WorkerLog) => {
      try {
        const { status } = await this.options.api.heartbeat(job.id, { progress, log })
        if (!KEEP_RUNNING.includes(status)) controller.abort()
      } catch (error) {
        this.options.onError?.(error)
      }
    }

    const interval = setInterval(() => void beat(), this.options.heartbeatMs ?? 15_000)

    try {
      await beat({ message: 'Started in the browser' })
      if (controller.signal.aborted) return

      const handler = this.options.handlers[job.type]
      if (!handler) throw new Error(`This browser can't run "${job.type}" jobs.`)

      const output = await handler(job.input, {
        signal: controller.signal,
        report: (value, message) => {
          progress = Math.max(0, Math.min(100, Math.round(value)))
          const now = Date.now()
          if (message || now - lastReport >= (this.options.progressMs ?? 1000)) {
            lastReport = now
            void beat(message ? { message } : undefined)
          }
        },
      })

      if (!controller.signal.aborted) await this.options.api.complete(job.id, output ?? {})
    } catch (error) {
      // A paused or cancelled job is simply dropped; real errors are reported.
      if (!controller.signal.aborted) {
        await this.options.api.fail(job.id, error instanceof Error ? error.message : String(error)).catch(this.options.onError)
      }
    } finally {
      clearInterval(interval)
      this.current = null
      this.busy = false
    }
  }
}
