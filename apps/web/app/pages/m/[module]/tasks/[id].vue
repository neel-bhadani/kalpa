<script setup lang="ts">
import type { Component } from 'vue'
import { findModule } from '@kalpa/core'
import type { EditorHighlight, EditorTask } from '@kalpa/core'
import type { CheckpointPort } from '@kalpa/ai-runtime'
import type { CursorPage, Job, JobUpdate, Task, TaskUpdate } from '~/types/api'

const route = useRoute()
const api = useApi()
const sync = useSyncStore()
const errors = useFormErrors()
const taskId = route.params.id as string

const module = findModule(route.params.module as string)
const task = ref<(Task & Pick<EditorTask, 'outputs'>) | null>(null)
const jobs = ref<Job[]>([])
// Opened from the offline cache (the server wasn't reachable).
const offline = ref(false)

// The checkpoint: notes and view state, kept on this device and synced (see useTaskCheckpoint).
const checkpoint = useTaskCheckpoint(taskId)

// The assistant edits the same checkpoint, through the module's commands. `highlight` lets the
// editor show each step happening; AI changes are sent at once rather than after the usual 2 s.
const highlight = ref<EditorHighlight>(null)
const assistantPort: CheckpointPort = {
  // A plain copy, not the editor's reactive one.
  get: () => JSON.parse(JSON.stringify(checkpoint.checkpoint.value)),
  set: async (next, change) => {
    await checkpoint.save(next)
    checkpoint.flush()
    highlight.value = { ...change, at: Date.now() }
  },
}
const showAssistant = ref(localStorage.getItem('kalpa.assistant') !== 'hidden')
// The module's assistant is writing: the editor locks itself until it's done.
const assistantBusy = ref(false)
const panel = ref<{ startWriting: (request: Record<string, unknown>) => Promise<void> } | null>(null)

// "Write with AI" in the editor: open the panel and start.
async function writeWithAi(request: Record<string, unknown>) {
  showAssistant.value = true
  await loadOutputs().catch(() => undefined)
  for (let i = 0; i < 50 && !panel.value; i++) await new Promise(resolve => setTimeout(resolve, 100))
  await panel.value?.startWriting(request)
}
watch(showAssistant, value => localStorage.setItem('kalpa.assistant', value ? 'shown' : 'hidden'))

// The module's editor, downloaded only now (its own chunk).
const Editor = module
  ? defineAsyncComponent(() => module.editor() as Promise<{ default: Component }>)
  : null

async function loadTask() {
  try {
    const { data } = await api<{ data: Task }>(`/api/v1/tasks/${taskId}`)
    task.value = { ...data, outputs: [] }
    await checkpoint.load(data as Task & { checkpoint: unknown })
  } catch (error) {
    if (!isNetworkError(error)) throw error
    // Offline: the copy this device keeps.
    const cached = await (await sync.ready()).getEntity('task', taskId)
    if (!cached) throw error
    task.value = { ...(cached.data as Task), outputs: [] }
    offline.value = true
    await checkpoint.load()
  }
}

async function loadOutputs() {
  const outputs = await api<{ data: NonNullable<EditorTask['outputs']> }>(`/api/v1/tasks/${taskId}/outputs`)
  if (task.value) task.value.outputs = outputs.data
}

async function loadJobs() {
  jobs.value = (await api<CursorPage<Job>>('/api/v1/jobs', { query: { task: taskId, per_page: 5 } })).data
}

// Dev only: count to 20 in this browser, so the whole job loop can be watched.
async function runDemoJob() {
  errors.clear()
  try {
    await api('/api/v1/jobs', {
      method: 'POST',
      body: {
        type: 'demo.count',
        target: 'device',
        task_id: taskId,
        input: { n: 20, interval_ms: 500 },
        priority: 'interactive',
        idempotency_key: crypto.randomUUID(),
      },
    })
    await loadJobs()
  } catch (error) {
    errors.show(error)
  }
}

// Live: the task (status, Processing %), its jobs and its outputs.
useLiveEvent(() => `task.${taskId}`, 'TaskUpdated', (update: TaskUpdate) => {
  if (task.value) Object.assign(task.value, update)
})
useLiveEvent(() => `task.${taskId}`, 'JobUpdated', (update: JobUpdate) => {
  const known = jobs.value.find(job => job.id === update.id)
  if (known) Object.assign(known, update)
  else loadJobs().catch(() => undefined)
})
useLiveEvent(() => `task.${taskId}`, 'AssetVersionCreated', () => loadOutputs().catch(() => undefined))

