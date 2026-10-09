// Resumable upload of one file: hash it, then send its parts straight to storage with
// presigned URLs, 3 at a time, retrying each part. Pause, resume and cancel at any time.
// The API calls are injected (see UploadApi), so this stays framework-free.

import { hashFile } from './hash'
import { planParts, partRange, type PartPlan } from './plan'
import { forgetResume, getResume, resumeKey, saveResume } from './resume-store'
import { withRetry } from './retry'

export type UploadInfo = { id: string; part_size: number; part_count: number; status: string }
export type StoredPart = { number: number; etag: string; size: number }
export type UploadedFile = { id: string; sha256: string; size_bytes: number; mime: string }

export type StartResult =
  | { deduplicated: true; file: UploadedFile }
  | { deduplicated: false; upload: UploadInfo }

export interface UploadApi {
  start(body: { file_name: string; size_bytes: number; mime: string; sha256: string }): Promise<StartResult>
  status(uploadId: string): Promise<{ upload: UploadInfo; parts: StoredPart[] }>
  presign(uploadId: string, partNumbers: number[]): Promise<{ urls: { number: number; url: string }[]; expires_at: string }>
  complete(uploadId: string, parts: { number: number; etag: string }[]): Promise<{ file: UploadedFile }>
  abort(uploadId: string): Promise<void>
}

export type UploadPhase = 'hashing' | 'uploading' | 'paused' | 'completing' | 'done' | 'cancelled' | 'error'

export type UploadState = {
  phase: UploadPhase
  hashedPercent: number
  uploadedPercent: number
  // Parts that were already stored when this upload resumed.
  resumedParts: number
  partCount: number
  deduplicated: boolean
  file?: UploadedFile
  error?: string
}

export type UploaderOptions = {
  workspaceId: string
  onChange: (state: UploadState) => void
  concurrency?: number
  retries?: number
}

const PRESIGN_BATCH = 10
// Ask for fresh URLs when less than this much time is left on one.
const URL_MARGIN_MS = 60_000

class AbortedError extends Error {
  override name = 'AbortError'
}

export class FileUpload {
  readonly state: UploadState = {
    phase: 'hashing', hashedPercent: 0, uploadedPercent: 0, resumedParts: 0, partCount: 0, deduplicated: false,
  }

  private sha256 = ''
  private uploadId = ''
  private plan: PartPlan | null = null
  private queue: number[] = []
  private etags = new Map<number, string>()
  private doneBytes = 0
  private inflight = new Map<number, { xhr: XMLHttpRequest; loaded: number }>()
  private urls = new Map<number, { url: string; expiresAt: number }>()
  private presigning: Promise<void> | null = null
  private paused = false
  private cancelled = false
  private hashing = new AbortController()

  constructor(
    readonly file: File,
    private api: UploadApi,
    private options: UploaderOptions,
  ) {}

  async start(): Promise<void> {
    await this.guard(async () => {
      this.sha256 = await hashFile(this.file, fraction => this.update({ hashedPercent: fraction * 100 }), this.hashing.signal)
      this.update({ hashedPercent: 100 })

      if (await this.prepare()) {
        await this.run()
      }
    })
  }

  pause(): void {
    if (this.state.phase !== 'uploading') return
    this.paused = true
    this.abortInflight()
  }

  // Continue after a pause, or after a failure once the upload has started (stored parts are kept).
  async resume(): Promise<void> {
    if (this.state.phase !== 'paused' && !(this.state.phase === 'error' && this.uploadId)) return
    this.paused = false
    this.update({ error: undefined })
    await this.guard(() => this.run())
  }

  async cancel(): Promise<void> {
    if (['done', 'cancelled'].includes(this.state.phase)) return
    this.cancelled = true
    this.hashing.abort()
    this.abortInflight()
    if (this.uploadId) {
      await this.api.abort(this.uploadId).catch(() => undefined)
    }
    if (this.sha256) {
      await forgetResume(this.key()).catch(() => undefined)
    }
    this.update({ phase: 'cancelled' })
  }

  private key(): string {
    return resumeKey(this.options.workspaceId, this.sha256)
  }

  // Dedupe, resume a saved upload, or start a new one. Returns false when nothing needs uploading.
  private async prepare(): Promise<boolean> {
    const saved = await getResume(this.key()).catch(() => undefined)
    if (saved) {
      try {
        const { upload, parts } = await this.api.status(saved.uploadId)
        if (upload.status === 'pending') {
          this.useUpload(upload, parts)
          return true
        }
      } catch {
        // Gone or not ours any more: start over below.
      }
      await forgetResume(this.key()).catch(() => undefined)
    }

    const result = await this.api.start({
      file_name: this.file.name,
      size_bytes: this.file.size,
      mime: this.file.type,
      sha256: this.sha256,
    })

    if (result.deduplicated) {
      this.update({ phase: 'done', deduplicated: true, uploadedPercent: 100, file: result.file })
      return false
    }

    await saveResume(this.key(), {
      uploadId: result.upload.id, fileName: this.file.name, size: this.file.size, savedAt: Date.now(),
    }).catch(() => undefined)
    this.useUpload(result.upload, [])

    return true
  }

