import { TaskCheckpoint, type Checkpoint } from '@kalpa/core/sync'

// A task's checkpoint for an editor (Vue wrapper around packages/core's TaskCheckpoint):
// loads the local copy if it has unsent edits, else the server's; keeps every change in IndexedDB
// at once and sends it 2 s after the last change, or right away when the tab is hidden.
export function useTaskCheckpoint(taskId: string) {
  const sync = useSyncStore()
  const checkpoint = ref<Checkpoint>({})
  const source = ref<'local' | 'server' | 'none'>('none')
  const ready = ref(false)

  let tracker: TaskCheckpoint | null = null

  // serverTask: a fresh copy from the API, when online.
  async function load(serverTask?: { id: string; row_version: number; checkpoint?: unknown }) {
    const client = await sync.ready()
    tracker ??= new TaskCheckpoint(client, taskId)
    const loaded = await tracker.load(serverTask)
    checkpoint.value = loaded.checkpoint
    source.value = loaded.source
    ready.value = true
  }

  async function save(next: Checkpoint) {
    checkpoint.value = next
    await tracker?.update(next)
  }

  function flush() {
    void tracker?.flush()
  }

  function onVisibility() {
    if (document.visibilityState === 'hidden') flush()
  }

  onMounted(() => {
    document.addEventListener('visibilitychange', onVisibility)
    window.addEventListener('pagehide', flush)
  })

  onBeforeUnmount(() => {
    flush()
    tracker?.dispose()
    document.removeEventListener('visibilitychange', onVisibility)
    window.removeEventListener('pagehide', flush)
  })

  return { checkpoint, source, ready, load, save, flush }
}
