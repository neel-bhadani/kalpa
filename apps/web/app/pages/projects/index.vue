<script setup lang="ts">
import type { AspectRatio, CursorPage, Project, ProjectStyle } from '~/types/api'

const api = useApi()
const errors = useFormErrors()

const projects = ref<Project[]>([])
const nextCursor = ref<string | null>(null)
const loading = ref(false)

const styles: ProjectStyle[] = ['2d', '3d', 'realistic', 'mixed']
const ratios: AspectRatio[] = ['16:9', '9:16', '1:1', '4:5', '4:3']

const form = reactive({ name: '', style: 'mixed' as ProjectStyle, aspect_ratio: '16:9' as AspectRatio })
const creating = ref(false)

async function load(cursor: string | null = null) {
  loading.value = true
  try {
    const page = await api<CursorPage<Project>>('/api/v1/projects', { query: { cursor: cursor ?? undefined } })
    projects.value = cursor ? [...projects.value, ...page.data] : page.data
    nextCursor.value = page.meta.next_cursor
  } catch (error) {
    errors.show(error)
  } finally {
    loading.value = false
  }
}

async function create() {
  creating.value = true
  errors.clear()
  try {
    const { data } = await api<{ data: Project }>('/api/v1/projects', { method: 'POST', body: form })
    projects.value = [data, ...projects.value]
    form.name = ''
  } catch (error) {
    errors.show(error)
  } finally {
    creating.value = false
  }
}

await load()
</script>

<template>
  <main class="mx-auto max-w-3xl p-6">
    <NuxtLink to="/" class="text-sm text-muted hover:text-text">← Back</NuxtLink>
    <h1 class="mt-2 text-2xl font-semibold">Projects</h1>

    <form class="mt-6 flex flex-wrap items-end gap-3 rounded-xl bg-panel p-4" @submit.prevent="create">
      <label class="min-w-48 flex-1">
        <span class="text-sm text-muted">New project</span>
        <input v-model="form.name" required placeholder="Name" class="field">
      </label>
      <label>
        <span class="text-sm text-muted">Style</span>
        <select v-model="form.style" class="field">
          <option v-for="style in styles" :key="style" :value="style">{{ style }}</option>
        </select>
      </label>
      <label>
        <span class="text-sm text-muted">Aspect</span>
        <select v-model="form.aspect_ratio" class="field">
          <option v-for="ratio in ratios" :key="ratio" :value="ratio">{{ ratio }}</option>
        </select>
      </label>
      <button :disabled="creating" class="rounded-lg bg-accent px-4 py-2 text-sm font-medium text-bg hover:opacity-90 disabled:opacity-50">
        Create
      </button>
    </form>

    <p v-if="errors.message.value" class="mt-3 text-sm text-red-400">{{ errors.message.value }}</p>
    <p v-for="(message, field) in errors.fields.value" :key="field" class="mt-3 text-sm text-red-400">{{ message }}</p>

    <ul class="mt-6 divide-y divide-muted/15 rounded-xl bg-panel">
      <li v-for="project in projects" :key="project.id">
        <NuxtLink :to="`/projects/${project.id}`" class="flex items-center justify-between px-4 py-3 hover:bg-bg/40">
          <span>{{ project.name }}</span>
          <span class="text-xs text-muted">{{ project.style }} · {{ project.aspect_ratio }} · {{ project.status }}</span>
        </NuxtLink>
      </li>
      <li v-if="!loading && projects.length === 0" class="px-4 py-6 text-center text-sm text-muted">No projects yet.</li>
    </ul>

    <button
      v-if="nextCursor"
      :disabled="loading"
      class="mt-4 w-full rounded-lg border border-muted/30 px-4 py-2 text-sm hover:bg-panel"
      @click="load(nextCursor)"
    >
      Load more
    </button>
  </main>
</template>