  private useUpload(upload: UploadInfo, stored: StoredPart[]): void {
    this.uploadId = upload.id
    this.plan = planParts(this.file.size, upload.part_size)

    // Keep stored parts that have the size we expect; anything else is sent again.
    for (const part of stored) {
      const { start, end } = partRange(this.plan, part.number)
      if (part.size === end - start) {
        this.etags.set(part.number, part.etag)
        this.doneBytes += part.size
      }
    }

    this.queue = []
    for (let number = 1; number <= this.plan.count; number++) {
      if (!this.etags.has(number)) this.queue.push(number)
    }

    this.update({ partCount: this.plan.count, resumedParts: this.etags.size })
    this.reportProgress()
  }

  private async run(): Promise<void> {
    this.update({ phase: 'uploading' })

    const workers = Array.from({ length: this.options.concurrency ?? 3 }, () => this.worker())
    const results = await Promise.allSettled(workers)

    if (this.cancelled) return
    const failure = results.find(result => result.status === 'rejected')
    if (failure) throw failure.reason
    if (this.paused) {
      this.update({ phase: 'paused' })
      return
    }

    this.update({ phase: 'completing' })
    const parts = [...this.etags].sort(([a], [b]) => a - b).map(([number, etag]) => ({ number, etag }))
    const { file } = await this.api.complete(this.uploadId, parts)
    await forgetResume(this.key()).catch(() => undefined)
    this.update({ phase: 'done', uploadedPercent: 100, file })
  }

  // Takes parts off the queue until it's empty, or the upload is paused or cancelled.
  private async worker(): Promise<void> {
    while (!this.paused && !this.cancelled) {
      const number = this.queue.shift()
      if (number === undefined) return

      try {
        await withRetry(() => this.sendPart(number), {
          retries: this.options.retries ?? 3,
          shouldRetry: error => !(error instanceof AbortedError) && !this.paused && !this.cancelled,
        })
      } catch (error) {
        // Put it back so resume picks it up; a real failure stops the other workers too.
        this.queue.unshift(number)
        if (!(error instanceof AbortedError) && !this.paused && !this.cancelled) {
          this.abortInflight()
          this.paused = true
          throw error
        }
        return
      }
    }
  }

  private async sendPart(number: number): Promise<void> {
    const url = await this.urlFor(number)
    const { start, end } = partRange(this.plan!, number)

    const etag = await new Promise<string>((resolve, reject) => {
      const xhr = new XMLHttpRequest()
      this.inflight.set(number, { xhr, loaded: 0 })

      xhr.upload.onprogress = event => {
        this.inflight.set(number, { xhr, loaded: event.loaded })
        this.reportProgress()
      }
      xhr.onload = () => {
        if (xhr.status >= 200 && xhr.status < 300) {
          resolve((xhr.getResponseHeader('ETag') ?? '').replace(/"/g, ''))
          return
        }
        // An expired or rejected signature: get a fresh URL before the retry.
        if (xhr.status === 403) this.urls.delete(number)
        reject(new Error(`Part ${number} failed with HTTP ${xhr.status}.`))
      }
      xhr.onerror = () => reject(new Error(`Part ${number} failed: network error.`))
      xhr.onabort = () => reject(new AbortedError())

      xhr.open('PUT', url)
      xhr.send(this.file.slice(start, end))
    }).finally(() => {
      this.inflight.delete(number)
      this.reportProgress()
    })

    this.etags.set(number, etag)
    this.doneBytes += end - start
    this.reportProgress()
  }

  // A presigned URL for the part; fetched in batches of up to 10 queued parts.
  private async urlFor(number: number): Promise<string> {
    for (;;) {
      const cached = this.urls.get(number)
      if (cached && cached.expiresAt - Date.now() > URL_MARGIN_MS) {
        return cached.url
      }

      if (!this.presigning) {
        const wanted = [number, ...this.queue.filter(n => !this.urls.has(n))].slice(0, PRESIGN_BATCH)
        this.presigning = this.api.presign(this.uploadId, wanted)
          .then(({ urls, expires_at }) => {
            for (const { number: n, url } of urls) {
              this.urls.set(n, { url, expiresAt: Date.parse(expires_at) })
            }
          })
          .finally(() => {
            this.presigning = null
          })
      }

      await this.presigning
    }
  }

  private abortInflight(): void {
    for (const { xhr } of this.inflight.values()) xhr.abort()
  }

  private reportProgress(): void {
    const inflight = [...this.inflight.values()].reduce((sum, part) => sum + part.loaded, 0)
    this.update({ uploadedPercent: Math.min(100, ((this.doneBytes + inflight) / this.file.size) * 100) })
  }

  private update(changes: Partial<UploadState>): void {
    Object.assign(this.state, changes)
    this.options.onChange({ ...this.state })
  }

  private async guard(action: () => Promise<void>): Promise<void> {
    try {
      await action()
    } catch (error) {
      if (this.cancelled) return
      this.update({ phase: 'error', error: error instanceof Error ? error.message : String(error) })
    }
  }
}
