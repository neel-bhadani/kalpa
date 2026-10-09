<script setup lang="ts">
import { findModule } from '@kalpa/core'
import type { CursorPage, Dashboard, Project, Task, TaskStatus } from '~/types/api'

const api = useApi()
const modules = useModulesStore()
const errors = useFormErrors()

const projects = ref<Project[]>([])
const continueTasks = ref<Task[]>([])
const counts = ref<Partial<Record<TaskStatus, number>>>({})
const tasks = ref<Task[]>([])
const nextCursor = ref<string | null>(null)
const loading = ref(true)
const showNewTask = ref(false)

// Filters: one module, one status chip (which may cover several statuses), one project.
const filters = reactive({ module: '', status: '', project: '' })

async function load(cursor: string | null = null) {
  loading.value = true
  try {
    const page = await api<Dashboard>('/api/v1/dashboard', {
      query: {
        module: filters.module || undefined,
        status: filters.status || undefined,
        project: filters.project || undefined,
        cursor: cursor ?? undefined,
      },
    })
    continueTasks.value = page.data.continue
    counts.value = page.data.counts
    tasks.value = cursor ? [...tasks.value, ...page.data.tasks] : page.data.tasks
    nextCursor.value = page.meta.next_cursor
  } catch (error) {
    errors.show(error)
  } finally {
    loading.value = false
  }
}

async function loadProjects() {
  projects.value = (await api<CursorPage<Project>>('/api/v1/projects', { query: { per_page: 100 } })).data
}

function countFor(statuses: TaskStatus[]): number {
  return statuses.reduce((sum, status) => sum + (counts.value[status] ?? 0), 0)
}

function onCreated(task: Task) {
  showNewTask.value = false
  navigateTo(`/m/${task.module}/tasks/${task.id}`)
}

watch(filters, () => load())

await Promise.all([load(), loadProjects()])
</script>

<template>
  <main class="mx-auto max-w-5xl p-6">
    <p v-if="errors.message.value" class="mb-4 text-sm text-red-400">{{ errors.message.value }}</p>

    <!-- Empty state: nothing to work on until there's a project. -->
    <section v-if="!loading && projects.length === 0" class="rounded-xl bg-panel p-10 text-center">
      <h1 class="text-xl font-semibold">Start your first project</h1>
      <p class="mt-2 text-sm text-muted">Projects hold your story, characters, voices, music and video.</p>
      <NuxtLink to="/projects" class="mt-6 inline-block rounded-lg bg-accent px-4 py-2 text-sm font-medium text-bg hover:opacity-90">
        Create your first project
      </NuxtLink>
    </section>

    <template v-else>
      <section v-if="continueTasks.length">
        <h2 class="text-sm text-muted">Continue where you left off</h2>
        <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          <NuxtLink
            v-for="task in continueTasks"
            :key="task.id"
            :to="`/m/${task.module}/tasks/${task.id}`"
            class="rounded-xl bg-panel p-4 hover:ring-1 hover:ring-accent/40"
          >
            <div class="flex items-center justify-between gap-2 text-muted">
              <AppIcon :name="findModule(task.module)?.icon ?? 'square'" />
              <StatusBadge :task="task" />
            </div>
            <p class="mt-3 truncate font-medium">{{ task.title }}</p>
            <p class="mt-1 truncate text-sm text-muted">{{ task.project_name }}</p>
            <div class="mt-3 flex items-center justify-between">
              <span class="font-mono text-xs text-muted">{{ timeAgo(task.updated_at) }}</span>
              <UnsavedChip v-if="task.has_unsynced_changes" />
            </div>
          </NuxtLink>
        </div>
      </section>

      <section class="mt-8">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <h2 class="text-lg font-semibold">Projects detailed</h2>
          <div class="flex items-center gap-2">
            <select v-model="filters.project" class="rounded-lg border border-muted/30 bg-bg px-2 py-1.5 text-sm" aria-label="Project">
              <option value="">All projects</option>
              <option v-for="project in projects" :key="project.id" :value="project.id">{{ project.name }}</option>
            </select>
            <button
              class="flex items-center gap-1 rounded-lg bg-accent px-3 py-1.5 text-sm font-medium text-bg hover:opacity-90"
              @click="showNewTask = true"
            >
              New task
            </button>
          </div>
        </div>

        <div class="mt-4 flex flex-wrap gap-2" aria-label="Module filter">
          <button class="chip" :class="{ 'chip-on': filters.module === '' }" @click="filters.module = ''">All modules</button>
          <button
            v-for="module in modules.enabled"
            :key="module.key"
            class="chip"
            :class="{ 'chip-on': filters.module === module.key }"
            @click="filters.module = module.key"
          >
            <AppIcon :name="module.icon" :size="14" /> {{ module.name }}
          </button>
        </div>

        <div class="mt-2 flex flex-wrap gap-2" aria-label="Status filter">
          <button class="chip" :class="{ 'chip-on': filters.status === '' }" @click="filters.status = ''">All statuses</button>
          <button
            v-for="filter in statusFilters"
            :key="filter.label"
            class="chip"
            :class="{ 'chip-on': filters.status === filter.statuses.join(',') }"
            @click="filters.status = filter.statuses.join(',')"
          >
            {{ filter.label }} <span class="font-mono text-xs opacity-70">{{ countFor(filter.statuses) }}</span>
          </button>
        </div>

        <ul class="mt-4 divide-y divide-muted/15 overflow-hidden rounded-xl bg-panel">
          <li v-for="task in tasks" :key="task.id"><TaskRow :task="task" /></li>
          <li v-if="!loading && tasks.length === 0" class="px-4 py-8 text-center text-sm text-muted">
            No tasks here yet.
          </li>
        </ul>

        <button
          v-if="nextCursor"
          :disabled="loading"
          class="mt-4 w-full rounded-lg border border-muted/30 px-4 py-2 text-sm hover:bg-panel"
          @click="load(nextCursor)"
        >
          Load more
        </button>
      </section>
    </template>

    <NewTaskDialog
      v-if="showNewTask"
      :projects="projects"
      :project-id="filters.project || undefined"
      :module-key="filters.module || undefined"
      @close="showNewTask = false"
      @created="onCreated"
    />
  </main>
</template>
