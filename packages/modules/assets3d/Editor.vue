<script setup lang="ts">
import type { EditorHighlight, EditorTask } from '../../core/src/modules'
import type { Checkpoint } from '../../core/src/sync/checkpoint'
import PlaceholderEditor from '../../ui/src/PlaceholderEditor.vue'

// Every editor gets the task, its checkpoint and what an assistant step just changed (highlight),
// and emits changes to the checkpoint.
defineProps<{ task: EditorTask; checkpoint: Checkpoint; highlight?: EditorHighlight }>()
const emit = defineEmits<{ 'update:checkpoint': [checkpoint: Checkpoint] }>()
</script>

<template>
  <PlaceholderEditor
    module-name="3D Assets"
    :task="task"
    :checkpoint="checkpoint"
    :highlight="highlight"
    @update:checkpoint="emit('update:checkpoint', $event)"
  />
</template>
