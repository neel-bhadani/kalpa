// A task's checkpoint in an editor: where the user is (view) and what they made (data).
// Every change is kept in IndexedDB at once; a task.checkpoint op is queued 2 s after the last
// change (or right away on flush, e.g. when the tab is hidden). Framework-free; see the web app's
// useTaskCheckpoint for the Vue wrapper.

import type { SyncClient } from './client'

export type Checkpoint = {
  view?: Record<string, unknown>
  data?: Record<string, unknown>
  asset_id?: string
  asset_version?: number
}

export type LoadedCheckpoint = {
  checkpoint: Checkpoint
  // local: unsent edits made on this device; server: the last saved copy; none: nothing yet.
  source: 'local' | 'server' | 'none'
}

export class TaskCheckpoint {
  private timer: ReturnType<typeof setTimeout> | undefined
  private unsent: Checkpoint | null = null

  constructor(
    private client: SyncClient,
    private taskId: string,
    private options: { debounceMs?: number } = {},
  ) {}

  // The local copy if it has unsent edits, otherwise the server's. Pass a fresh server copy when online.
  async load(serverTask?: { id: string; row_version: number; checkpoint?: unknown }): Promise<LoadedCheckpoint> {
    if (serverTask) await this.client.putServerEntity('task', serverTask)

    const record = await this.client.getEntity('task', this.taskId)

    if (record?.local) {
      // Edits from before a reload that never reached the outbox: queue them now (unless a conflict waits).
      const queued = await this.client.hasPendingOp('task.checkpoint', this.taskId)
      if (!queued && !(await this.client.hasConflict('task', this.taskId))) {
        await this.client.enqueue({
          type: 'task.checkpoint',
          entity_id: this.taskId,
          base_row_version: record.local.base_row_version,
          payload: { checkpoint: record.local.data },
        })
      }
      return { checkpoint: record.local.data as Checkpoint, source: 'local' }
    }

    if (record?.data.checkpoint) return { checkpoint: record.data.checkpoint as Checkpoint, source: 'server' }

    return { checkpoint: {}, source: 'none' }
  }

  // Kept in IndexedDB at once; sent after a pause in changes.
  async update(checkpoint: Checkpoint): Promise<void> {
    const record = await this.client.getEntity('task', this.taskId)
    const base = record?.local?.base_row_version ?? record?.row_version ?? 1

    await this.client.setLocal('task', this.taskId, checkpoint, base)
    this.unsent = checkpoint

    clearTimeout(this.timer)
    this.timer = setTimeout(() => void this.flush(), this.options.debounceMs ?? 2000)
  }

  // Queue the latest change now.
  async flush(): Promise<void> {
    clearTimeout(this.timer)
    if (!this.unsent) return

    const checkpoint = this.unsent
    this.unsent = null
    const record = await this.client.getEntity('task', this.taskId)

    await this.client.enqueue({
      type: 'task.checkpoint',
      entity_id: this.taskId,
      base_row_version: record?.local?.base_row_version ?? record?.row_version ?? 1,
      payload: { checkpoint },
    })
  }

  dispose(): void {
    clearTimeout(this.timer)
  }
}
