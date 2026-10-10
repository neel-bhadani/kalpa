<script setup lang="ts">
import type { CursorPage, Project, ProjectAsset, Task, TaskEvent, TaskMode, TaskStatus } from '~/types/api'

const route = useRoute()
const api = useApi()
const errors = useFormErrors()
const projectId = route.params.id as string

const project = ref<Project | null>(null)
const projectAssets = ref<ProjectAsset[]>([])

// The Producer (fake brain for now) plans a goal into three linked tasks; nothing runs yet.
const goal = ref('')
const planning = ref(false)
const planned = ref('')

async function planWithProducer() {
  planning.value = true
  errors.clear()
  try {
    const { data } = await api<{ data: { tasks: Task[] } }>(`/api/v1/projects/${projectId}/plans`, { method: 'POST', body: { goal: goal.value } })
    planned.value = `Planned ${data.tasks.length} tasks: ${data.tasks.map(task => task.title.split(':')[0]).join(' → ')}.`
    await loadTasks()
  } catch (error) {
    errors.show(error)
  } finally {
    planning.value = false
  }
}
const tasks = ref<Task[]>([])
const nextCursor = ref<string | null>(null)

// Events per task id, loaded when the task's event list is opened.
const events = ref<Record<string, TaskEvent[]>>({})

const modes: TaskMode[] = ['manual', 'semi', 'auto']
const form = reactive({ module: '', kind: '', title: '', mode: 'manual' as TaskMode })

const statusLabels: Record<TaskStatus, string> = {
  draft: 'Draft',
  in_progress: 'In progress',
  waiting_job: 'Waiting for job',
  needs_review: 'Needs review',
  paused: 'Paused',
  failed: 'Failed',
  completed: 'Completed',
  archived: 'Archived',
}

async function run(action: () => Promise<void>) {
  errors.clear()
  try {
    await action()
  } catch (error) {
    errors.show(error)
  }
}

async function loadTasks(cursor: string | null = null) {
  const page = await api<CursorPage<Task>>('/api/v1/tasks', {
    query: { project: projectId, cursor: cursor ?? undefined },
  })
  tasks.value = cursor ? [...tasks.value, ...page.data] : page.data
  nextCursor.value = page.meta.next_cursor
}

function replaceTask(updated: Task) {
  tasks.value = tasks.value.map(task => (task.id === updated.id ? updated : task))
}

async function loadEvents(taskId: string) {
  const page = await api<CursorPage<TaskEvent>>(`/api/v1/tasks/${taskId}/events`, { query: { per_page: 100 } })
  events.value[taskId] = page.data
}

function toggleEvents(taskId: string) {
  if (events.value[taskId]) {
    delete events.value[taskId]
    return
  }
  run(() => loadEvents(taskId))
}

function createTask() {
  run(async () => {
    const { data } = await api<{ data: Task }>(`/api/v1/projects/${projectId}/tasks`, { method: 'POST', body: form })
    tasks.value = [data, ...tasks.value]
    form.title = ''
  })
}

function moveTask(task: Task, to: TaskStatus) {
  run(async () => {
    const { data } = await api<{ data: Task }>(`/api/v1/tasks/${task.id}/status`, { method: 'POST', body: { to } })
    replaceTask(data)
    if (events.value[task.id]) {
      await loadEvents(task.id)
    }
  })
}

function describe(event: TaskEvent): string {
  if (event.type === 'status_changed') {
    return `${event.from_status} → ${event.to_status}`
  }
  if (event.type === 'created') {
    return 'created'
  }
  return event.type.replace(/_/g, ' ')
}

await run(async () => {
  const { data } = await api<{ data: Project }>(`/api/v1/projects/${projectId}/open`, { method: 'POST' })
  project.value = data
  await loadTasks()
  projectAssets.value = (await api<{ data: ProjectAsset[] }>(`/api/v1/projects/${projectId}/assets`)).data
})
</script>

