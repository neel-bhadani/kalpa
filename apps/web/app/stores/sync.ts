import { defineStore } from 'pinia'
import { isTaskUnsaved, SyncClient, type Change, type Op, type OpResult, type SyncApi, type SyncStatus } from '@kalpa/core/sync'

// Offline sync for the current workspace (see packages/core/src/sync): keeps a cache of projects,
// tasks and asset metadata in IndexedDB, sends edits made here, and pulls changes made elsewhere.
export const useSyncStore = defineStore('sync', () => {
  const status = ref<SyncStatus>({ state: 'syncing', pending: 0, pendingTaskIds: [], conflicts: [] })
  // Rows pulled from the server since the page opened, for screens that refresh from the cache.
  const lastChanges = ref<Change[]>([])

  let client: SyncClient | null = null
  let starting: Promise<void> | null = null
  let stopNudges: (() => void)[] = []

  const pendingTaskIds = computed(() => new Set(status.value.pendingTaskIds))

  // "Unsaved": edits on this device not sent yet, or the server says another device has some.
  function isUnsaved(task: { id: string; has_unsynced_changes?: boolean }): boolean {
    return isTaskUnsaved(task, pendingTaskIds.value)
  }

  // Safe to call more than once: the first call starts, the others wait for it.
  function start(): Promise<void> {
    starting ??= begin().catch((error) => {
      starting = null
      throw error
    })
    return starting
  }

  async function begin() {
    const auth = useAuthStore()
    const workspace = auth.currentWorkspace
    if (client || !workspace || !auth.user) return

    client = new SyncClient({
      api: syncApi(workspace.id),
      dbName: `kalpa-sync:${auth.user.id}:${workspace.id}`,
      deviceId: localStorage.getItem(DEVICE_ID_KEY),
      onChanges: (changes) => {
        lastChanges.value = changes
        listenForNudges()
      },
      onRejected: (op: Op, result: OpResult) => console.warn('[kalpa sync] rejected', op.type, result.errors),
    })
    client.subscribe((next) => {
      status.value = next
    })

    // Send what's waiting when the tab is hidden or closed; flag what can't be sent.
    window.addEventListener('pagehide', onHide)
    document.addEventListener('visibilitychange', onVisibility)

    // Open the cache before anything waits on it; the first pull runs in the background
    // (it may take a while, or fail while offline).
    await client.open()
    void client.start().then(listenForNudges)

    // The device id may arrive after start (first visit): then edits can be pushed.
    const devices = useDevicesStore()
    watch(() => devices.deviceId, (id) => {
      if (id) void client?.setDeviceId(id)
    }, { immediate: true })
  }

  function stop() {
    stopNudges.forEach(stop => stop())
    stopNudges = []
    client?.stop()
    client = null
    starting = null
    window.removeEventListener('pagehide', onHide)
    document.removeEventListener('visibilitychange', onVisibility)
  }

  function get(): SyncClient {
    if (!client) throw new Error('Sync has not started.')
    return client
  }

  // The open client, starting it if needed (pages can ask before the layout has mounted).
  async function ready(): Promise<SyncClient> {
    await start()
    if (!client) throw new Error('Sign in to use the offline cache.')
    return client
  }

  async function resolveConflict(key: string, choice: 'mine' | 'theirs') {
    await client?.resolveConflict(key, choice)
  }

  // Another device changed something in one of my projects: pull now.
  async function listenForNudges() {
    if (!client) return
    const projects = await client.listEntities('project')
    const { listen } = useRealtime()
    stopNudges.forEach(stop => stop())
    stopNudges = projects.map(project => listen(`project.${project.id}`, 'sync.nudge', () => client?.nudge()))
  }

  function onHide() {
    client?.flushBeacon()
  }

  function onVisibility() {
    if (document.visibilityState === 'hidden') onHide()
    else void client?.sync()
  }

  return { status, lastChanges, pendingTaskIds, isUnsaved, start, stop, get, ready, resolveConflict }
})

// The sync endpoints, in the shape the core client expects.
function syncApi(workspaceId: string): SyncApi {
  const api = useApi()

  return {
    pull: (after, limit) => api<Awaited<ReturnType<SyncApi['pull']>>>('/api/v1/sync', { query: { after, limit } }),
    push: async (deviceId, ops) => (await api<{ data: OpResult[] }>('/api/v1/sync', { method: 'POST', body: { device_id: deviceId, ops } })).data,
    // Runs while the page closes. fetch keepalive (not sendBeacon) because the request needs
    // the CSRF and workspace headers, which sendBeacon can't send.
    markUnsynced(taskIds) {
      const token = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/)?.[1]
      void fetch('/api/v1/sync/unsynced', {
        method: 'POST',
        keepalive: true,
        credentials: 'same-origin',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          'X-Workspace-Id': workspaceId,
          ...(token ? { 'X-XSRF-TOKEN': decodeURIComponent(token) } : {}),
        },
        body: JSON.stringify({ task_ids: taskIds }),
      }).catch(() => undefined)
    },
  }
}
