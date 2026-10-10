// Offline sync for one workspace on this device. Framework-free; the API calls are injected (SyncApi).
//
// IndexedDB stores:
//   entities  a cache of synced rows (projects, tasks, asset metadata...), each with an optional
//             `local` copy of unsent edits (task checkpoints)
//   outbox    ops made here, in order, waiting to be pushed
//   meta      last_seq (how far we've pulled), device_id, conflicts
//
// Pull: on start, when the network comes back, on a nudge, and every 60 s. Push: whenever the outbox
// has ops and we're online, with backoff on errors. Each request that fails to reach the server marks
// the device offline until a later one gets through (navigator.onLine alone isn't reliable).

import { openDB, type DBSchema, type IDBPDatabase } from 'idb'
import { backoffDelay } from '../upload/retry'

export type EntityType = 'project' | 'task' | 'asset' | 'asset_version' | 'project_memory' | 'project_asset' | 'task_output' | 'ai_session' | 'ai_step'
export type OpType = 'task.checkpoint' | 'task.update' | 'project_memory.patch'

export type Change = {
  seq: number
  entity: EntityType
  entity_id: string
  op: 'upsert' | 'delete'
  row_version: number | null
  payload: Record<string, any> | null
}

export type Op = {
  op_id: string
  type: OpType
  entity_id: string
  base_row_version: number
  payload: Record<string, any>
}

export type OpResult = {
  op_id: string
  status: 'applied' | 'conflict' | 'rejected'
  row_version?: number
  server?: Record<string, any>
  errors?: Record<string, string[]>
}

export interface SyncApi {
  pull(after: number, limit: number): Promise<{ data: Change[]; meta: { next: number; has_more: boolean } }>
  push(deviceId: string, ops: Op[]): Promise<OpResult[]>
  // Fire-and-forget while the page closes: flag these tasks "Unsaved" on the server.
  markUnsynced(taskIds: string[]): void
}

export type LocalEdit = { data: any; base_row_version: number; edited_at: string }

export type EntityRecord = {
  key: string
  type: EntityType
  id: string
  data: Record<string, any>
  row_version: number | null
  // Edits made here that the server doesn't have yet.
  local?: LocalEdit
}

export type Conflict = {
  key: string
  entity: EntityType
  entity_id: string
  op_type: OpType
  // What this device wanted to send, and when it was edited.
  mine: Record<string, any>
  mine_edited_at: string
  // The server's copy (with last_change: { at, device_name }).
  theirs: Record<string, any>
}

export type SyncState = 'synced' | 'syncing' | 'offline'

export type SyncStatus = {
  state: SyncState
  // Ops waiting in the outbox.
  pending: number
  // Tasks with unsent edits here (outbox ops or local edits not queued yet).
  pendingTaskIds: string[]
  conflicts: Conflict[]
  lastError?: string
}

export interface Network {
  isOnline(): boolean
  onChange(listener: () => void): () => void
}

export type SyncClientOptions = {
  api: SyncApi
  dbName: string
  deviceId?: string | null
  network?: Network
  pullIntervalMs?: number
  // While the server is unreachable, try again this often.
  probeMs?: number
  random?: () => number
  // Rows pulled from the server (for screens showing cached data).
  onChanges?: (changes: Change[]) => void
  onRejected?: (op: Op, result: OpResult) => void
}

type OutboxRecord = Op & { seq?: number; created_at: string }

interface SyncDb extends DBSchema {
  entities: { key: string; value: EntityRecord; indexes: { type: string } }
  outbox: { key: number; value: OutboxRecord }
  meta: { key: string; value: any }
}

const MAX_BATCH = 50

export const browserNetwork: Network = {
  isOnline: () => typeof navigator === 'undefined' || navigator.onLine,
  onChange(listener) {
    window.addEventListener('online', listener)
    window.addEventListener('offline', listener)
    return () => {
      window.removeEventListener('online', listener)
      window.removeEventListener('offline', listener)
    }
  },
}

