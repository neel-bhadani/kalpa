<script setup lang="ts">
import { nextTick, onMounted, ref, watch } from 'vue'
import type { EditorHighlight, EditorTask } from '../../core/src/modules'
import type { Checkpoint } from '../../core/src/sync/checkpoint'
import { placeholderCommands } from './placeholder-commands'

// Stand-in editor shown by every module until its real editor arrives. It already keeps a real
// checkpoint, so resuming on another device can be tried: notes (data) plus which tab is open
// and how far the details are scrolled (view). Its tab buttons and typing go through the same
// commands an assistant uses (placeholder-commands.ts); `highlight` shows an assistant's step happening.
const props = defineProps<{ moduleName: string; task: EditorTask; checkpoint: Checkpoint; highlight?: EditorHighlight; readonly?: boolean }>()
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

// The same command the assistant's view.selectTab runs.
function selectTab(next: Tab) {
  tab.value = next
  emit('update:checkpoint', placeholderCommands['view.selectTab']!.apply(current(), { tab: next }))
}

// Typing: the same command as the assistant's notes.replace.
function onNotesInput() {
  emit('update:checkpoint', placeholderCommands['notes.replace']!.apply(current(), { text: notes.value }))
}

// Visible process: briefly mark what an assistant step just changed.
const flashTab = ref(false)
const added = ref('')
const notesField = ref<HTMLTextAreaElement | null>(null)
let flashTimer: ReturnType<typeof setTimeout> | undefined

watch(() => props.highlight, async (change) => {
  if (!change) return
  clearTimeout(flashTimer)
  if (change.tool === 'view.selectTab') {
    flashTab.value = true
  } else if (change.tool.startsWith('notes.')) {
    added.value = String(change.args.text ?? '')
    await nextTick()
    // Select the new text so it stands out in the field.
    const field = notesField.value
    const start = field ? field.value.lastIndexOf(added.value) : -1
    if (field && start >= 0) field.setSelectionRange(start, start + added.value.length)
  }
  flashTimer = setTimeout(() => {
    flashTab.value = false
    added.value = ''
  }, 2500)
})

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

    <nav
      class="mt-6 flex gap-4 border-b border-muted/15 text-sm transition-shadow"
      :class="flashTab && 'rounded-md shadow-[0_0_0_2px_rgba(139,124,255,0.6)]'"
      role="tablist"
    >
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
        ref="notesField"
        v-model="notes"
        rows="8"
        placeholder="Notes for this task. They're saved on this device at once and synced to your other devices."
        class="w-full rounded-lg border bg-bg p-3 text-sm outline-none transition-colors focus:border-accent"
        :class="added ? 'border-accent shadow-[0_0_0_3px_rgba(139,124,255,0.25)]' : 'border-muted/30'"
        data-test="notes"
        :readonly="readonly"
        @input="onNotesInput"
      />
      <p v-if="readonly" class="mt-2 text-xs text-muted" data-test="read-only">Read only: you can view this task but not change it.</p>
      <p v-if="added" class="mt-2 text-xs text-accent" data-test="added">✦ Added by the assistant: {{ added }}</p>
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
