// Upload ids by workspace + file hash, kept in IndexedDB so an upload can resume after a reload.

export type ResumeRecord = {
  uploadId: string
  fileName: string
  size: number
  savedAt: number
}

const DB_NAME = 'kalpa-uploads'
const STORE = 'uploads'

function openDb(): Promise<IDBDatabase> {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open(DB_NAME, 1)
    request.onupgradeneeded = () => request.result.createObjectStore(STORE)
    request.onsuccess = () => resolve(request.result)
    request.onerror = () => reject(request.error)
  })
}

async function run<T>(mode: IDBTransactionMode, action: (store: IDBObjectStore) => IDBRequest<T>): Promise<T> {
  const db = await openDb()
  try {
    return await new Promise<T>((resolve, reject) => {
      const request = action(db.transaction(STORE, mode).objectStore(STORE))
      request.onsuccess = () => resolve(request.result)
      request.onerror = () => reject(request.error)
    })
  } finally {
    db.close()
  }
}

export function resumeKey(workspaceId: string, sha256: string): string {
  return `${workspaceId}:${sha256}`
}

export function getResume(key: string): Promise<ResumeRecord | undefined> {
  return run('readonly', store => store.get(key) as IDBRequest<ResumeRecord | undefined>)
}

export async function saveResume(key: string, record: ResumeRecord): Promise<void> {
  await run('readwrite', store => store.put(record, key))
}

export async function forgetResume(key: string): Promise<void> {
  await run('readwrite', store => store.delete(key))
}