export function entityKey(type: EntityType, id: string): string {
  return `${type}:${id}`
}

export function entityOfOp(type: OpType): EntityType {
  return type === 'project_memory.patch' ? 'project_memory' : 'task'
}

// "Unsaved" on the dashboard: this device has unsent edits for the task, or the server says another one has.
export function isTaskUnsaved(task: { id: string; has_unsynced_changes?: boolean }, pendingTaskIds: Iterable<string>): boolean {
  return Boolean(task.has_unsynced_changes) || new Set(pendingTaskIds).has(task.id)
}

// A request that never got an HTTP answer (network down, server unreachable).
function isNetworkError(error: unknown): boolean {
  const status = (error as { status?: number; statusCode?: number } | null)?.status
    ?? (error as { statusCode?: number } | null)?.statusCode
  return !status
}

function sameJson(a: unknown, b: unknown): boolean {
  return JSON.stringify(a) === JSON.stringify(b)
}

export class SyncClient {
  private db!: IDBPDatabase<SyncDb>
  private listeners = new Set<(status: SyncStatus) => void>()
  private status: SyncStatus = { state: 'syncing', pending: 0, pendingTaskIds: [], conflicts: [] }
  private pulling: Promise<void> | null = null
  private pushing: Promise<void> | null = null
  private inFlight = new Set<number>()
  private reachable = true
  private pushAttempt = 0
  private retryTimer: ReturnType<typeof setTimeout> | undefined
  private probeTimer: ReturnType<typeof setTimeout> | undefined
  private pullTimer: ReturnType<typeof setInterval> | undefined
  private unsubscribeNetwork: (() => void) | null = null
  private deviceId: string | null

  constructor(private options: SyncClientOptions) {
    this.deviceId = options.deviceId ?? null
  }

  private get network(): Network {
    return this.options.network ?? browserNetwork
  }

  async open(): Promise<void> {
    if (this.db) return
    this.db = await openDB<SyncDb>(this.options.dbName, 1, {
      upgrade(db) {
        db.createObjectStore('entities', { keyPath: 'key' }).createIndex('type', 'type')
        db.createObjectStore('outbox', { keyPath: 'seq', autoIncrement: true })
        db.createObjectStore('meta')
      },
    })
    this.deviceId ??= (await this.db.get('meta', 'device_id')) ?? null
    await this.refreshStatus()
  }

  async start(): Promise<void> {
    await this.open()
    this.unsubscribeNetwork = this.network.onChange(() => {
      if (this.network.isOnline()) void this.sync()
      else void this.refreshStatus()
    })
    this.pullTimer = setInterval(() => void this.sync(), this.options.pullIntervalMs ?? 60_000)
    await this.sync()
  }

  stop(): void {
    clearInterval(this.pullTimer)
    clearTimeout(this.retryTimer)
    clearTimeout(this.probeTimer)
    this.unsubscribeNetwork?.()
    this.unsubscribeNetwork = null
    this.db?.close()
  }

  async setDeviceId(id: string): Promise<void> {
    this.deviceId = id
    await this.db.put('meta', id, 'device_id')
    void this.push()
  }

  // Another device changed something (a "sync.nudge"): pull now.
  nudge(): void {
    void this.sync()
  }

  async sync(): Promise<void> {
    await this.pull()
    await this.push()
  }

  isOnline(): boolean {
    return this.network.isOnline() && this.reachable
  }

  subscribe(listener: (status: SyncStatus) => void): () => void {
    this.listeners.add(listener)
    listener(this.status)
    return () => this.listeners.delete(listener)
  }

  getStatus(): SyncStatus {
    return this.status
  }

  // ---- Pull ----

