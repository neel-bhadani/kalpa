// Model files are downloaded once into this device's storage (OPFS), with progress and resume:
// after an interruption the download continues from the bytes already on disk (HTTP Range).
// Checks: enough free space before starting, the server's size before writing anything, and the
// final size and SHA-256 once done. A file only counts as downloaded after it passed them.

import { formatBytes } from './catalog'

export type FileAppender = { write(chunk: Uint8Array): Promise<void>; close(): Promise<void> }

export interface ModelFileStore {
  // Bytes on disk so far (0 if none).
  size(name: string): Promise<number>
  // A writer that adds to the end of the file (kept as it is when closed early).
  appender(name: string): Promise<FileAppender>
  read(name: string): Promise<Blob | null>
  isComplete(name: string): Promise<boolean>
  markComplete(name: string): Promise<void>
  remove(name: string): Promise<void>
  list(): Promise<{ name: string; bytes: number; complete: boolean }[]>
}

export type DownloadOptions = {
  store: ModelFileStore
  name: string
  url: string
  bytes: number
  sha256?: string
  onProgress?: (done: number, total: number) => void
  signal?: AbortSignal
  fetch?: typeof fetch
  // Free storage in bytes, or null if the browser can't tell.
  freeBytes?: () => Promise<number | null>
  // SHA-256 (hex) of a file; checking is skipped without it.
  hash?: (file: Blob) => Promise<string>
}

export class DownloadError extends Error {}

export async function downloadModelFile(options: DownloadOptions): Promise<void> {
  const { store, name, bytes } = options
  if (await store.isComplete(name)) return

  let have = await store.size(name)
  if (have > bytes) {
    await store.remove(name)
    have = 0
  }

  const free = (await options.freeBytes?.()) ?? null
  if (free !== null && free < bytes - have) {
    throw new DownloadError(`Not enough storage: the download needs ${formatBytes(bytes - have)}, and ${formatBytes(free)} is free.`)
  }

  if (have < bytes) {
    const response = await (options.fetch ?? fetch)(options.url, { headers: have ? { Range: `bytes=${have}-` } : {}, signal: options.signal })
    if (response.status === 200 && have > 0) {
      // The server ignored the Range header and sends the whole file: start over.
      await store.remove(name)
      have = 0
    } else if (response.status !== 200 && response.status !== 206) {
      throw new DownloadError(`The download failed (HTTP ${response.status}).`)
    }

    const total = serverTotal(response, have)
    if (total !== null && total !== bytes) {
      throw new DownloadError(`The file on the server is ${formatBytes(total)}, but ${formatBytes(bytes)} was expected. Nothing was downloaded.`)
    }

    have = await receive(response, options, have)
  }

  if (have !== bytes) {
    await store.remove(name)
    throw new DownloadError(`The download is ${formatBytes(have)}, but ${formatBytes(bytes)} was expected. It was removed; try again.`)
  }

  if (options.sha256 && options.hash) {
    const file = await store.read(name)
    if (!file || (await options.hash(file)) !== options.sha256.toLowerCase()) {
      await store.remove(name)
      throw new DownloadError('The downloaded file is damaged (its SHA-256 does not match). It was removed; try again.')
    }
  }

  await store.markComplete(name)
}

// Write the body to the end of the file. Returns the bytes on disk. On an error (or a stop) the
// part already written stays, so the next try resumes from there.
async function receive(response: Response, options: DownloadOptions, start: number): Promise<number> {
  if (!response.body) throw new DownloadError('The server sent no file.')
  const writer = await options.store.appender(options.name)
  const reader = response.body.getReader()
  let have = start
  try {
    while (true) {
      const { value, done } = await reader.read()
      if (done) break
      have += value.byteLength
      // More than expected: stop; the size check after removes it.
      if (have > options.bytes) break
      await writer.write(value)
      options.onProgress?.(have, options.bytes)
    }
  } finally {
    await reader.cancel().catch(() => {})
    await writer.close()
  }
  return have > options.bytes ? have : options.store.size(options.name)
}

// The whole file's size as the server reports it, or null if it doesn't say.
function serverTotal(response: Response, have: number): number | null {
  if (response.status === 206) {
    const total = response.headers.get('Content-Range')?.match(/\/(\d+)$/)?.[1]
    return total ? Number(total) : null
  }
  const length = response.headers.get('Content-Length')
  return length ? have + Number(length) : null
}

type DirectoryWithEntries = FileSystemDirectoryHandle & { entries(): AsyncIterable<[string, FileSystemHandle]> }

// Model files in the Origin Private File System, in a "models" folder. A "<name>.ok" marker is
// written once a file has passed its checks.
export async function opfsModelStore(folder = 'models'): Promise<ModelFileStore> {
  const root = await navigator.storage.getDirectory()
  const dir = await root.getDirectoryHandle(folder, { create: true })
  const find = (name: string) => dir.getFileHandle(name).catch(() => null)

  const store: ModelFileStore = {
    async size(name) {
      const handle = await find(name)
      return handle ? (await handle.getFile()).size : 0
    },
    async appender(name) {
      const handle = await dir.getFileHandle(name, { create: true })
      const writable = await handle.createWritable({ keepExistingData: true })
      await writable.seek((await handle.getFile()).size)
      return { write: chunk => writable.write(chunk as Uint8Array<ArrayBuffer>), close: () => writable.close() }
    },
    async read(name) {
      return (await find(name))?.getFile() ?? null
    },
    async isComplete(name) {
      return (await find(`${name}.ok`)) !== null
    },
    async markComplete(name) {
      await dir.getFileHandle(`${name}.ok`, { create: true })
    },
    async remove(name) {
      for (const entry of [`${name}.ok`, name]) await dir.removeEntry(entry).catch(() => {})
    },
    async list() {
      const names: string[] = []
      for await (const [name, handle] of (dir as DirectoryWithEntries).entries()) {
        if (handle.kind === 'file' && !name.endsWith('.ok')) names.push(name)
      }
      return Promise.all(names.map(async name => ({ name, bytes: await store.size(name), complete: await store.isComplete(name) })))
    },
  }
  return store
}
