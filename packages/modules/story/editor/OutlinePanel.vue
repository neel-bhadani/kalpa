<script setup lang="ts">
// Left side: the story's acts and the script's scenes. Drag a row to reorder, click a scene to go there.
import { ref } from 'vue'
import type { Act } from '../spec'

defineProps<{ acts: Act[]; scenes: { id: string; heading: string }[] | null; currentSceneId: string | null; readonly?: boolean }>()
const emit = defineEmits<{ goToScene: [id: string]; moveScene: [id: string, to: number]; moveAct: [id: string, to: number] }>()

const dragging = ref<{ list: 'act' | 'scene'; id: string } | null>(null)
const over = ref<string | null>(null)

function drop(list: 'act' | 'scene', to: number) {
  const item = dragging.value
  dragging.value = null
  over.value = null
  if (!item || item.list !== list) return
  if (list === 'scene') emit('moveScene', item.id, to)
  else emit('moveAct', item.id, to)
}
</script>

<template>
  <nav class="flex min-h-0 flex-col gap-4 overflow-y-auto text-xs" aria-label="Outline" data-test="outline">
    <section v-if="acts.length">
      <h3 class="px-2 text-[11px] uppercase tracking-wide text-muted">Acts</h3>
      <ol class="mt-1 space-y-0.5">
        <li
          v-for="(act, i) in acts"
          :key="act.id"
          :draggable="!readonly"
          class="cursor-grab truncate rounded px-2 py-1 hover:bg-bg"
          :class="over === act.id && 'ring-1 ring-accent'"
          data-test="outline-act"
          @dragstart="dragging = { list: 'act', id: act.id }"
          @dragover.prevent="over = act.id"
          @dragleave="over = null"
          @drop.prevent="drop('act', i)"
        >
          {{ act.title || 'Untitled act' }}
        </li>
      </ol>
    </section>

    <section v-if="scenes">
      <h3 class="px-2 text-[11px] uppercase tracking-wide text-muted">Scenes · {{ scenes.length }}</h3>
      <ol class="mt-1 space-y-0.5">
        <li
          v-for="(scene, i) in scenes"
          :key="scene.id"
          :draggable="!readonly"
          class="flex cursor-pointer gap-2 rounded px-2 py-1 hover:bg-bg"
          :class="[scene.id === currentSceneId ? 'bg-accent/15 text-text' : 'text-muted', over === scene.id && 'ring-1 ring-accent']"
          :aria-current="scene.id === currentSceneId ? 'location' : undefined"
          data-test="outline-scene"
          @click="emit('goToScene', scene.id)"
          @dragstart="dragging = { list: 'scene', id: scene.id }"
          @dragover.prevent="over = scene.id"
          @dragleave="over = null"
          @drop.prevent="drop('scene', i)"
        >
          <span class="w-6 shrink-0 text-right font-mono">{{ i + 1 }}</span>
          <span class="min-w-0 truncate">{{ scene.heading || 'New scene' }}</span>
        </li>
      </ol>
    </section>
  </nav>
</template>
