<script setup lang="ts">
// The screenplay page: one ProseMirror view for the whole script. After each edit it reads the
// script back from the document (reusing what didn't change) and emits it; only the edited
// elements are redrawn. setScript() replaces the document when the script changed elsewhere
// (restore, the assistant, another device).
import { EditorState, TextSelection } from 'prosemirror-state'
import { EditorView } from 'prosemirror-view'
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import type { ElementType } from '../screenplay'
import type { ScriptSpec, StoryCharacter } from '../spec'
import { readScript, scriptToDoc } from './convert'
import { currentElement, screenplayPlugins, setElementType, TYPE_LABELS } from './plugins'
import { elementType } from './schema'

export type Cursor = { sceneId: string; elementId: string; offset: number }

const props = defineProps<{ script: ScriptSpec; characters: StoryCharacter[]; readonly?: boolean; language?: string }>()
const emit = defineEmits<{
  change: [script: ScriptSpec]
  cursor: [cursor: Cursor, element: ElementType]
}>()

const root = ref<HTMLElement | null>(null)
let view: EditorView | null = null
// The script the document matches.
let shown: ScriptSpec = props.script

function state(script: ScriptSpec): EditorState {
  return EditorState.create({ doc: scriptToDoc(script), plugins: screenplayPlugins() })
}

onMounted(() => {
  view = new EditorView(root.value!, {
    state: state(props.script),
    editable: () => !props.readonly,
    // No spellcheck: on a long script it costs 50–100 ms a keystroke on a slow PC.
    attributes: { 'class': 'sp-page', 'spellcheck': 'false', 'data-test': 'screenplay' },
    dispatchTransaction(tr) {
      view!.updateState(view!.state.apply(tr))
      if (tr.docChanged) {
        const next = readScript(view!.state.doc, shown, props.characters, props.language)
        if (next !== shown) {
          shown = next
          emit('change', next)
        }
      }
      if (tr.docChanged || tr.selectionSet) reportCursor()
    },
  })
  reportCursor()
})

onBeforeUnmount(() => view?.destroy())

// A new character in the story, or another language: read the lines again (speakers, capitals).
watch(() => [props.characters, props.language], () => {
  if (!view) return
  const next = readScript(view.state.doc, shown, props.characters, props.language)
  if (next !== shown) {
    shown = next
    emit('change', next)
  }
})

function reportCursor() {
  if (!view) return
  const { node, index } = currentElement(view.state)
  let scene = index
  while (scene > 0 && view.state.doc.child(scene).type.name !== 'heading') scene--
  const offset = view.state.selection.head - view.state.selection.$head.start(1)
  emit('cursor', { sceneId: view.state.doc.child(scene).attrs.id, elementId: node.attrs.id, offset }, elementType(node) as ElementType)
}

// The script changed elsewhere: show it, keeping the cursor on the same element if it's still there.
function setScript(script: ScriptSpec, cursor?: Cursor) {
  shown = script
  if (!view) return
  view.updateState(state(script))
  if (cursor) placeCursor(cursor)
}

function placeCursor(cursor: Cursor, scroll = false) {
  if (!view) return
  let target = -1
  let size = 0
  view.state.doc.forEach((node, offset) => {
    if (target === -1 && node.attrs.id === cursor.elementId) {
      target = offset
      size = node.content.size
    }
  })
  if (target === -1) {
    view.state.doc.forEach((node, offset) => {
      if (target === -1 && node.attrs.id === cursor.sceneId) target = offset
    })
  }
  if (target === -1) return
  const pos = target + 1 + Math.min(cursor.offset, size)
  const tr = view.state.tr.setSelection(TextSelection.create(view.state.doc, pos))
  view.dispatch(scroll ? tr.scrollIntoView() : tr)
}

// Jump to a scene (from the outline): cursor at the end of its heading, heading at the top.
function goToScene(sceneId: string) {
  if (!view) return
  placeCursor({ sceneId, elementId: sceneId, offset: Number.MAX_SAFE_INTEGER })
  const dom = root.value?.querySelector(`[data-id="${CSS.escape(sceneId)}"]`)
  dom?.scrollIntoView({ block: 'start' })
  view.focus()
}

// The toolbar's element picker.
function setType(type: ElementType) {
  if (!view || props.readonly) return
  const { node, pos } = currentElement(view.state)
  view.dispatch(setElementType(view.state.tr, pos, node, type))
  view.focus()
}

function focus() {
  view?.focus()
}

defineExpose({ setScript, placeCursor, goToScene, setType, focus, labels: TYPE_LABELS })
</script>

<template>
  <div ref="root" :lang="language ?? 'en'" />
</template>
