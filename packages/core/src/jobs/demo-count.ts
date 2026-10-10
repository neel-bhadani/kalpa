import type { JobHandler } from './worker'

// demo.count: counts to n, one step every interval_ms, reporting progress. Dev only.
export const demoCount: JobHandler = async (input, { report, signal }) => {
  const { n = 20, interval_ms = 500 } = (input ?? {}) as { n?: number; interval_ms?: number }

  for (let i = 1; i <= n; i++) {
    await sleep(interval_ms, signal)
    report((i / n) * 100, i === n ? `Counted to ${n}` : undefined)
  }

  return { counted: n }
}

function sleep(ms: number, signal: AbortSignal): Promise<void> {
  return new Promise((resolve, reject) => {
    if (signal.aborted) return reject(new DOMException('Stopped', 'AbortError'))
    const timer = setTimeout(resolve, ms)
    signal.addEventListener('abort', () => {
      clearTimeout(timer)
      reject(new DOMException('Stopped', 'AbortError'))
    }, { once: true })
  })
}
