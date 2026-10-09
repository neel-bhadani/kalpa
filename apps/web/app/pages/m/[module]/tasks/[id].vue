<script setup lang="ts">
import type { Component } from 'vue'
import { findModule } from '@kalpa/core'
import type { Task } from '~/types/api'

const route = useRoute()
const api = useApi()
const errors = useFormErrors()

const module = findModule(route.params.module as string)
const task = ref<Task | null>(null)

// The module's editor, downloaded only now (its own chunk).
const Editor = module
  ? defineAsyncComponent(() => module.editor() as Promise<{ default: Component }>)
  : null

try {
  task.value = (await api<{ data: Task }>(`/api/v1/tasks/${route.params.id}`)).data
} catch (error) {
  errors.show(error)
}
</script>

<template>
  <main class="mx-auto max-w-4xl p-6">
    <NuxtLink v-if="module" :to="`/m/${module.key}`" class="text-sm text-muted hover:text-text">← {{ module.name }}</NuxtLink>

    <p v-if="errors.message.value" class="mt-4 text-sm text-red-400">{{ errors.message.value }}</p>
    <p v-else-if="!module" class="mt-4 text-sm text-muted">There is no "{{ route.params.module }}" module.</p>

    <div v-if="task && Editor" class="mt-4">
      <component :is="Editor" :task="task" />
    </div>
  </main>
</template>
