<script setup lang="ts">
import type { Project, Task } from '~/types/api'

// "New task": pick a project, then a module, then one of its kinds, then a title.
const props = defineProps<{ projects: Project[]; projectId?: string; moduleKey?: string }>()
const emit = defineEmits<{ close: []; created: [task: Task] }>()

const api = useApi()
const modules = useModulesStore()
const errors = useFormErrors()

const form = reactive({
  project: props.projectId ?? props.projects[0]?.id ?? '',
  module: props.moduleKey ?? modules.enabled[0]?.key ?? '',
  kind: '',
  title: '',
})
const busy = ref(false)

const kinds = computed(() => modules.serverModule(form.module)?.task_kinds ?? [])

// A new module means a new list of kinds; default to its first one.
watch(() => form.module, () => {
  form.kind = kinds.value[0]?.key ?? ''
}, { immediate: true })

async function submit() {
  busy.value = true
  errors.clear()
  try {
    const { data } = await api<{ data: Task }>(`/api/v1/projects/${form.project}/tasks`, {
      method: 'POST',
      body: { module: form.module, kind: form.kind, title: form.title },
    })
    emit('created', data)
  } catch (error) {
    errors.show(error)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="fixed inset-0 z-20 flex items-center justify-center bg-bg/80 p-4" @click.self="emit('close')">
    <form
      class="w-full max-w-md space-y-4 rounded-xl bg-panel p-6"
      role="dialog"
      aria-labelledby="new-task-title"
      @submit.prevent="submit"
      @keydown.esc="emit('close')"
    >
      <h2 id="new-task-title" class="text-lg font-semibold">New task</h2>

      <p v-if="errors.message.value" class="text-sm text-red-400">{{ errors.message.value }}</p>

      <label class="block">
        <span class="text-sm text-muted">Project</span>
        <select v-model="form.project" required class="field">
          <option v-for="project in projects" :key="project.id" :value="project.id">{{ project.name }}</option>
        </select>
      </label>

      <label class="block">
        <span class="text-sm text-muted">Module</span>
        <select v-model="form.module" required class="field">
          <option v-for="module in modules.enabled" :key="module.key" :value="module.key">{{ module.name }}</option>
        </select>
        <span v-if="errors.fields.value.module" class="mt-1 block text-sm text-red-400">{{ errors.fields.value.module }}</span>
      </label>

      <label class="block">
        <span class="text-sm text-muted">Kind</span>
        <select v-model="form.kind" required class="field">
          <option v-for="kind in kinds" :key="kind.key" :value="kind.key">{{ kind.label }}</option>
        </select>
        <span v-if="errors.fields.value.kind" class="mt-1 block text-sm text-red-400">{{ errors.fields.value.kind }}</span>
      </label>

      <label class="block">
        <span class="text-sm text-muted">Title</span>
        <input v-model="form.title" required placeholder="What needs doing" class="field">
        <span v-if="errors.fields.value.title" class="mt-1 block text-sm text-red-400">{{ errors.fields.value.title }}</span>
      </label>

      <div class="flex justify-end gap-2 pt-2">
        <button type="button" class="rounded-lg px-4 py-2 text-sm text-muted hover:text-text" @click="emit('close')">Cancel</button>
        <button :disabled="busy" class="rounded-lg bg-accent px-4 py-2 text-sm font-medium text-bg hover:opacity-90 disabled:opacity-50">
          Create task
        </button>
      </div>
    </form>
  </div>
</template>
