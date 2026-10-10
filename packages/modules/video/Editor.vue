<script setup lang="ts">
import type { EditorTask } from '../../core/src/modules'
import type { Checkpoint } from '../../core/src/sync/checkpoint'
import PlaceholderEditor from '../../ui/src/PlaceholderEditor.vue'

// Every editor gets the task and its checkpoint, and emits changes to the checkpoint.
defineProps<{ task: EditorTask; checkpoint: Checkpoint }>()
const emit = defineEmits<{ 'update:checkpoint': [checkpoint: Checkpoint] }>()
</script>

<template>
  <PlaceholderEditor module-name="Video" :task="task" :checkpoint="checkpoint" @update:checkpoint="emit('update:checkpoint', $event)" />
</template>
