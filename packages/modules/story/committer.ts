// Turns a draft's queued changes into an asset version: 10 s after the last edit, or at once
// (Ctrl+S, leaving the editor). One commit at a time; edits made meanwhile wait for the next one.
// If another device committed first, the draft is saved on top of that version (its version stays
// in the history). Offline or on an error, the draft stays queued and the next commit tries again.

import { commitBody, committed, isDirty, type Draft } from './draft'

export type CommitResult =
  | { ok: true; version: number }
  | { ok: false; conflict: number }

export type CommitPort = {
  // POST /assets/{id}/versions. Returns the new version, or the current one on a 409.
  send: (assetId: string, body: ReturnType<typeof commitBody>) => Promise<CommitResult>
  get: () => Draft
  set: (draft: Draft) => void
}

export const IDLE_COMMIT_MS = 10_000

export type CommitState = 'saved' | 'unsaved' | 'saving' | 'error'

export class VersionCommitter {
  private timer: ReturnType<typeof setTimeout> | null = null
  private running: Promise<void> | null = null
  state: CommitState = 'saved'
  error = ''

  constructor(private port: CommitPort, private onState: (state: CommitState) => void = () => {}, private idleMs = IDLE_COMMIT_MS) {}

  // An edit was made: commit after idleMs without another one.
  touched(): void {
    this.setState('unsaved')
    if (this.timer) clearTimeout(this.timer)
    this.timer = setTimeout(() => void this.commit(), this.idleMs)
  }

  // Commit now (and wait for one already running).
  async commit(): Promise<void> {
    if (this.timer) clearTimeout(this.timer)
    this.timer = null
    if (this.running) await this.running
    if (!isDirty(this.port.get())) {
      if (this.state !== 'error') this.setState('saved')
      return
    }
    this.running = this.send().finally(() => { this.running = null })
    await this.running
  }

  dispose(): void {
    if (this.timer) clearTimeout(this.timer)
  }

  private async send(): Promise<void> {
    const draft = this.port.get()
    const sent = { ops: draft.ops.length, changes: draft.changes.length }
    this.setState('saving')
    try {
      let result = await this.port.send(draft.assetId, commitBody(draft))
      if (!result.ok) {
        // Saved on top of the other device's version, as the whole spec.
        const body = commitBody(draft)
        result = await this.port.send(draft.assetId, { base_version: result.conflict, spec: draft.spec, message: `${body.message} (after v${result.conflict} from another device)` })
      }
      if (!result.ok) throw new Error('The script keeps changing on another device. Try again.')
      const now = committed(this.port.get(), result.version, sent)
      this.port.set(now)
      this.error = ''
      this.setState(isDirty(now) ? 'unsaved' : 'saved')
    } catch (error) {
      this.error = error instanceof Error ? error.message : 'Saving the version failed.'
      this.setState('error')
    }
  }

  private setState(state: CommitState): void {
    this.state = state
    this.onState(state)
  }
}
