import 'fake-indexeddb/auto'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { isTaskUnsaved, SyncClient, type Change, type Network, type Op, type OpResult, type SyncApi } from './client'
import { TaskCheckpoint } from './checkpoint'

// Only timers are faked: fake-indexeddb schedules its own work with setImmediate.
const fakeTimers = { toFake: ['setTimeout', 'clearTimeout', 'setInterval', 'clearInterval', 'Date'] as const }

const TASK = '01a12000-0000-7000-8000-000000000001'
const OTHER = '01a12000-0000-7000-8000-000000000002'

function onlineNetwork(): Network & { set(online: boolean): void } {
  let online = true
  let listener: () => void = () => {}
  return {
    isOnline: () => online,
    onChange: (l) => {
      listener = l
      return () => {}
    },
    set(value) {
      online = value
      listener()
    },
  }
}

// A server that applies ops if their base matches, like the real one.
function fakeServer(versions: Record<string, number> = {}) {
  const pushed: Op[][] = []
  const api = {
    pull: vi.fn<SyncApi['pull']>(async () => ({ data: [], meta: { next: 0, has_more: false } })),
    push: vi.fn<SyncApi['push']>(async (_device, ops) => {
      pushed.push(ops)
      return ops.map((op): OpResult => {
        const current = versions[op.entity_id] ?? 1
        if (op.base_row_version !== current) {
          return { op_id: op.op_id, status: 'conflict', server: { id: op.entity_id, row_version: current, checkpoint: { data: { notes: 'theirs' } } } }
        }
        versions[op.entity_id] = current + 1
        return { op_id: op.op_id, status: 'applied', row_version: current + 1 }
      })
    }),
    markUnsynced: vi.fn(),
  }
  return { api, pushed, versions }
}

let dbCount = 0
function client(api: SyncApi, network = onlineNetwork()) {
  return new SyncClient({ api, network, dbName: `test-${++dbCount}`, deviceId: 'device-1', random: () => 1, pullIntervalMs: 60_000 })
}

// Let the client's async work (IndexedDB, push loops) run to the end.
async function settle() {
  for (let i = 0; i < 20; i++) await new Promise(resolve => setImmediate(resolve))
}

beforeEach(() => vi.useFakeTimers(fakeTimers))
afterEach(() => vi.useRealTimers())

describe('outbox', () => {
  it('pushes ops in order, one per row per request, rebasing later ops on the new version', async () => {
    const { api, pushed, versions } = fakeServer({ [TASK]: 1, [OTHER]: 1 })
    const sync = client(api)
    await sync.open()

    // Offline while editing, so everything queues up first.
    api.push.mockRejectedValueOnce(new TypeError('Failed to fetch'))
    await sync.enqueue({ type: 'task.update', entity_id: TASK, base_row_version: 1, payload: { title: 'One' } })
    await sync.enqueue({ type: 'task.update', entity_id: OTHER, base_row_version: 1, payload: { title: 'Other' } })
    await sync.enqueue({ type: 'task.update', entity_id: TASK, base_row_version: 1, payload: { mode: 'semi' } })
    await settle()

    await vi.advanceTimersByTimeAsync(1000)
    await settle()

    // (The failed first attempt never reached the server.)
    expect(pushed.map(batch => batch.map(op => [op.entity_id, op.base_row_version]))).toEqual([
      [[TASK, 1], [OTHER, 1]],
      // The second op on TASK goes on its own, on top of the version the first made.
      [[TASK, 2]],
    ])
    expect(versions[TASK]).toBe(3)
    expect(sync.getStatus().pending).toBe(0)
  })

  it('retries with growing waits while the server is unreachable', async () => {
    const { api } = fakeServer({ [TASK]: 1 })
    api.push.mockRejectedValueOnce(new TypeError('Failed to fetch')).mockRejectedValueOnce(new TypeError('Failed to fetch'))
    const sync = client(api)
    await sync.open()

    await sync.enqueue({ type: 'task.update', entity_id: TASK, base_row_version: 1, payload: { title: 'x' } })
    await settle()
    expect(api.push).toHaveBeenCalledTimes(1)
    expect(sync.getStatus().state).toBe('offline')

    await vi.advanceTimersByTimeAsync(999)
    expect(api.push).toHaveBeenCalledTimes(1)
    await vi.advanceTimersByTimeAsync(1)
    await settle()
    expect(api.push).toHaveBeenCalledTimes(2)

    // The second wait is twice as long.
    await vi.advanceTimersByTimeAsync(1999)
    expect(api.push).toHaveBeenCalledTimes(2)
    await vi.advanceTimersByTimeAsync(1)
    await settle()
    expect(api.push).toHaveBeenCalledTimes(3)
    expect(sync.getStatus()).toMatchObject({ state: 'synced', pending: 0 })
  })

  it('keeps checking every 5 s while the server is unreachable, then sends what waited', async () => {
    const { api, pushed } = fakeServer({ [TASK]: 1 })
    const down = new TypeError('Failed to fetch')
    api.pull.mockRejectedValueOnce(down).mockRejectedValueOnce(down).mockRejectedValueOnce(down)
    const sync = client(api)
    await sync.open()

    await sync.pull()
    await settle()
    expect(sync.getStatus().state).toBe('offline')
    await sync.enqueue({ type: 'task.update', entity_id: TASK, base_row_version: 1, payload: { title: 'Typed while down' } })
    expect(api.push).not.toHaveBeenCalled()

    // Two more failed checks, 5 s apart; it doesn't give up after one.
    await vi.advanceTimersByTimeAsync(5000)
    await settle()
    await vi.advanceTimersByTimeAsync(5000)
    await settle()
    expect(api.pull).toHaveBeenCalledTimes(3)
    expect(sync.getStatus().state).toBe('offline')

    // The server is back: the next check gets through and the waiting edit is sent.
    await vi.advanceTimersByTimeAsync(5000)
    await settle()
    expect(api.pull).toHaveBeenCalledTimes(4)
    expect(pushed.flat().map(op => op.payload.title)).toEqual(['Typed while down'])
    expect(sync.getStatus()).toMatchObject({ state: 'synced', pending: 0 })
  })

  it('keeps only the latest unsent checkpoint of a task', async () => {
    const { api, pushed } = fakeServer({ [TASK]: 1 })
    const network = onlineNetwork()
    network.set(false)
    const sync = client(api, network)
    await sync.open()

    for (const notes of ['a', 'ab', 'abc']) {
      await sync.enqueue({ type: 'task.checkpoint', entity_id: TASK, base_row_version: 1, payload: { checkpoint: { data: { notes } } } })
    }
    expect(sync.getStatus()).toMatchObject({ state: 'offline', pending: 1 })

    network.set(true)
    await sync.push()
    await settle()
    expect(pushed).toHaveLength(1)
    expect(pushed[0]![0]!.payload.checkpoint.data.notes).toBe('abc')
  })
})

