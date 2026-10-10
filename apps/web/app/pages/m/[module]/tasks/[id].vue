<script setup lang="ts">
import type { Component } from 'vue'
import { findModule } from '@kalpa/core'
import type { EditorTask } from '@kalpa/core'
import type { CursorPage, Job, JobUpdate, Task, TaskUpdate } from '~/types/api'

const route = useRoute()
const api = useApi()
const errors = useFormErrors()
const taskId = route.params.id as string

const module = findModule(route.params.module as string)
const task = ref<(Task & Pick<EditorTask, 'outputs'>) | null>(null)
const jobs = ref<Job[]>([])

// The module's editor, downloaded only now (its own chunk).
const Editor = module
  ? defineAsyncComponent(() => module.editor() as Promise<{ default: Component }>)
  : null

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
  else loadJobs()
})
useLiveEvent(() => `task.${taskId}`, 'AssetVersionCreated', () => loadOutputs())

const isDev = import.meta.dev

try {
  const [taskResponse] = await Promise.all([api<{ data: Task }>(`/api/v1/tasks/${taskId}`), loadJobs()])
  task.value = { ...taskResponse.data, outputs: [] }
  await loadOutputs()
} catch (error) {
  errors.show(error)
}
</script>

<template>
  <main class="mx-auto max-w-4xl p-6">
    <NuxtLink v-if="module" :to="`/m/${module.key}`" class="text-sm text-muted hover:text-text">← {{ module.name }}</NuxtLink>

    <p v-if="errors.message.value" class="mt-4 text-sm text-red-400">{{ errors.message.value }}</p>
    <p v-for="(message, field) in errors.fields.value" :key="field" class="mt-4 text-sm text-red-400">{{ message }}</p>
    <p v-if="!module" class="mt-4 text-sm text-muted">There is no "{{ route.params.module }}" module.</p>

    <template v-if="task">
      <div class="mt-4 flex flex-wrap items-center gap-3">
        <StatusBadge :task="task" data-test="task-status" />
        <UnsavedChip v-if="task.has_unsynced_changes" />
        <button
          v-if="isDev"
          class="ml-auto rounded-lg border border-muted/30 px-3 py-1.5 text-xs hover:border-accent hover:text-accent"
          data-test="run-demo-job"
          @click="runDemoJob"
        >
          Run demo job
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

      <div v-if="Editor" class="mt-4">
        <component :is="Editor" :task="task" />
      </div>
    </template>
  </main>
</template>
