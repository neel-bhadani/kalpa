<script setup lang="ts">
import type { EditorTask } from '../../core/src/modules'

// Stand-in editor shown by every module until its real editor arrives.
defineProps<{ moduleName: string; task: EditorTask }>()
</script>

<template>
  <section class="rounded-xl bg-panel p-6">
    <p class="text-sm text-muted">{{ moduleName }}</p>
    <h2 class="mt-1 text-xl font-semibold">{{ task.title }}</h2>
    <p class="mt-2 font-mono text-xs text-muted">status: {{ task.status }} · mode: {{ task.mode }}</p>

    <h3 class="mt-6 text-sm text-muted">Checkpoint</h3>
    <pre class="mt-2 overflow-x-auto rounded-lg bg-bg p-3 font-mono text-xs">{{ JSON.stringify(task.checkpoint, null, 2) }}</pre>

    <p class="mt-6 text-sm text-accent">Editor coming in P1+</p>
  </section>
</template>
