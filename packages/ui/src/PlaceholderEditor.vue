<script setup lang="ts">
import { nextTick, onMounted, ref, watch } from 'vue'
import type { EditorTask } from '../../core/src/modules'
import type { Checkpoint } from '../../core/src/sync/checkpoint'

// Stand-in editor shown by every module until its real editor arrives. It already keeps a real
// checkpoint, so resuming on another device can be tried: notes (data) plus which tab is open
// and how far the details are scrolled (view).
const props = defineProps<{ moduleName: string; task: EditorTask; checkpoint: Checkpoint }>()
const emit = defineEmits<{ 'update:checkpoint': [checkpoint: Checkpoint] }>()

type Tab = 'notes' | 'details'

const tab = ref<Tab>('notes')
const notes = ref('')
const scroll = ref(0)
const details = ref<HTMLElement | null>(null)

// Take the checkpoint's state (on open, and when another device's version arrives).
function apply(checkpoint: Checkpoint) {
  tab.value = (checkpoint.view?.tab as Tab) ?? 'notes'
  scroll.value = Number(checkpoint.view?.scroll ?? 0)
  notes.value = String(checkpoint.data?.notes ?? '')
  void nextTick(() => {
    if (details.value) details.value.scrollTop = scroll.value
  })
}

function current(): Checkpoint {
  return { ...props.checkpoint, view: { tab: tab.value, scroll: scroll.value }, data: { ...props.checkpoint.data, notes: notes.value } }
}

function save() {
  emit('update:checkpoint', current())
}

function selectTab(next: Tab) {
  tab.value = next
  save()
}

// Scroll position is saved a moment after scrolling stops.
let scrollTimer: ReturnType<typeof setTimeout> | undefined
function onScroll(event: Event) {
  scroll.value = Math.round((event.target as HTMLElement).scrollTop)
  clearTimeout(scrollTimer)
  scrollTimer = setTimeout(save, 300)
}

watch(() => props.checkpoint, (checkpoint) => {
  if (JSON.stringify(checkpoint) !== JSON.stringify(current())) apply(checkpoint)
})

onMounted(() => apply(props.checkpoint))

// Something long enough to scroll, so the scroll position is worth keeping.
const shotList = Array.from({ length: 40 }, (_, i) => `Shot ${i + 1}: placeholder line for the ${props.moduleName} editor`)
</script>

<template>
  <section class="rounded-xl bg-panel p-6">
    <p class="text-sm text-muted">{{ moduleName }}</p>
    <h2 class="mt-1 text-xl font-semibold">{{ task.title }}</h2>
    <p class="mt-2 font-mono text-xs text-muted">status: {{ task.status }} · mode: {{ task.mode }}</p>

    <nav class="mt-6 flex gap-4 border-b border-muted/15 text-sm" role="tablist">
      <button
        v-for="name in (['notes', 'details'] as Tab[])"
        :key="name"
        role="tab"
        :aria-selected="tab === name"
        class="-mb-px border-b-2 pb-2 capitalize"
        :class="tab === name ? 'border-accent text-text' : 'border-transparent text-muted hover:text-text'"
        :data-test="`tab-${name}`"
        @click="selectTab(name)"
      >
        {{ name }}
      </button>
    </nav>

    <div v-if="tab === 'notes'" class="mt-4">
      <textarea
        v-model="notes"
        rows="8"
        placeholder="Notes for this task. They're saved on this device at once and synced to your other devices."
        class="w-full rounded-lg border border-muted/30 bg-bg p-3 text-sm outline-none focus:border-accent"
        data-test="notes"
        @input="save"
      />
    </div>

    <div v-else ref="details" class="mt-4 h-64 overflow-y-auto rounded-lg bg-bg p-3" data-test="details" @scroll="onScroll">
      <h3 class="text-sm text-muted">Outputs</h3>
      <ul class="mt-2 space-y-1 text-sm">
        <li v-for="output in task.outputs ?? []" :key="output.asset.id">
          {{ output.asset.name }}
          <span class="text-xs text-muted">{{ output.asset.kind_label }} · v{{ output.asset.current_version?.number }} · {{ output.role }}</span>
        </li>
        <li v-if="!task.outputs?.length" class="text-muted">No outputs yet.</li>
      </ul>

      <h3 class="mt-4 text-sm text-muted">Checkpoint</h3>
      <pre class="mt-2 overflow-x-auto font-mono text-xs">{{ JSON.stringify(checkpoint, null, 2) }}</pre>

      <h3 class="mt-4 text-sm text-muted">Shot list</h3>
      <ol class="mt-2 space-y-1 font-mono text-xs text-muted">
        <li v-for="line in shotList" :key="line">{{ line }}</li>
      </ol>
    </div>

    <p class="mt-6 text-sm text-accent">Editor coming in P1+</p>
  </section>
</template>