describe('checkpoints', () => {
  it('prefers a local copy with unsent edits over an older server copy', async () => {
    const { api } = fakeServer()
    const network = onlineNetwork()
    network.set(false)
    const sync = client(api, network)
    await sync.open()

    const editor = new TaskCheckpoint(sync, TASK, { debounceMs: 2000 })
    await editor.load({ id: TASK, row_version: 1, checkpoint: { data: { notes: 'saved' } } })
    await editor.update({ view: { tab: 'notes' }, data: { notes: 'typed offline' } })

    // After a reload, the server copy (still version 1) doesn't win over the unsent edit.
    const reopened = new TaskCheckpoint(sync, TASK)
    const loaded = await reopened.load({ id: TASK, row_version: 1, checkpoint: { data: { notes: 'saved' } } })
    expect(loaded).toEqual({ source: 'local', checkpoint: { view: { tab: 'notes' }, data: { notes: 'typed offline' } } })

    // With no edits here, the server copy is used.
    const fresh = await new TaskCheckpoint(sync, OTHER).load({ id: OTHER, row_version: 4, checkpoint: { data: { notes: 'server' } } })
    expect(fresh).toEqual({ source: 'server', checkpoint: { data: { notes: 'server' } } })
  })

  it('queues a checkpoint op 2 s after the last change, and clears the local copy once saved', async () => {
    const { api, pushed } = fakeServer({ [TASK]: 1 })
    const sync = client(api)
    await sync.open()
    const editor = new TaskCheckpoint(sync, TASK, { debounceMs: 2000 })
    await editor.load({ id: TASK, row_version: 1, checkpoint: {} })

    await editor.update({ data: { notes: 'H' } })
    await vi.advanceTimersByTimeAsync(1500)
    await editor.update({ data: { notes: 'Hi' } })
    await vi.advanceTimersByTimeAsync(1999)
    expect(api.push).not.toHaveBeenCalled()
    expect(sync.getStatus().pendingTaskIds).toEqual([TASK])

    await vi.advanceTimersByTimeAsync(1)
    await settle()
    expect(pushed[0]![0]).toMatchObject({ type: 'task.checkpoint', base_row_version: 1, payload: { checkpoint: { data: { notes: 'Hi' } } } })

    const record = await sync.getEntity('task', TASK)
    expect(record?.local).toBeUndefined()
    expect(record).toMatchObject({ row_version: 2, data: { checkpoint: { data: { notes: 'Hi' } } } })
    expect(sync.getStatus().pendingTaskIds).toEqual([])
  })
})

