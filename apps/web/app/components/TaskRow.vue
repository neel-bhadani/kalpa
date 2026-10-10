<script setup lang="ts">
import { findModule } from '@kalpa/core'
import type { Task } from '~/types/api'

// One line of a task list: module, title, status, project and when it last changed.
const props = defineProps<{ task: Task }>()
const sync = useSyncStore()

const icon = computed(() => findModule(props.task.module)?.icon ?? 'square')
</script>

<template>
  <NuxtLink
    :to="`/m/${task.module}/tasks/${task.id}`"
    class="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-3 hover:bg-bg/40 sm:flex-nowrap"
  >
    <span class="flex w-44 shrink-0 items-center gap-2 text-sm text-muted">
      <AppIcon :name="icon" :size="16" />
      <span class="truncate">{{ task.module_name ?? task.module }} task</span>
    </span>
    <span class="min-w-0 flex-1 truncate">{{ task.title }}</span>
    <UnsavedChip v-if="sync.isUnsaved(task)" data-test="unsaved" />
    <StatusBadge :task="task" />
    <span v-if="task.project_name" class="max-w-40 truncate text-sm text-muted">({{ task.project_name }})</span>
    <span class="w-20 shrink-0 text-right font-mono text-xs text-muted">{{ timeAgo(task.updated_at) }}</span>
  </NuxtLink>
</template>
