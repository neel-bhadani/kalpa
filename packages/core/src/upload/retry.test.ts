import { describe, expect, it } from 'vitest'
import { backoffDelay, withRetry } from './retry'

// No real waiting: record the delays instead.
function recorder() {
  const delays: number[] = []
  return { delays, sleep: async (ms: number) => { delays.push(ms) } }
}

describe('backoffDelay', () => {
  it('doubles each retry, between half and the full delay', () => {
    expect([1, 2, 3].map(retry => backoffDelay(retry, 500, 8000, () => 1))).toEqual([500, 1000, 2000])
    expect([1, 2, 3].map(retry => backoffDelay(retry, 500, 8000, () => 0))).toEqual([250, 500, 1000])
  })

  it('never goes above the cap', () => {
    expect(backoffDelay(10, 500, 8000, () => 1)).toBe(8000)
  })
})

describe('withRetry', () => {
  it('returns the first success', async () => {
    const { delays, sleep } = recorder()
    let calls = 0

    const result = await withRetry(async () => {
      calls++
      if (calls < 3) throw new Error('flaky')
      return 'ok'
    }, { sleep, random: () => 1 })

    expect(result).toBe('ok')
    expect(calls).toBe(3)
    expect(delays).toEqual([500, 1000])
  })

  it('gives up after 3 retries (4 tries) with the last error', async () => {
    const { delays, sleep } = recorder()
    let calls = 0

    await expect(withRetry(async () => {
      calls++
      throw new Error(`try ${calls}`)
    }, { sleep, random: () => 1 })).rejects.toThrow('try 4')

    expect(calls).toBe(4)
    expect(delays).toEqual([500, 1000, 2000])
  })

  it('stops at once when the error should not be retried', async () => {
    const { delays, sleep } = recorder()
    let calls = 0

    await expect(withRetry(async () => {
      calls++
      throw new Error('paused')
    }, { sleep, shouldRetry: () => false })).rejects.toThrow('paused')

    expect(calls).toBe(1)
    expect(delays).toEqual([])
  })

  it('passes the attempt number to the task', async () => {
    const attempts: number[] = []
    await withRetry(async (attempt) => {
      attempts.push(attempt)
      if (attempt < 2) throw new Error('again')
    }, { sleep: async () => {} })

    expect(attempts).toEqual([1, 2])
  })
})
