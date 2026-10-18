<script setup lang="ts">
// The Story & Script editor: outline (left), screenplay page (centre), Story panel (right).
// The task's story and script are worked on as drafts in the task checkpoint: edits go there in
// each pause while typing (saved on this device and synced), and become an asset version after
// 10 s idle, on Ctrl+S or when leaving the editor. Reopening the task brings back the cursor scene and scroll.
import { computed, customRef, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import type { EditorApi, EditorHighlight, EditorTask } from '../../core/src/modules'
import type { Checkpoint } from '../../core/src/sync/checkpoint'
import { VersionCommitter, type CommitState } from './committer'
import { diffScript } from './diff'
import { isDirty, newDraft, runCommand, type Draft } from './draft'
import OutlinePanel from './editor/OutlinePanel.vue'
import ScreenplayView, { type Cursor } from './editor/ScreenplayView.vue'
import StoryPanel from './editor/StoryPanel.vue'
import { TYPE_LABELS } from './editor/plugins'
import { estimate, formatRuntime, type ElementType } from './screenplay'
import type { ScriptSpec, SpecKind, StorySpec } from './spec'

type View = { cursor?: Cursor; scrollTop?: number; storyPanel?: boolean }
type StoryCheckpoint = Checkpoint & { view?: View; story?: Draft<StorySpec>; script?: Draft<ScriptSpec> }
type Version = { id: string; number: number; message: string | null; author?: string | null; created_at: string; spec?: unknown }

const props = defineProps<{ task: EditorTask; checkpoint: Checkpoint; highlight?: EditorHighlight; readonly?: boolean; api?: EditorApi }>()
const emit = defineEmits<{ 'update:checkpoint': [checkpoint: Checkpoint] }>()

const LANGUAGES = [
  ['en', 'English'], ['hi', 'हिन्दी'], ['gu', 'ગુજરાતી'], ['mr', 'मराठी'], ['bn', 'বাংলা'],
  ['ta', 'தமிழ்'], ['te', 'తెలుగు'], ['es', 'Español'], ['fr', 'Français'],
] as const

// Drafts are replaced, never changed in place, so Vue only tracks the outer object. Typing sets
// the draft quietly: code reading .value gets it at once, but the shell (outline, page count,
// status) redraws at most every 300 ms — redrawing it on every keystroke costs ~16 ms here.
function draftRef<D>() {
  let value: D | null = null
  let timer: ReturnType<typeof setTimeout> | null = null
  let notify = () => {}
  const draft = customRef<D | null>((track, trigger) => {
    notify = trigger
    return {
      get: () => {
        track()
        return value
      },
      set: (next) => {
        value = next
        if (timer) clearTimeout(timer)
        timer = null
        trigger()
      },
    }
  })
  return Object.assign(draft, {
    quiet(next: D) {
      value = next
      timer ??= setTimeout(() => {
        timer = null
        notify()
      }, 300)
    },
  })
}

const drafts = { story: draftRef<Draft<StorySpec>>(), script: draftRef<Draft<ScriptSpec>>() }
const story = drafts.story
const script = drafts.script
// Saved with the checkpoint. Not reactive: the cursor moves with every keystroke.
let view: View = { storyPanel: true, ...((props.checkpoint as StoryCheckpoint).view ?? {}) }
const storyPanel = ref(view.storyPanel !== false)
const currentSceneId = ref<string | null>(view.cursor?.sceneId ?? null)
// Where to resume (the page reports its own starting cursor as soon as it's shown).
const resume = { cursor: view.cursor, scrollTop: view.scrollTop }
const element = ref<ElementType>('action')
const states = reactive<Record<SpecKind, CommitState>>({ story: 'saved', script: 'saved' })
const errors = reactive<Record<SpecKind, string>>({ story: '', script: '' })
const loadError = ref('')
const screenplay = ref<InstanceType<typeof ScreenplayView> | null>(null)
const page = ref<HTMLElement | null>(null)

// The outline's rows: the same list while no scene was added, moved, removed or renamed, so typing
// in a scene doesn't redraw the outline.
let outlineRows: { id: string; heading: string }[] | null = null
const scenes = computed(() => {
  const list = script.value?.spec.scenes
  if (!list) return null
  const same = outlineRows?.length === list.length && list.every((scene, i) => outlineRows![i]!.id === scene.id && outlineRows![i]!.heading === scene.heading)
  if (!same) outlineRows = list.map(scene => ({ id: scene.id, heading: scene.heading }))
  return outlineRows
})
const length = computed(() => (script.value ? estimate(script.value.spec.scenes) : null))
const characters = computed(() => story.value?.spec.characters ?? [])
const language = computed(() => script.value?.spec.language ?? story.value?.spec.language ?? 'en')
// The version shown in the top bar: the script's, or the story's on a story task.
const main = computed<SpecKind>(() => (script.value ? 'script' : 'story'))
const status = computed(() => {
  const kind = main.value
  const draft = drafts[kind].value
  if (!draft) return ''
  if (states[kind] === 'saving') return 'saving…'
  if (states[kind] === 'error') return `not saved: ${errors[kind]}`
  return isDirty(draft) ? 'unsaved changes' : `v${draft.baseVersion} · saved`
})

// ---- Loading: each draft from the checkpoint, or the asset's current version.

let loaded = false

async function load() {
  const checkpoint = props.checkpoint as StoryCheckpoint
  const outputs = props.task.outputs ?? []
  for (const kind of ['story', 'script'] as const) {
    const asset = outputs.find(output => output.asset.kind === kind)?.asset
    const local = checkpoint[kind]
    const current = drafts[kind].value ?? (local && (!asset || local.assetId === asset.id) ? local : null)
    const latest = asset?.current_version?.number
    // A draft with unsaved edits stays (its commit lands on top); a clean one follows the server.
    if (asset && props.api && latest && (!current || (!isDirty(current) && current.baseVersion !== latest))) {
      const version = await props.api<{ data: Version }>(`/api/v1/assets/${asset.id}/versions/${latest}`)
      setDraft(kind, newDraft(asset.id, kind, version.data as { number: number; spec: any }), true)
    } else if (current && current !== drafts[kind].value) {
      setDraft(kind, current, true)
    }
    if (current && isDirty(current) && !props.readonly) committers[kind].touched()
  }
  if (!loaded && (script.value || story.value)) {
    loaded = true
    await nextTick()
    if (resume.cursor) {
      screenplay.value?.placeCursor(resume.cursor)
      if (!props.readonly) screenplay.value?.focus()
    }
    if (page.value && resume.scrollTop) page.value.scrollTop = resume.scrollTop
  }
}

// external: the draft came from elsewhere, so the page must show it.
function setDraft(kind: SpecKind, draft: Draft<any>, external = false) {
  drafts[kind].value = draft
  if (external && kind === 'script') screenplay.value?.setScript(draft.spec, view.cursor)
}

watch(() => props.task.outputs, () => load().catch(fail), { deep: true })

// The checkpoint changed elsewhere: the assistant, another device, a conflict resolved. A copy this
// editor saved itself (the sync echoes it back a little later) is never newer than what's shown.
const editorId = crypto.randomUUID()
watch(() => props.checkpoint, (next) => {
  for (const kind of ['story', 'script'] as const) {
    const incoming = (next as StoryCheckpoint)[kind]
    const local = drafts[kind].value
    if (!incoming || !local) continue
    const echo = incoming.writer === editorId && incoming.assetId === local.assetId && incoming.rev <= local.rev
    if (!echo && incoming !== local) setDraft(kind, incoming, true)
  }
})

function fail(error: unknown) {
  loadError.value = error instanceof Error ? error.message : 'The script could not be loaded.'
}

// ---- Saving: the checkpoint in each pause while typing, versions through the committers.
// Writing a long script's checkpoint to this device's storage takes tens of milliseconds, so it
// happens 0.8 s after the last keystroke (at least every 4 s), in idle time; and at once on
// Ctrl+S, when the tab is hidden and when leaving.

const PAUSE_MS = 800
const MAX_WAIT_MS = 4000
let persistTimer: ReturnType<typeof setTimeout> | null = null
let firstUnsaved: number | null = null

function persist() {
  if (props.readonly) return
  firstUnsaved ??= Date.now()
  if (persistTimer) clearTimeout(persistTimer)
  const wait = Math.max(0, Math.min(PAUSE_MS, firstUnsaved + MAX_WAIT_MS - Date.now()))
  persistTimer = setTimeout(() => {
    persistTimer = null
    if ('requestIdleCallback' in window) window.requestIdleCallback(persistNow, { timeout: 500 })
    else persistNow()
  }, wait)
}

function persistNow() {
  if (persistTimer) clearTimeout(persistTimer)
  persistTimer = null
  firstUnsaved = null
  if (props.readonly) return
  const stamp = <D extends Draft<any>>(draft: D | null) => (draft ? { ...draft, writer: editorId } : undefined)
  const next: StoryCheckpoint = { ...props.checkpoint, view, story: stamp(story.value), script: stamp(script.value) }
  emit('update:checkpoint', next)
}

function committer(kind: SpecKind) {
  return new VersionCommitter({
    get: () => drafts[kind].value!,
    set: (draft) => {
      drafts[kind].value = draft
      persist()
    },
    send: async (assetId, body) => {
      try {
        const { data } = await props.api!<{ data: Version }>(`/api/v1/assets/${assetId}/versions`, { method: 'POST', body })
        return { ok: true, version: data.number }
      } catch (error) {
        const { status, data } = (error ?? {}) as { status?: number; data?: { data?: Version; message?: string } }
        if (status === 409 && data?.data) return { ok: false, conflict: data.data.number }
        // A refused version keeps its edits here; fixing them saves again.
        throw new Error(status === 422 ? `refused: ${data?.message ?? 'a change is not allowed'}` : 'offline, will retry')
      }
    },
  }, (state) => {
    states[kind] = state
    errors[kind] = committers[kind]?.error ?? ''
  })
}

const committers: Record<SpecKind, VersionCommitter> = { story: committer('story'), script: committer('script') }

function commitAll(): Promise<unknown> {
  persistNow()
  return Promise.all((['story', 'script'] as const).filter(kind => drafts[kind].value).map(kind => committers[kind].commit()))
}

// ---- Edits.

function changed(kind: SpecKind) {
  committers[kind].touched()
  persist()
}

// Typing on the page: the script read back from it, as commands.
function onScriptChange(next: ScriptSpec) {
  let draft = script.value!
  for (const call of diffScript(draft.spec, next)) draft = runCommand(draft, call.name, call.args).draft
  script.quiet(draft)
  changed('script')
}

const actionError = ref('')

// A command from the outline or a panel. Script changes are shown on the page too.
function run(name: string, args: Record<string, unknown>) {
  const kind: SpecKind = name.startsWith('script.') ? 'script' : 'story'
  const draft = drafts[kind].value
  if (!draft || props.readonly) return
  actionError.value = ''
  try {
    setDraft(kind, runCommand(draft, name, args).draft, kind === 'script')
    changed(kind)
  } catch (error) {
    actionError.value = error instanceof Error ? error.message : String(error)
  }
}

function setLanguage(code: string) {
  if (script.value) run('script.setField', { field: 'language', value: code })
  if (story.value) run('story.setField', { field: 'language', value: code })
}

function onCursor(cursor: Cursor, type: ElementType) {
  element.value = type
  currentSceneId.value = cursor.sceneId
  view = { ...view, cursor }
  persist()
}

function onScroll() {
  if (!page.value) return
  view = { ...view, scrollTop: Math.round(page.value.scrollTop) }
  persist()
}

function toggleStoryPanel() {
  storyPanel.value = !storyPanel.value
  view = { ...view, storyPanel: storyPanel.value }
  persist()
}

// ---- Versions.

const versions = ref<Version[] | null>(null)
const versionError = ref('')

async function toggleVersions() {
  if (versions.value) {
    versions.value = null
    return
  }
  await loadVersions()
}

async function loadVersions() {
  const draft = drafts[main.value].value
  if (!draft || !props.api) return
  versions.value = (await props.api<{ data: Version[] }>(`/api/v1/assets/${draft.assetId}/versions`, { query: { per_page: 50 } })).data
}

async function restore(number: number) {
  const kind = main.value
  const draft = drafts[kind].value
  if (!draft || !props.api) return
  versionError.value = ''
  try {
    await commitAll()
    const { data } = await props.api<{ data: Version }>(`/api/v1/assets/${draft.assetId}/restore/${number}`, { method: 'POST' })
    setDraft(kind, { ...newDraft(draft.assetId, kind, data as { number: number; spec: any }), rev: drafts[kind].value!.rev + 1 }, true)
    states[kind] = 'saved'
    persistNow()
    await loadVersions()
  } catch {
    versionError.value = 'Restoring did not work. Check the connection and try again.'
  }
}

// ---- Keys and leaving.

function onKey(event: KeyboardEvent) {
  if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
    event.preventDefault()
    if (!props.readonly) void commitAll()
  }
}