// Another device saved this task's checkpoint: show it, unless this device has unsent edits
// (then the next push decides, and a conflict dialog may ask).
watch(() => sync.lastChanges, (changes) => {
  const mine = changes.find(change => change.entity === 'task' && change.entity_id === taskId)
  if (mine && !sync.pendingTaskIds.has(taskId)) void checkpoint.load()
})

// A conflict for this task was settled ("Use theirs" replaces what's shown).
watch(() => sync.status.conflicts.some(conflict => conflict.entity_id === taskId), (open, wasOpen) => {
  if (wasOpen && !open) void checkpoint.load()
})

const isDev = computed(() => useAuthStore().devTools)
// Viewers can look (and switch tabs on this screen) but nothing they do is saved or sent.
const canEdit = computed(() => useAuthStore().currentWorkspace?.role !== 'viewer')

function onEditorChange(next: Record<string, any>) {
  if (canEdit.value) void checkpoint.save(next)
  else checkpoint.checkpoint.value = next
}

try {
  await loadTask()
  if (!offline.value) await Promise.all([loadOutputs(), loadJobs()]).catch(() => undefined)
} catch (error) {
  errors.show(error)
}
</script>

<template>
  <main class="mx-auto p-6" :class="showAssistant ? 'max-w-7xl' : 'max-w-4xl'">
    <NuxtLink v-if="module" :to="`/m/${module.key}`" class="text-sm text-muted hover:text-text">← {{ module.name }}</NuxtLink>

    <p v-if="errors.message.value" class="mt-4 text-sm text-red-400">{{ errors.message.value }}</p>
    <p v-for="(message, field) in errors.fields.value" :key="field" class="mt-4 text-sm text-red-400">{{ message }}</p>
    <p v-if="!module" class="mt-4 text-sm text-muted">There is no "{{ route.params.module }}" module.</p>

    <template v-if="task">
      <div class="mt-4 flex flex-wrap items-center gap-3">
        <StatusBadge :task="task" data-test="task-status" />
        <UnsavedChip v-if="sync.isUnsaved(task)" data-test="unsaved" />
        <span v-if="offline" class="text-xs text-amber-300">Offline copy</span>
        <span v-else-if="checkpoint.source.value === 'local'" class="text-xs text-muted">Resumed from this device's unsent edits</span>
        <button
          v-if="isDev && canEdit && !offline"
          class="ml-auto rounded-lg border border-muted/30 px-3 py-1.5 text-xs hover:border-accent hover:text-accent"
          data-test="run-demo-job"
          @click="runDemoJob"
        >
          Run demo job
        </button>
        <button
          class="rounded-lg border border-muted/30 px-3 py-1.5 text-xs hover:border-accent hover:text-accent"
          :class="!(isDev && canEdit && !offline) && 'ml-auto'"
          data-test="toggle-assistant"
          @click="showAssistant = !showAssistant"
        >
          {{ showAssistant ? 'Hide assistant' : 'Assistant' }}
        </button>
      </div>

      <ul v-if="jobs.length" class="mt-3 space-y-1 text-xs">
        <li v-for="job in jobs" :key="job.id" class="flex items-center gap-3" data-test="task-job">
          <span class="font-mono">{{ job.type }}</span>
          <span class="rounded-full px-2 py-0.5" :class="jobStatusClasses[job.status]">{{ job.status }}</span>
          <span class="font-mono text-muted">{{ job.progress }}%</span>
          <span v-if="job.error" class="truncate text-red-400">{{ job.error }}</span>
        </li>
      </ul>

      <div class="mt-4 grid items-start gap-4" :class="showAssistant && 'lg:grid-cols-[minmax(0,1fr)_380px]'">
        <div v-if="Editor && checkpoint.ready.value">
          <component
            :is="Editor"
            :task="task"
            :checkpoint="checkpoint.checkpoint.value"
            :highlight="highlight"
            :readonly="!canEdit"
            :api="api"
            :ai-busy="assistantBusy"
            @update:checkpoint="onEditorChange"
            @assistant="writeWithAi"
          />
        </div>
        <AssistantPanel
          v-if="showAssistant && !offline && checkpoint.ready.value"
          class="lg:sticky lg:top-4 lg:h-[calc(100vh-8rem)]"
          :task-id="taskId"
          ref="panel"
          :module="task.module"
          :checkpoint="assistantPort"
          :project-id="task.project_id"
          :task-title="task.title"
          :outputs="task.outputs ?? []"
          @busy="assistantBusy = $event"
        />
      </div>
    </template>
  </main>
</template>