  pull(): Promise<void> {
    if (this.pulling) return this.pulling

    this.pulling = (async () => {
      if (!this.network.isOnline()) return
      try {
        let after: number = (await this.db.get('meta', 'last_seq')) ?? 0
        for (;;) {
          const page = await this.options.api.pull(after, 500)
          await this.applyChanges(page.data)
          after = page.meta.next
          await this.db.put('meta', after, 'last_seq')
          if (!page.meta.has_more) break
        }
        this.markReachable(true)
      } catch (error) {
        this.requestFailed(error)
      }
    })().finally(() => {
      this.pulling = null
      void this.refreshStatus()
    })

    void this.refreshStatus()
    return this.pulling
  }

  private async applyChanges(changes: Change[]): Promise<void> {
    if (!changes.length) return

    const tx = this.db.transaction('entities', 'readwrite')
    for (const change of changes) {
      const key = entityKey(change.entity, change.entity_id)
      if (change.op === 'delete' || change.payload === null) {
        await tx.store.delete(key)
        continue
      }
      const existing = await tx.store.get(key)
      await tx.store.put({
        key,
        type: change.entity,
        id: change.entity_id,
        data: change.payload,
        row_version: change.payload.row_version ?? change.row_version ?? null,
        // Unsent edits here stay; pushing them shows whether they conflict.
        local: existing?.local,
      })
    }
    await tx.done

    this.options.onChanges?.(changes)
  }

  // ---- Entity cache ----

  getEntity(type: EntityType, id: string): Promise<EntityRecord | undefined> {
    return this.db.get('entities', entityKey(type, id))
  }

  async listEntities(type: EntityType): Promise<EntityRecord[]> {
    return this.db.getAllFromIndex('entities', 'type', type)
  }

  // A fresh copy fetched from the API: cache it (keeping local edits), unless the cache is newer.
  async putServerEntity(type: EntityType, payload: Record<string, any>): Promise<void> {
    const key = entityKey(type, payload.id)
    const existing = await this.db.get('entities', key)
    if (existing && (existing.row_version ?? 0) > (payload.row_version ?? 0)) return

    await this.db.put('entities', {
      key,
      type,
      id: payload.id,
      data: { ...existing?.data, ...payload },
      row_version: payload.row_version ?? existing?.row_version ?? null,
      local: existing?.local,
    })
  }

  // Keep an edit here at once (it survives reloads), made on top of server version base.
  async setLocal(type: EntityType, id: string, data: unknown, base: number): Promise<void> {
    const key = entityKey(type, id)
    const existing = await this.db.get('entities', key)
    await this.db.put('entities', {
      key,
      type,
      id,
      data: existing?.data ?? { id },
      row_version: existing?.row_version ?? base,
      local: { data, base_row_version: base, edited_at: new Date().toISOString() },
    })
    await this.refreshStatus()
  }

  // ---- Outbox and push ----

  async enqueue(op: Omit<Op, 'op_id'>): Promise<void> {
    const tx = this.db.transaction('outbox', 'readwrite')

    // A newer checkpoint replaces one that hasn't been sent yet: only the latest matters.
    let merged = false
    if (op.type === 'task.checkpoint') {
      for (const queued of await tx.store.getAll()) {
        if (queued.type === op.type && queued.entity_id === op.entity_id && !this.inFlight.has(queued.seq!)) {
          await tx.store.put({ ...queued, payload: op.payload })
          merged = true
          break
        }
      }
    }
    if (!merged) {
      await tx.store.add({ ...op, op_id: crypto.randomUUID(), created_at: new Date().toISOString() })
    }
    await tx.done

    await this.refreshStatus()
    void this.push()
  }

  async hasPendingOp(type: OpType, entityId: string): Promise<boolean> {
    return (await this.db.getAll('outbox')).some(op => op.type === type && op.entity_id === entityId)
  }

