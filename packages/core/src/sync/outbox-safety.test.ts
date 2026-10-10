// "Nothing lost": the last edit made on a device must always end up on the server, or still be
// waiting on the device (outbox or local copy) — never neither. These tests replay the interleavings
// that can break that: replies that never arrive, tabs closing mid-push, reopening, rejections.

import 'fake-indexeddb/auto'
import fc from 'fast-check'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { SyncClient, type Network, type Op, type OpResult, type SyncApi } from './client'
import { TaskCheckpoint } from './checkpoint'

const TASK = '01a12000-0000-7000-8000-00000000000a'

// Only timers are faked (and never advanced here): the client's retry and probe timers stay idle,
// so every push in these tests happens exactly when the test says. fake-indexeddb uses setImmediate.
beforeEach(() => vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'setInterval', 'clearInterval'] }))
afterEach(() => vi.useRealTimers())

type Mode = 'ok' | 'fail' | 'lose-ack' | 'reject'

// Like the real server: applies an op if its base matches, and remembers every result by op_id,
// so a repeated op_id gets the stored result without being applied again.
function server() {
  const state = { version: 1, notes: '', mode: 'ok' as Mode }
  const stored = new Map<string, OpResult>()

  const api: SyncApi = {
    pull: async () => ({ data: [], meta: { next: 0, has_more: false } }),
    markUnsynced: () => {},
    push: async (_device: string, ops: Op[]) => {
      if (state.mode === 'fail') throw new TypeError('Failed to fetch') // never reached the server
      const results = ops.map((op): OpResult => {
        const seen = stored.get(op.op_id)
        if (seen) return seen
        let result: OpResult
        if (state.mode === 'reject') {
          result = { op_id: op.op_id, status: 'rejected', errors: { payload: ['rejected for the test'] } }
        } else if (op.base_row_version !== state.version) {
          result = { op_id: op.op_id, status: 'conflict', server: { id: TASK, row_version: state.version, checkpoint: { data: { notes: state.notes } } } }
        } else {
          state.version++
          state.notes = op.payload.checkpoint.data.notes
          result = { op_id: op.op_id, status: 'applied', row_version: state.version }
        }
        stored.set(op.op_id, result)
        return result
      })
      // Applied on the server, but the reply is lost (tab closed, connection dropped).
      if (state.mode === 'lose-ack') throw new TypeError('Failed to fetch')
      return results
    },
  }
  return { state, api }
}

const online: Network = { isOnline: () => true, onChange: () => () => {} }

let dbCount = 0
function device(api: SyncApi, dbName = `safety-${++dbCount}`) {
  const client = new SyncClient({ api, network: online, dbName, deviceId: 'device-1', random: () => 1 })
  return { client, dbName, editor: new TaskCheckpoint(client, TASK) }
}

// Let IndexedDB work and push loops finish.
async function settle() {
  for (let i = 0; i < 30; i++) await new Promise(resolve => setImmediate(resolve))
}

async function edit(dev: ReturnType<typeof device>, notes: string) {
  await dev.editor.update({ data: { notes } })
  await dev.editor.flush()
  await settle()
}

// Where the edit is now: on the server, waiting in the outbox, kept as the local copy, or nowhere.
async function whereIs(notes: string, dev: ReturnType<typeof device>, srv: ReturnType<typeof server>) {
  const places: string[] = []
  if (srv.state.notes === notes) places.push('server')
  const outbox = await (dev.client as any).db.getAll('outbox') as Op[]
  if (outbox.some(op => op.payload.checkpoint?.data?.notes === notes)) places.push('outbox')
  const record = await dev.client.getEntity('task', TASK)
  if (record?.local && (record.local.data as any)?.data?.notes === notes) places.push('local')
  return places
}

