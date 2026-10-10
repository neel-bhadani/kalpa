import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { BrowserWorker, type ClaimedJob, type JobHandler, type Visibility, type WorkerApi } from './worker'
import { demoCount } from './demo-count'

// A handler that runs until the test finishes it (or the job is stopped).
function controllableHandler() {
  let finish: (output?: unknown) => void = () => {}
  const handler: JobHandler = (_input, { signal }) => new Promise((resolve, reject) => {
    finish = resolve
    signal.addEventListener('abort', () => reject(new Error('aborted')))
  })
  return { handler, finish: (output?: unknown) => finish(output) }
}

function fakeApi(jobs: ClaimedJob[], heartbeatStatus = 'running') {
  const queue = [...jobs]
  return {
    claim: vi.fn(async () => queue.shift() ?? null),
    heartbeat: vi.fn(async () => ({ status: heartbeatStatus })),
    complete: vi.fn(async () => {}),
    fail: vi.fn(async () => {}),
  } satisfies WorkerApi
}

function fakeVisibility(hidden = false) {
  let listener: () => void = () => {}
  const visibility: Visibility & { set(value: boolean): void } = {
    isHidden: () => hidden,
    onChange: (l) => {
      listener = l
      return () => {}
    },
    set(value) {
      hidden = value
      listener()
    },
  }
  return visibility
}

const job = (id: string, type = 'test.job'): ClaimedJob => ({ id, type, input: {} })

describe('BrowserWorker', () => {
  beforeEach(() => vi.useFakeTimers())
  afterEach(() => vi.useRealTimers())

  it('claims only when idle', async () => {
    const { handler, finish } = controllableHandler()
    const api = fakeApi([job('a'), job('b')])
    const worker = new BrowserWorker({ api, handlers: { 'test.job': handler }, visibility: fakeVisibility() })

    worker.start()
    await vi.advanceTimersByTimeAsync(0)
    expect(api.claim).toHaveBeenCalledTimes(1)
    expect(worker.isBusy).toBe(true)

    // A long job: no more claims however long it runs.
    await vi.advanceTimersByTimeAsync(60_000)
    expect(api.claim).toHaveBeenCalledTimes(1)

    finish({ ok: true })
    await vi.advanceTimersByTimeAsync(0)
    expect(api.complete).toHaveBeenCalledWith('a', { ok: true })
    // Idle again: it asks for the next job at once.
    expect(api.claim).toHaveBeenCalledTimes(2)
    worker.stop()
  })

  it('polls every 5 s while there is nothing to do', async () => {
    const api = fakeApi([])
    const worker = new BrowserWorker({ api, handlers: {}, visibility: fakeVisibility() })

    worker.start()
    await vi.advanceTimersByTimeAsync(0)
    await vi.advanceTimersByTimeAsync(14_999)
    expect(api.claim).toHaveBeenCalledTimes(3)
    worker.stop()
  })

  it('claims at once when poked instead of waiting for the next poll', async () => {
    const api = fakeApi([])
    const worker = new BrowserWorker({ api, handlers: {}, visibility: fakeVisibility() })

    worker.start()
    await vi.advanceTimersByTimeAsync(0)
    expect(api.claim).toHaveBeenCalledTimes(1)

    worker.poke()
    await vi.advanceTimersByTimeAsync(0)
    expect(api.claim).toHaveBeenCalledTimes(2)
    worker.stop()
  })

  it('sends a heartbeat when it starts and then every 15 s', async () => {
    const { handler, finish } = controllableHandler()
    const api = fakeApi([job('a')])
    const worker = new BrowserWorker({ api, handlers: { 'test.job': handler }, visibility: fakeVisibility() })

    worker.start()
    await vi.advanceTimersByTimeAsync(0)
    expect(api.heartbeat).toHaveBeenCalledTimes(1)

    await vi.advanceTimersByTimeAsync(14_999)
    expect(api.heartbeat).toHaveBeenCalledTimes(1)
    await vi.advanceTimersByTimeAsync(1)
    expect(api.heartbeat).toHaveBeenCalledTimes(2)
    await vi.advanceTimersByTimeAsync(15_000)
    expect(api.heartbeat).toHaveBeenCalledTimes(3)

    finish()
    await vi.advanceTimersByTimeAsync(0)
    // No heartbeats once the job is done.
    await vi.advanceTimersByTimeAsync(30_000)
    expect(api.heartbeat).toHaveBeenCalledTimes(3)
    worker.stop()
  })

  it('does not claim while the tab is hidden, and starts again when it is shown', async () => {
    const visibility = fakeVisibility(true)
    const api = fakeApi([job('a')])
    const worker = new BrowserWorker({ api, handlers: { 'test.job': async () => ({}) }, visibility })

    worker.start()
    await vi.advanceTimersByTimeAsync(60_000)
    expect(api.claim).not.toHaveBeenCalled()

    visibility.set(false)
    await vi.advanceTimersByTimeAsync(0)
    expect(api.claim).toHaveBeenCalled()
    expect(api.complete).toHaveBeenCalledWith('a', {})
    worker.stop()
  })

  it('stops the handler when a heartbeat says the job was cancelled', async () => {
    const { handler } = controllableHandler()
    const api = fakeApi([job('a')], 'cancelled')
    const worker = new BrowserWorker({ api, handlers: { 'test.job': handler }, visibility: fakeVisibility() })

    worker.start()
    await vi.advanceTimersByTimeAsync(0)

    expect(worker.isBusy).toBe(false)
    expect(api.complete).not.toHaveBeenCalled()
    expect(api.fail).not.toHaveBeenCalled()
    worker.stop()
  })

  it('reports a failure for an unknown job type or a handler error', async () => {
    const api = fakeApi([job('a', 'nope'), job('b')])
    const worker = new BrowserWorker({
      api,
      handlers: { 'test.job': async () => { throw new Error('GPU lost') } },
      visibility: fakeVisibility(),
    })

    worker.start()
    // The second job is claimed on the next timer tick after the first one fails.
    await vi.advanceTimersByTimeAsync(10)

    expect(api.fail).toHaveBeenNthCalledWith(1, 'a', 'This browser can\'t run "nope" jobs.')
    expect(api.fail).toHaveBeenNthCalledWith(2, 'b', 'GPU lost')
    worker.stop()
  })

  it('sends progress at most once a second', async () => {
    const api = fakeApi([{ id: 'a', type: 'demo.count', input: { n: 10, interval_ms: 100 } }])
    const worker = new BrowserWorker({ api, handlers: { 'demo.count': demoCount }, visibility: fakeVisibility() })

    worker.start()
    await vi.advanceTimersByTimeAsync(0)
    await vi.advanceTimersByTimeAsync(1000)

    // Start beat + one throttled progress beat + the final "Counted to 10" beat.
    const progress = api.heartbeat.mock.calls.map(([, body]) => body.progress)
    expect(progress).toEqual([0, 10, 100])
    expect(api.complete).toHaveBeenCalledWith('a', { counted: 10 })
    worker.stop()
  })
})