describe('conflicts', () => {
  async function conflicted() {
    const { api, pushed, versions } = fakeServer({ [TASK]: 3 }) // another device moved it to 3
    const sync = client(api)
    await sync.open()
    const editor = new TaskCheckpoint(sync, TASK)
    await editor.load({ id: TASK, row_version: 2, checkpoint: {} })
    await editor.update({ data: { notes: 'mine' } })
    await editor.flush()
    await settle()
    return { sync, api, pushed, versions }
  }

  it('records the conflict with both versions and holds the edit', async () => {
    const { sync } = await conflicted()

    expect(sync.getStatus().pending).toBe(0)
    expect(sync.getStatus().conflicts).toMatchObject([{
      entity: 'task',
      entity_id: TASK,
      op_type: 'task.checkpoint',
      mine: { checkpoint: { data: { notes: 'mine' } } },
      theirs: { row_version: 3, checkpoint: { data: { notes: 'theirs' } } },
    }])
    // Still unsent here, so still "Unsaved".
    expect(sync.getStatus().pendingTaskIds).toEqual([TASK])
  })

  it('"Keep mine" sends it again on the new version', async () => {
    const { sync, pushed, versions } = await conflicted()

    await sync.resolveConflict(sync.getStatus().conflicts[0]!.key, 'mine')
    await settle()

    expect(pushed.at(-1)![0]).toMatchObject({ base_row_version: 3, payload: { checkpoint: { data: { notes: 'mine' } } } })
    expect(versions[TASK]).toBe(4)
    expect(sync.getStatus()).toMatchObject({ conflicts: [], pendingTaskIds: [] })
  })

  it('"Use theirs" drops the local edit', async () => {
    const { sync, api } = await conflicted()
    const pushes = api.push.mock.calls.length

    await sync.resolveConflict(sync.getStatus().conflicts[0]!.key, 'theirs')
    await settle()

    const loaded = await new TaskCheckpoint(sync, TASK).load()
    expect(loaded).toEqual({ source: 'server', checkpoint: { data: { notes: 'theirs' } } })
    expect(api.push).toHaveBeenCalledTimes(pushes)
    expect(sync.getStatus()).toMatchObject({ conflicts: [], pendingTaskIds: [] })
  })
})

describe('pull', () => {
  it('applies pages of changes and tombstones, and remembers where it stopped', async () => {
    const { api } = fakeServer()
    const changes: Change[] = [
      { seq: 1, entity: 'project', entity_id: 'p1', op: 'upsert', row_version: null, payload: { id: 'p1', name: 'Film' } },
      { seq: 2, entity: 'task', entity_id: TASK, op: 'upsert', row_version: 1, payload: { id: TASK, title: 'A', row_version: 1 } },
      { seq: 3, entity: 'task', entity_id: OTHER, op: 'upsert', row_version: 1, payload: { id: OTHER, title: 'B', row_version: 1 } },
      { seq: 4, entity: 'task', entity_id: OTHER, op: 'delete', row_version: null, payload: null },
    ]
    api.pull
      .mockResolvedValueOnce({ data: changes.slice(0, 2), meta: { next: 2, has_more: true } })
      .mockResolvedValueOnce({ data: changes.slice(2), meta: { next: 4, has_more: false } })
      .mockResolvedValue({ data: [], meta: { next: 4, has_more: false } })
    const sync = client(api)
    await sync.open()

    await sync.pull()

    expect(api.pull.mock.calls.map(([after]) => after)).toEqual([0, 2])
    expect((await sync.listEntities('task')).map(task => task.data.title)).toEqual(['A'])
    expect((await sync.getEntity('project', 'p1'))?.data.name).toBe('Film')

    await sync.pull()
    expect(api.pull).toHaveBeenLastCalledWith(4, 500)
  })

  it('keeps unsent edits when a pulled change arrives for the same task', async () => {
    const { api } = fakeServer()
    api.pull.mockResolvedValueOnce({
      data: [{ seq: 9, entity: 'task', entity_id: TASK, op: 'upsert', row_version: 5, payload: { id: TASK, row_version: 5, title: 'Renamed elsewhere' } }],
      meta: { next: 9, has_more: false },
    })
    const network = onlineNetwork()
    const sync = client(api, network)
    await sync.open()
    network.set(false)
    await sync.setLocal('task', TASK, { data: { notes: 'mine' } }, 4)
    network.set(true)

    await sync.pull()

    const record = await sync.getEntity('task', TASK)
    expect(record).toMatchObject({ row_version: 5, data: { title: 'Renamed elsewhere' }, local: { data: { data: { notes: 'mine' } }, base_row_version: 4 } })
  })
})

describe('Unsaved', () => {
  it('is set by the server flag or by unsent edits on this device', async () => {
    const { api } = fakeServer({ [TASK]: 1 })
    const network = onlineNetwork()
    network.set(false)
    const sync = client(api, network)
    await sync.open()

    expect(isTaskUnsaved({ id: TASK, has_unsynced_changes: false }, sync.getStatus().pendingTaskIds)).toBe(false)
    expect(isTaskUnsaved({ id: TASK, has_unsynced_changes: true }, [])).toBe(true)

    await sync.enqueue({ type: 'task.update', entity_id: TASK, base_row_version: 1, payload: { title: 'x' } })
    expect(isTaskUnsaved({ id: TASK }, sync.getStatus().pendingTaskIds)).toBe(true)
    expect(isTaskUnsaved({ id: OTHER }, sync.getStatus().pendingTaskIds)).toBe(false)

    // The page closes with it unsent: the server is asked to flag the task.
    sync.flushBeacon()
    expect(api.markUnsynced).toHaveBeenCalledWith([TASK])

    network.set(true)
    await sync.push()
    await settle()
    expect(isTaskUnsaved({ id: TASK }, sync.getStatus().pendingTaskIds)).toBe(false)
  })
})