function onLeave() {
  if (!props.readonly) void commitAll()
}

function onVisibility() {
  if (document.visibilityState === 'hidden') persistNow()
}

onMounted(() => {
  window.addEventListener('keydown', onKey)
  window.addEventListener('pagehide', onLeave)
  document.addEventListener('visibilitychange', onVisibility)
  load().catch(fail)
})

onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKey)
  window.removeEventListener('pagehide', onLeave)
  document.removeEventListener('visibilitychange', onVisibility)
  onLeave()
  for (const kind of ['story', 'script'] as const) committers[kind].dispose()
})
</script>

<template>
  <div class="rounded-xl bg-panel" data-test="story-editor">
    <header class="flex flex-wrap items-center gap-3 border-b border-muted/15 px-4 py-2 text-xs">
      <p class="min-w-0 flex-1 truncate text-sm font-medium">{{ script?.spec.title ?? story?.spec.title ?? task.title }}</p>
      <span v-if="length" class="font-mono text-muted" data-test="page-estimate" title="About one page a minute">
        {{ length.pages.toFixed(1) }} pages · ~{{ formatRuntime(length.minutes) }}
      </span>
      <select
        v-if="script && !readonly"
        :value="element"
        aria-label="Element"
        class="rounded-md border border-muted/25 bg-bg px-1.5 py-1"
        data-test="element-type"
        @change="screenplay?.setType(($event.target as HTMLSelectElement).value as ElementType)"
      >
        <option v-for="(label, type) in TYPE_LABELS" :key="type" :value="type">{{ label }}</option>
      </select>
      <select :value="language" :disabled="readonly" aria-label="Language" class="rounded-md border border-muted/25 bg-bg px-1.5 py-1" data-test="language" @change="setLanguage(($event.target as HTMLSelectElement).value)">
        <option v-for="[code, name] in LANGUAGES" :key="code" :value="code">{{ name }}</option>
      </select>
      <span v-if="readonly" class="rounded-full bg-muted/15 px-2 py-0.5 text-muted" data-test="read-only">Read only</span>
      <button class="font-mono" :class="states[main] === 'error' ? 'text-red-400' : 'text-muted hover:text-text'" data-test="version-indicator" @click="toggleVersions">
        {{ status }}
      </button>
      <button v-if="script" class="text-muted hover:text-text" :aria-pressed="storyPanel" @click="toggleStoryPanel">Story</button>
    </header>

    <p v-if="loadError" class="px-4 py-2 text-xs text-red-400">{{ loadError }}</p>
    <p v-if="actionError" class="px-4 py-2 text-xs text-red-400" data-test="editor-error">{{ actionError }}</p>

    <section v-if="versions" class="border-b border-muted/15 px-4 py-2 text-xs" data-test="versions">
      <p v-if="versionError" class="text-red-400">{{ versionError }}</p>
      <ol class="max-h-48 space-y-1 overflow-y-auto">
        <li v-for="version in versions" :key="version.id" class="flex items-center gap-3" data-test="version">
          <span class="w-10 font-mono text-muted">v{{ version.number }}</span>
          <span class="min-w-0 flex-1 truncate">{{ version.message ?? '—' }}</span>
          <span class="text-muted">{{ version.author ?? '' }}</span>
          <button
            v-if="!readonly && version.number !== drafts[main].value?.baseVersion"
            class="text-accent hover:underline"
            data-test="restore"
            @click="restore(version.number)"
          >
            Restore
          </button>
          <span v-else class="w-12 text-muted">current</span>
        </li>
      </ol>
    </section>

    <div
      class="grid h-[calc(100vh-14rem)] min-h-96 gap-3 p-3"
      :class="script && storyPanel ? 'grid-cols-[180px_minmax(0,1fr)_260px]' : 'grid-cols-[180px_minmax(0,1fr)]'"
    >
      <OutlinePanel
        :acts="story?.spec.acts ?? []"
        :scenes="scenes"
        :current-scene-id="currentSceneId"
        :readonly="readonly"
        @go-to-scene="screenplay?.goToScene($event)"
        @move-scene="(id, to) => run('script.moveScene', { id, to })"
        @move-act="(id, to) => run('story.moveAct', { id, to })"
      />

      <div v-if="script" ref="page" class="min-h-0 overflow-y-auto rounded-lg bg-bg" data-test="page-scroll" @scroll.passive="onScroll">
        <ScreenplayView
          ref="screenplay"
          :script="script.spec"
          :characters="characters"
          :readonly="readonly"
          :language="language"
          @change="onScriptChange"
          @cursor="onCursor"
        />
      </div>

      <!-- A story task: the acts in the centre. -->
      <div v-else-if="story" class="min-h-0 space-y-3 overflow-y-auto" data-test="acts">
        <div v-for="act in story.spec.acts ?? []" :key="act.id" class="rounded-lg bg-bg p-3">
          <div class="flex gap-2">
            <input :value="act.title" :disabled="readonly" aria-label="Act title" maxlength="200" class="min-w-0 flex-1 bg-transparent font-medium outline-none" @change="run('story.updateAct', { id: act.id, title: ($event.target as HTMLInputElement).value })">
            <button v-if="!readonly" class="text-xs text-muted hover:text-red-400" @click="run('story.removeAct', { id: act.id })">Remove</button>
          </div>
          <textarea :value="act.summary ?? ''" :disabled="readonly" aria-label="Act summary" rows="3" maxlength="5000" placeholder="What happens" class="mt-2 w-full resize-y bg-transparent text-sm text-muted outline-none" @change="run('story.updateAct', { id: act.id, summary: ($event.target as HTMLTextAreaElement).value })" />
        </div>
        <button v-if="!readonly" class="rounded-lg border border-muted/30 px-3 py-1.5 text-xs hover:border-accent hover:text-accent" @click="run('story.addAct', { title: `Act ${(story.spec.acts?.length ?? 0) + 1}` })">Add act</button>
        <StoryPanel :story="story.spec" :readonly="readonly" @run="run" />
      </div>

      <StoryPanel v-if="script && story && storyPanel" :story="story.spec" :readonly="readonly" @run="run" />
    </div>
  </div>
