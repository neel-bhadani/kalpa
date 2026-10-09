// Web Worker: SHA-256 of a File, read in chunks so a large file never sits in memory at once.
import { createSHA256 } from 'hash-wasm'

export type HashRequest = { file: Blob; chunkSize: number }

export type HashMessage =
  | { type: 'progress'; done: number; total: number }
  | { type: 'done'; sha256: string }
  | { type: 'error'; message: string }

const scope = self as unknown as {
  onmessage: (event: MessageEvent<HashRequest>) => void
  postMessage: (message: HashMessage) => void
}

scope.onmessage = async ({ data: { file, chunkSize } }) => {
  try {
    const hasher = await createSHA256()
    hasher.init()

    for (let offset = 0; offset < file.size; offset += chunkSize) {
      const end = Math.min(offset + chunkSize, file.size)
      hasher.update(new Uint8Array(await file.slice(offset, end).arrayBuffer()))
      scope.postMessage({ type: 'progress', done: end, total: file.size })
    }

    scope.postMessage({ type: 'done', sha256: hasher.digest('hex') })
  } catch (error) {
    scope.postMessage({ type: 'error', message: error instanceof Error ? error.message : String(error) })
  }
}
