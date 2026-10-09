<script setup lang="ts">
import { findModule } from '@kalpa/core'
import type { CursorPage, Project, Task } from '~/types/api'

const route = useRoute()
const api = useApi()
const errors = useFormErrors()

const moduleKey = computed(() => route.params.module as string)
const module = computed(() => findModule(moduleKey.value))

const tasks = ref<Task[]>([])
const projects = ref<Project[]>([])
const nextCursor = ref<string | null>(null)
const showNewTask = ref(false)

async function load(cursor: string | null = null) {
  try {
    const page = await api<CursorPage<Task>>('/api/v1/tasks', {
      query: { module: moduleKey.value, cursor: cursor ?? undefined },
    })
    tasks.value = cursor ? [...tasks.value, ...page.data] : page.data
    nextCursor.value = page.meta.next_cursor
  } catch (error) {
    errors.show(error)
  }
}

async function openNewTask() {
  projects.value = (await api<CursorPage<Project>>('/api/v1/projects', { query: { per_page: 100 } })).data
  showNewTask.value = true
}

watch(moduleKey, () => load())
await load()
</script>

<template>
  <main class="mx-auto max-w-5xl p-6">
    <div v-if="!module" class="rounded-xl bg-panel p-6 text-sm text-muted">There is no "{{ moduleKey }}" module.</div>

    <template v-else>
      <div class="flex items-center justify-between gap-3">
        <h1 class="flex items-center gap-2 text-2xl font-semibold">
          <AppIcon :name="module.icon" :size="22" /> {{ module.name }}
        </h1>
        <button class="rounded-lg bg-accent px-3 py-1.5 text-sm font-medium text-bg hover:opacity-90" @click="openNewTask">
          New task
        </button>
      </div>

      <p v-if="errors.message.value" class="mt-4 text-sm text-red-400">{{ errors.message.value }}</p>

      <ul class="mt-6 divide-y divide-muted/15 overflow-hidden rounded-xl bg-panel">
        <li v-for="task in tasks" :key="task.id"><TaskRow :task="task" /></li>
        <li v-if="tasks.length === 0" class="px-4 py-8 text-center text-sm text-muted">No {{ module.name }} tasks yet.</li>
      </ul>

      <button
        v-if="nextCursor"
        class="mt-4 w-full rounded-lg border border-muted/30 px-4 py-2 text-sm hover:bg-panel"
        @click="load(nextCursor)"
      >
        Load more
      </button>

      <NewTaskDialog
        v-if="showNewTask"
        :projects="projects"
        :module-key="module.key"
        @close="showNewTask = false"
        @created="(task) => navigateTo(`/m/${task.module}/tasks/${task.id}`)"
      />
    </template>
  </main>
</template>