<template>
  <main class="mx-auto max-w-4xl p-6">
    <NuxtLink to="/projects" class="text-sm text-muted hover:text-text">← Projects</NuxtLink>
    <h1 class="mt-2 text-2xl font-semibold">{{ project?.name ?? 'Project' }}</h1>

    <form class="mt-4 flex flex-wrap items-center gap-2 rounded-xl bg-panel p-3" @submit.prevent="planWithProducer">
      <span class="text-sm text-accent">Producer</span>
      <input v-model="goal" placeholder="Goal, e.g. a one-minute night market short" class="field !mt-0 min-w-64 flex-1" aria-label="Goal">
      <button :disabled="planning || !goal.trim()" class="rounded-lg bg-accent px-3 py-2 text-sm font-medium text-bg disabled:opacity-50" data-test="plan">
        Plan with Producer (demo)
      </button>
      <p v-if="planned" class="w-full text-xs text-muted" data-test="planned">
        {{ planned }} <NuxtLink to="/" class="text-accent hover:underline">See them on the dashboard</NuxtLink>
      </p>
    </form>
    <p v-if="project" class="mt-1 text-sm text-muted">{{ project.style }} · {{ project.aspect_ratio }}</p>

    <section class="mt-6">
      <h2 class="text-sm text-muted">Assets</h2>
      <ul class="mt-2 flex flex-wrap gap-2">
        <li v-for="item in projectAssets" :key="item.asset.id">
          <NuxtLink
            :to="{ path: '/assets', query: { open: item.asset.id } }"
            class="flex items-center gap-2 rounded-lg bg-panel px-3 py-1.5 text-sm hover:ring-1 hover:ring-accent/40"
          >
            <AppIcon :name="assetKindIcon(item.asset.kind)" :size="14" class="text-muted" />
            {{ item.asset.name }}
            <span class="font-mono text-xs text-muted">
              {{ item.pinned_version ? `v${item.pinned_version.number} pinned` : `v${item.asset.current_version?.number}` }}
            </span>
          </NuxtLink>
        </li>
        <li v-if="projectAssets.length === 0" class="text-sm text-muted">No assets yet. Add them from the asset library.</li>
      </ul>
    </section>

    <form class="mt-6 flex flex-wrap items-end gap-3 rounded-xl bg-panel p-4" @submit.prevent="createTask">
      <label class="w-32">
        <span class="text-sm text-muted">Module</span>
        <input v-model="form.module" required placeholder="story" class="field font-mono">
      </label>
      <label class="w-32">
        <span class="text-sm text-muted">Kind</span>
        <input v-model="form.kind" required placeholder="outline" class="field font-mono">
      </label>
      <label class="min-w-48 flex-1">
        <span class="text-sm text-muted">Title</span>
        <input v-model="form.title" required placeholder="What needs doing" class="field">
      </label>
      <label>
        <span class="text-sm text-muted">Mode</span>
        <select v-model="form.mode" class="field">
          <option v-for="mode in modes" :key="mode" :value="mode">{{ mode }}</option>
        </select>
      </label>
      <button class="rounded-lg bg-accent px-4 py-2 text-sm font-medium text-bg hover:opacity-90">Add task</button>
    </form>

    <p v-if="errors.message.value" class="mt-3 text-sm text-red-400">{{ errors.message.value }}</p>
    <p v-for="(message, field) in errors.fields.value" :key="field" class="mt-3 text-sm text-red-400">{{ message }}</p>

    <ul class="mt-6 space-y-3">
      <li v-for="task in tasks" :key="task.id" class="rounded-xl bg-panel p-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <div>
            <p>{{ task.title }}</p>
            <p class="mt-0.5 font-mono text-xs text-muted">{{ task.module }} / {{ task.kind }} · {{ task.mode }}</p>
          </div>
          <span class="rounded-full bg-media/10 px-2.5 py-0.5 text-xs text-media">{{ statusLabels[task.status] }}</span>
        </div>

        <div class="mt-3 flex flex-wrap items-center gap-2">
          <button
            v-for="to in task.allowed_transitions"
            :key="to"
            class="rounded-lg border border-muted/30 px-2.5 py-1 text-xs hover:border-accent hover:text-accent"
            @click="moveTask(task, to)"
          >
            {{ statusLabels[to] }}
          </button>
          <button class="ml-auto text-xs text-muted hover:text-text" @click="toggleEvents(task.id)">
            {{ events[task.id] ? 'Hide events' : 'Events' }}
          </button>
        </div>

        <ol v-if="events[task.id]" class="mt-3 space-y-1 border-t border-muted/15 pt-3">
          <li v-for="event in events[task.id]" :key="event.id" class="flex gap-3 text-xs">
            <span class="font-mono text-muted">{{ new Date(event.created_at).toLocaleTimeString() }}</span>
            <span>{{ describe(event) }}</span>
          </li>
        </ol>
      </li>
      <li v-if="tasks.length === 0" class="rounded-xl bg-panel px-4 py-6 text-center text-sm text-muted">No tasks yet.</li>
    </ul>

    <button
      v-if="nextCursor"
      class="mt-4 w-full rounded-lg border border-muted/30 px-4 py-2 text-sm hover:bg-panel"
      @click="run(() => loadTasks(nextCursor))"
    >
      Load more
    </button>
  </main>
</template>