</template>

<style>
/* The screenplay page: standard layout in a monospace face (Hindi and Gujarati fall back to
   Lohit or Noto, whichever this system has). */
.sp-page {
  max-width: 44rem;
  margin: 0 auto;
  padding: 2rem 2.5rem 40vh;
  outline: none;
  font-family: "Courier Prime", "Courier New", Courier, "Lohit Devanagari", "Noto Sans Devanagari", "Lohit Gujarati", "Noto Sans Gujarati", monospace;
  font-size: 15px;
  line-height: 1.45;
  white-space: pre-wrap;
  word-wrap: break-word;
}
.sp-page .sp-el { margin: 0; min-height: 1.45em; position: relative; }
.sp-page .sp-heading { margin-top: 2em; font-weight: 700; text-transform: uppercase; }
.sp-page .sp-heading:first-child { margin-top: 0; }
.sp-page .sp-action, .sp-page .sp-narration { margin-top: 1em; }
.sp-page .sp-narration { font-style: italic; }
.sp-page .sp-character { margin: 1em 25% 0 17%; text-align: center; text-transform: uppercase; }
.sp-page .sp-parenthetical { margin: 0 30% 0 27%; }
.sp-page .sp-dialogue { margin: 0 25% 0 17%; }
.sp-page .sp-transition { margin-top: 1em; text-align: right; text-transform: uppercase; }
/* The element with the cursor shows its type while empty. */
.sp-page .sp-current.sp-empty::before {
  content: attr(data-label);
  position: absolute;
  color: #8a90a6;
  opacity: 0.6;
  pointer-events: none;
  text-transform: none;
  font-weight: 400;
  font-style: normal;
}
.sp-page .sp-character.sp-current.sp-empty::before, .sp-page .sp-transition.sp-current.sp-empty::before { left: 0; right: 0; }
</style>
