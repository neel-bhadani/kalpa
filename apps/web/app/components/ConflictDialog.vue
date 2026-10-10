<script setup lang="ts">
// "This task changed on another device": both versions side by side; keep mine or use theirs.
const sync = useSyncStore()
const devices = useDevicesStore()

const conflict = computed(() => sync.status.conflicts[0] ?? null)
const busy = ref(false)

// What each side looks like, for checkpoints (notes and view) and task edits.
function describe(payload: Record<string, any> | undefined, kind: string) {
  if (kind === 'task.checkpoint') {
    const checkpoint = payload?.checkpoint ?? {}
    return {
      notes: String(checkpoint.data?.notes ?? ''),
      view: checkpoint.view ? `${checkpoint.view.tab ?? 'notes'} tab, scrolled ${checkpoint.view.scroll ?? 0}px` : '—',
    }
  }
  return { notes: JSON.stringify(payload ?? {}, null, 2), view: '' }
}

const mine = computed(() => conflict.value && describe(conflict.value.mine, conflict.value.op_type))
const theirs = computed(() => conflict.value && describe(
  conflict.value.op_type === 'task.checkpoint' ? { checkpoint: conflict.value.theirs.checkpoint } : conflict.value.theirs,
  conflict.value.op_type,
))

const title = computed(() => conflict.value?.op_type === 'project_memory.patch' ? 'Project memory changed on another device' : 'This task changed on another device')

async function choose(choice: 'mine' | 'theirs') {
  if (!conflict.value) return
  busy.value = true
  try {
    await sync.resolveConflict(conflict.value.key, choice)
  } finally {
    busy.value = false
  }
}

function when(value?: string | null) {
  return value ? new Date(value).toLocaleString() : 'unknown time'
}
</script>

<template>
  <div v-if="conflict && mine && theirs" class="fixed inset-0 z-40 flex items-center justify-center bg-bg/80 p-4" data-test="conflict">
    <div class="w-full max-w-3xl rounded-xl bg-panel p-6 shadow-2xl" role="alertdialog" aria-labelledby="conflict-title">
      <h2 id="conflict-title" class="text-lg font-semibold">{{ title }}</h2>
      <p class="mt-1 text-sm text-muted">
        <template v-if="conflict.theirs.title">"{{ conflict.theirs.title }}": </template>
        both versions were edited. Choose which one to keep.
      </p>

      <div class="mt-5 grid gap-4 sm:grid-cols-2">
        <section class="rounded-lg border border-accent/40 p-4" data-test="conflict-mine">
          <h3 class="text-sm font-medium text-accent">Mine</h3>
          <p class="mt-0.5 text-xs text-muted">{{ devices.deviceName }} · {{ when(conflict.mine_edited_at) }}</p>
          <pre class="mt-3 max-h-48 overflow-auto whitespace-pre-wrap rounded bg-bg p-2 text-sm">{{ mine.notes || '(empty)' }}</pre>
          <p v-if="mine.view" class="mt-2 font-mono text-xs text-muted">{{ mine.view }}</p>
        </section>
        <section class="rounded-lg border border-media/40 p-4" data-test="conflict-theirs">
          <h3 class="text-sm font-medium text-media">Theirs</h3>
          <p class="mt-0.5 text-xs text-muted">
            {{ conflict.theirs.last_change?.device_name ?? 'Another device' }} · {{ when(conflict.theirs.last_change?.at) }}
          </p>
          <pre class="mt-3 max-h-48 overflow-auto whitespace-pre-wrap rounded bg-bg p-2 text-sm">{{ theirs.notes || '(empty)' }}</pre>
          <p v-if="theirs.view" class="mt-2 font-mono text-xs text-muted">{{ theirs.view }}</p>
        </section>
      </div>

      <div class="mt-6 flex justify-end gap-2">
        <button
          :disabled="busy"
          class="rounded-lg border border-media/50 px-4 py-2 text-sm text-media hover:bg-media/10 disabled:opacity-50"
          data-test="use-theirs"
          @click="choose('theirs')"
        >
          Use theirs
        </button>
        <button
          :disabled="busy"
          class="rounded-lg bg-accent px-4 py-2 text-sm font-medium text-bg hover:opacity-90 disabled:opacity-50"
          data-test="keep-mine"
          @click="choose('mine')"
        >
          Keep mine
        </button>
      </div>
    </div>
  </div>
</template>