  // While the server is unreachable, new edits just wait: the retry timer (force) or the next
  // successful pull sends them, instead of every edit trying again at once.
  push(force = false): Promise<void> {
    if (this.pushing) return this.pushing

    this.pushing = (async () => {
      for (;;) {
        if (!this.network.isOnline() || !this.deviceId || (!this.reachable && !force)) return

        const queued = await this.db.getAll('outbox')
        if (!queued.length) return

        // One op per row per request: the next op on a row needs the version the previous one made.
        const seen = new Set<string>()
        const batch = queued.filter((op) => {
          const key = entityKey(entityOfOp(op.type), op.entity_id)
          if (seen.has(key)) return false
          seen.add(key)
          return true
        }).slice(0, MAX_BATCH)

        batch.forEach(op => this.inFlight.add(op.seq!))
        let results: OpResult[]
        try {
          results = await this.options.api.push(this.deviceId, batch.map(({ op_id, type, entity_id, base_row_version, payload }) => (
            { op_id, type, entity_id, base_row_version, payload }
          )))
        } catch (error) {
          this.inFlight.clear()
          this.requestFailed(error)
          this.pushAttempt++
          clearTimeout(this.retryTimer)
          this.retryTimer = setTimeout(
            () => void this.push(true),
            backoffDelay(this.pushAttempt, 1000, 60_000, this.options.random),
          )
          return
        }

        this.inFlight.clear()
        this.pushAttempt = 0
        this.markReachable(true)

        for (const result of results) {
          const op = batch.find(item => item.op_id === result.op_id)
          if (op) await this.handleResult(op, result)
        }
        await this.refreshStatus()
      }
    })().finally(() => {
      this.pushing = null
      void this.refreshStatus()
    })

    void this.refreshStatus()
    return this.pushing
  }

  private async handleResult(op: OutboxRecord, result: OpResult): Promise<void> {
    const entity = entityOfOp(op.type)
    const key = entityKey(entity, op.entity_id)
    // One transaction for everything: awaiting anything else in between would close it.
    const tx = this.db.transaction(['outbox', 'entities', 'meta'], 'readwrite')
    const outbox = tx.objectStore('outbox')
    const entities = tx.objectStore('entities')
    const meta = tx.objectStore('meta')

    await outbox.delete(op.seq!)
    const record = await entities.get(key)

    if (result.status === 'applied') {
      const version = result.row_version!

      // Later ops on this row were made on top of this one: move them to the new version.
      for (const later of await outbox.getAll()) {
        if (entityOfOp(later.type) === entity && later.entity_id === op.entity_id) {
          await outbox.put({ ...later, base_row_version: version })
        }
      }

      if (record) {
        record.row_version = version
        record.data = { ...record.data, row_version: version }
        if (op.type === 'task.update') Object.assign(record.data, op.payload)
        if (op.type === 'task.checkpoint') {
          record.data.checkpoint = op.payload.checkpoint
          // Sent and saved: no unsent edit left, unless more typing happened since.
          if (record.local && sameJson(record.local.data, op.payload.checkpoint)) delete record.local
          else if (record.local) record.local.base_row_version = version
        }
        await entities.put(record)
      }
    }

    if (result.status === 'conflict') {
      // Ops of the same kind on this row are part of the same edit; hold them until the user decides.
      let mine = op.payload
      for (const later of await outbox.getAll()) {
        if (later.type === op.type && later.entity_id === op.entity_id) {
          mine = op.type === 'task.update' ? { ...mine, ...later.payload }
            : op.type === 'project_memory.patch' ? { patch: [...mine.patch, ...later.payload.patch] }
              : later.payload
          await outbox.delete(later.seq!)
        }
      }
      if (op.type === 'task.checkpoint' && record?.local) mine = { checkpoint: record.local.data }

      const theirs = result.server ?? {}
      const conflict: Conflict = {
        key: `${key}:${op.type}`,
        entity,
        entity_id: op.entity_id,
        op_type: op.type,
        mine,
        mine_edited_at: record?.local?.edited_at ?? op.created_at,
        theirs,
      }
      const conflicts: Conflict[] = ((await meta.get('conflicts')) ?? []).filter((item: Conflict) => item.key !== conflict.key)
      await meta.put([...conflicts, conflict], 'conflicts')

      if (record) {
        record.data = theirs
        record.row_version = theirs.row_version ?? record.row_version
        await entities.put(record)
      }
    }

    await tx.done

    if (result.status === 'rejected') {
      this.status = { ...this.status, lastError: Object.values(result.errors ?? {}).flat()[0] ?? 'An edit was rejected.' }
      this.options.onRejected?.(op, result)
    }
  }