describe('the lost offline edit (step 9 browser check)', () => {
  it('keeps a newer edit when the older op was applied but its reply never came', async () => {
    const srv = server()
    const dev = device(srv.api)
    await dev.client.open()
    await dev.editor.load({ id: TASK, row_version: 1, checkpoint: {} })

    // 1. An edit is sent and applied, but the reply is lost: the op stays in the outbox.
    srv.state.mode = 'lose-ack'
    await edit(dev, 'first edit')
    await dev.client.push(true)
    await settle()
    expect(srv.state.notes).toBe('first edit')

    // 2. Pushes fail for a while; the user keeps typing. The newer edit replaces the waiting op.
    srv.state.mode = 'fail'
    await edit(dev, 'second edit')
    await dev.client.push(true)
    await settle()

    // 3. The network is back.
    srv.state.mode = 'ok'
    await dev.client.push(true)
    await settle()

    expect(await whereIs('second edit', dev, srv)).not.toEqual([])
    expect(srv.state.notes).toBe('second edit')
  })

  it('keeps it when the tab closes after the server applied the older op, and is reopened', async () => {
    const srv = server()
    const first = device(srv.api)
    await first.client.open()
    await first.editor.load({ id: TASK, row_version: 1, checkpoint: {} })

    // Closing the tab: the push reaches the server, the page is gone before the reply.
    srv.state.mode = 'lose-ack'
    await edit(first, 'typed before closing')
    await first.client.push(true)
    await settle()
    first.client.stop()

    // Reopened while pushes still fail; the user types again.
    srv.state.mode = 'fail'
    const reopened = device(srv.api, first.dbName)
    await reopened.client.open()
    await reopened.editor.load({ id: TASK, row_version: 2, checkpoint: { data: { notes: 'typed before closing' } } })
    await edit(reopened, 'typed after reopening')

    srv.state.mode = 'ok'
    await reopened.client.push(true)
    await settle()

    expect(srv.state.notes).toBe('typed after reopening')
  })

  it('does not drop a newer edit when an older op of the same task is rejected', async () => {
    const srv = server()
    const dev = device(srv.api)
    await dev.client.open()
    await dev.editor.load({ id: TASK, row_version: 1, checkpoint: {} })

    // The older op's request gets through and is rejected, but the reply is held up while the
    // user types again: the newer edit must survive the rejection.
    srv.state.mode = 'reject'
    await dev.editor.update({ data: { notes: 'bad' } })
    await dev.editor.flush()
    const pushing = dev.client.push(true)
    await edit(dev, 'good')
    await pushing
    await settle()

    expect(await whereIs('good', dev, srv)).not.toEqual([])
  })
})

describe('reopening never loses the local copy', () => {
  it('keeps unsent edits when the server copy is loaded first', async () => {
    const srv = server()
    srv.state.mode = 'fail'
    const first = device(srv.api)
    await first.client.open()
    await first.editor.load({ id: TASK, row_version: 1, checkpoint: {} })
    await first.editor.update({ data: { notes: 'unsent' } })
    // The tab closes before the 2 s send delay: no op was queued, only the local copy exists.
    first.client.stop()

    const reopened = device(srv.api, first.dbName)
    await reopened.client.open()
    const loaded = await reopened.editor.load({ id: TASK, row_version: 1, checkpoint: { data: { notes: '' } } })

    expect(loaded).toMatchObject({ source: 'local', checkpoint: { data: { notes: 'unsent' } } })
    // And it was queued again, so it will be sent.
    expect(await whereIs('unsent', reopened, srv)).toEqual(['outbox', 'local'])
  })
})

// Random sequences of what can happen to one device. After every sequence, once the network is back,
// the last edit must be on the server; and at every point in between it must be somewhere.
describe('property: the last edit is never lost', () => {
  type Action =
    | { kind: 'edit' }
    | { kind: 'push'; mode: Mode }
    | { kind: 'close' }

  const action = fc.oneof(
    { weight: 3, arbitrary: fc.constant<Action>({ kind: 'edit' }) },
    { weight: 3, arbitrary: fc.constantFrom<Mode>('ok', 'fail', 'lose-ack').map((mode): Action => ({ kind: 'push', mode })) },
    { weight: 1, arbitrary: fc.constant<Action>({ kind: 'close' }) },
  )

  it('holds for any order of edits, pushes, lost replies, failures, closes and reopens', async () => {
    await fc.assert(fc.asyncProperty(fc.array(action, { minLength: 1, maxLength: 14 }), async (actions) => {
      const srv = server()
      let dev = device(srv.api)
      await dev.client.open()
      await dev.editor.load({ id: TASK, row_version: 1, checkpoint: {} })
      let last: string | null = null

      for (const [i, step] of actions.entries()) {
        if (step.kind === 'edit') {
          last = `edit ${i}`
          await edit(dev, last)
        } else if (step.kind === 'push') {
          srv.state.mode = step.mode
          await dev.client.push(true)
          await settle()
        } else {
          // Close and reopen (with the server copy loaded first, as the page does).
          dev.client.stop()
          dev = device(srv.api, dev.dbName)
          await dev.client.open()
          srv.state.mode = 'fail'
          await dev.editor.load({ id: TASK, row_version: srv.state.version, checkpoint: { data: { notes: srv.state.notes } } })
        }

        if (last !== null) expect(await whereIs(last, dev, srv)).not.toEqual([])
      }

      // The network is back for good.
      srv.state.mode = 'ok'
      await dev.client.push(true)
      await settle()
      if (last !== null) expect(srv.state.notes).toBe(last)
      dev.client.stop()
    }), { numRuns: 150 })
  }, 120_000)
})
