// Retry with exponential backoff: 0.5 s, 1 s, 2 s ... (capped), with jitter so
// parallel parts don't all retry at the same moment.

export type RetryOptions = {
  // Retries after the first try (so `retries: 3` means up to 4 tries).
  retries?: number
  baseMs?: number
  maxMs?: number
  // Return false for errors that won't get better by trying again (e.g. a pause).
  shouldRetry?: (error: unknown) => boolean
  sleep?: (ms: number) => Promise<void>
  // 0..1; injectable for tests.
  random?: () => number
}

// Delay before retry number `retry` (1-based): between half and all of base * 2^(retry-1).
export function backoffDelay(retry: number, baseMs = 500, maxMs = 8000, random: () => number = Math.random): number {
  const full = Math.min(maxMs, baseMs * 2 ** (retry - 1))

  return full / 2 + (random() * full) / 2
}

export async function withRetry<T>(task: (attempt: number) => Promise<T>, options: RetryOptions = {}): Promise<T> {
  const {
    retries = 3,
    baseMs = 500,
    maxMs = 8000,
    shouldRetry = () => true,
    sleep = (ms: number) => new Promise<void>(resolve => setTimeout(resolve, ms)),
    random = Math.random,
  } = options

  for (let attempt = 1; ; attempt++) {
    try {
      return await task(attempt)
    } catch (error) {
      if (attempt > retries || !shouldRetry(error)) {
        throw error
      }
      await sleep(backoffDelay(attempt, baseMs, maxMs, random))
    }
  }
}
