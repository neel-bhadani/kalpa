<script setup lang="ts">
import type { EditorTask } from '../../core/src/modules'
import PlaceholderEditor from '../../ui/src/PlaceholderEditor.vue'

defineProps<{ task: EditorTask }>()
</script>

<template>
  <PlaceholderEditor module-name="Story & Script" :task="task" />
</template>
