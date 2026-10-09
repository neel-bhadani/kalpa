import type { HashMessage, HashRequest } from './hash.worker'

const CHUNK_SIZE = 4 * 1024 * 1024

// SHA-256 (hex) of a file, computed in a Web Worker. `onProgress` gets 0..1.
export function hashFile(file: Blob, onProgress: (fraction: number) => void, signal?: AbortSignal): Promise<string> {
  return new Promise((resolve, reject) => {
    const worker = new Worker(new URL('./hash.worker.ts', import.meta.url), { type: 'module' })

    const stop = () => {
      worker.terminate()
      reject(new DOMException('Hashing was cancelled.', 'AbortError'))
    }
    signal?.addEventListener('abort', stop, { once: true })

    worker.onmessage = ({ data }: MessageEvent<HashMessage>) => {
      if (data.type === 'progress') {
        onProgress(data.done / data.total)
        return
      }
      signal?.removeEventListener('abort', stop)
      worker.terminate()
      if (data.type === 'done') {
        resolve(data.sha256)
      } else {
        reject(new Error(data.message))
      }
    }

    worker.postMessage({ file, chunkSize: CHUNK_SIZE } satisfies HashRequest)
  })
}