  // ---- Conflicts ----

  async hasConflict(entity: EntityType, id: string): Promise<boolean> {
    const conflicts: Conflict[] = (await this.db.get('meta', 'conflicts')) ?? []
    return conflicts.some(conflict => conflict.entity === entity && conflict.entity_id === id)
  }

  // "Keep mine": send my version again, on top of the server's. "Use theirs": drop my edit.
  async resolveConflict(key: string, choice: 'mine' | 'theirs'): Promise<void> {
    const conflicts: Conflict[] = (await this.db.get('meta', 'conflicts')) ?? []
    const conflict = conflicts.find(item => item.key === key)
    if (!conflict) return

    await this.db.put('meta', conflicts.filter(item => item.key !== key), 'conflicts')
    const record = await this.getEntity(conflict.entity, conflict.entity_id)
    const base = conflict.theirs.row_version ?? record?.row_version ?? 1

    if (choice === 'mine') {
      if (record?.local) await this.db.put('entities', { ...record, local: { ...record.local, base_row_version: base } })
      await this.enqueue({ type: conflict.op_type, entity_id: conflict.entity_id, base_row_version: base, payload: conflict.mine })
    } else {
      if (record) {
        const { local: _dropped, ...kept } = record
        await this.db.put('entities', { ...kept, data: conflict.theirs, row_version: base })
      }
      await this.refreshStatus()
    }
  }

  // ---- Closing the page ----

  // The tab is closing with unsent edits: ask the server to flag those tasks (best effort).
  flushBeacon(): void {
    if (this.status.pendingTaskIds.length) this.options.api.markUnsynced(this.status.pendingTaskIds)
  }

  // ---- Status ----

  private requestFailed(error: unknown): void {
    if (isNetworkError(error)) {
      this.markReachable(false)
      // Keep trying quietly every few seconds; the first request that gets through brings us back.
      clearTimeout(this.probeTimer)
      this.probeTimer = setTimeout(() => void this.sync(), this.options.probeMs ?? 5000)
    } else {
      this.status = { ...this.status, lastError: error instanceof Error ? error.message : String(error) }
    }
  }

  private markReachable(reachable: boolean): void {
    if (reachable === this.reachable) return
    this.reachable = reachable
    if (reachable) clearTimeout(this.probeTimer)
    void this.refreshStatus()
  }

  async refreshStatus(): Promise<void> {
    if (!this.db) return
    const [outbox, entities, conflicts] = await Promise.all([
      this.db.getAll('outbox'),
      this.db.getAllFromIndex('entities', 'type', 'task'),
      this.db.get('meta', 'conflicts'),
    ])

    const pendingTaskIds = new Set<string>()
    for (const op of outbox) if (entityOfOp(op.type) === 'task') pendingTaskIds.add(op.entity_id)
    for (const task of entities) if (task.local) pendingTaskIds.add(task.id)

    const busy = this.pulling !== null || this.pushing !== null || outbox.length > 0
    this.status = {
      state: !this.isOnline() ? 'offline' : busy ? 'syncing' : 'synced',
      pending: outbox.length,
      pendingTaskIds: [...pendingTaskIds],
      conflicts: conflicts ?? [],
      lastError: this.status.lastError,
    }
    for (const listener of this.listeners) listener(this.status)
  }
}
