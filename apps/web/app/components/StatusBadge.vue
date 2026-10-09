<script setup lang="ts">
import type { Task } from '~/types/api'

const props = defineProps<{ task: Pick<Task, 'status' | 'progress'> }>()

const badge = computed(() => statusBadges[props.task.status])
</script>

<template>
  <span class="inline-flex shrink-0 items-center gap-1 rounded-full px-2.5 py-0.5 text-xs whitespace-nowrap" :class="badge.classes">
    {{ badge.label }}
    <span v-if="task.status === 'waiting_job'" class="font-mono">{{ task.progress }}%</span>
  </span>
</template>
