// How the screenplay page behaves while typing:
//   Enter        splits and starts the next element (after Character comes Dialogue, …); Enter on
//                an empty character/dialogue/parenthetical/transition line turns it into Action.
//   Tab          on an empty element cycles its type (Shift+Tab backwards).
//   "INT." / "EXT." typed at the start of a line makes it a scene heading.
// Also: every element keeps a unique id (copies get new ones), and the element with the cursor is
// marked, with its type shown while empty.

import { baseKeymap } from 'prosemirror-commands'
import { history, redo, undo } from 'prosemirror-history'
import { keymap } from 'prosemirror-keymap'
import type { Node } from 'prosemirror-model'
import { Plugin, TextSelection, type Command, type EditorState, type Transaction } from 'prosemirror-state'
import { Decoration, DecorationSet } from 'prosemirror-view'
import { cycleType, isSceneHeading, nextType, type ElementType } from '../screenplay'
import { LIMITS, newId } from '../spec'
import { elementType, schema } from './schema'

export const TYPE_LABELS: Record<ElementType, string> = {
  heading: 'Scene heading',
  action: 'Action',
  character: 'Character',
  dialogue: 'Dialogue',
  parenthetical: 'Parenthetical',
  transition: 'Transition',
  narration: 'Narration',
}

// The top-level element with the cursor, and where it starts.
export function currentElement(state: EditorState): { node: Node; pos: number; index: number } {
  const { $head } = state.selection
  const index = $head.index(0)
  return { node: state.doc.child(Math.min(index, state.doc.childCount - 1)), pos: $head.before(1), index }
}

// Change the element at pos to another type (a heading is a new scene, so it gets a new id).
export function setElementType(tr: Transaction, pos: number, node: Node, type: ElementType): Transaction {
  const toHeading = type === 'heading'
  if (toHeading === (node.type.name === 'heading')) {
    return toHeading ? tr : tr.setNodeMarkup(pos, null, { ...node.attrs, type })
  }
  return tr.setNodeMarkup(pos, toHeading ? schema.nodes.heading : schema.nodes.line, toHeading ? { id: newId() } : { id: newId(), type })
}

const ESCAPES_TO_ACTION = new Set(['character', 'dialogue', 'parenthetical', 'transition'])

const enter: Command = (state, dispatch) => {
  let tr = state.tr
  if (!state.selection.empty) tr = tr.deleteSelection()
  const $from = tr.selection.$from
  const node = $from.parent
  const type = elementType(node) as ElementType

  if (dispatch) {
    if (!node.content.size && ESCAPES_TO_ACTION.has(type)) {
      dispatch(setElementType(tr, $from.before(1), node, 'action').scrollIntoView())
      return true
    }
    if ($from.parentOffset === 0 && node.content.size) {
      // At the start: an empty element of the same kind goes before this one.
      const before = $from.before(1)
      const empty = type === 'heading' ? schema.nodes.heading!.create({ id: newId() }) : schema.nodes.line!.create({ id: newId(), type })
      dispatch(tr.insert(before, empty).scrollIntoView())
      return true
    }
    // At the end: the next element type. In the middle: the rest keeps this one's kind.
    const atEnd = $from.parentOffset === node.content.size
    const next = atEnd ? nextType(type) : type === 'heading' ? 'action' : type
    const typeAfter = next === 'heading'
      ? { type: schema.nodes.heading!, attrs: { id: newId() } }
      : { type: schema.nodes.line!, attrs: { id: newId(), type: next } }
    dispatch(tr.split($from.pos, 1, [typeAfter]).scrollIntoView())
  }
  return true
}

function tab(backwards: boolean): Command {
  return (state, dispatch) => {
    const { node, pos } = currentElement(state)
    if (node.content.size) return true // only an empty element changes type; keep the focus here
    if (dispatch) {
      const tr = setElementType(state.tr, pos, node, cycleType(elementType(node) as ElementType, backwards))
      dispatch(tr.setSelection(TextSelection.create(tr.doc, pos + 1)))
    }
    return true
  }
}

// After typing "INT." or "EXT." at the start of a line, it becomes a scene heading.
const headingRule = new Plugin({
  appendTransaction(transactions, _old, state) {
    if (!transactions.some(tr => tr.docChanged)) return null
    const { node, pos } = currentElement(state)
    if (node.type.name !== 'line' || ['dialogue', 'parenthetical', 'character'].includes(node.attrs.type)) return null
    if (!isSceneHeading(node.textContent)) return null
    return setElementType(state.tr, pos, node, 'heading')
  },
})

// Every element has an id, and no two the same (pasted or split copies get new ones).
const uniqueIds = new Plugin({
  appendTransaction(transactions, _old, state) {
    if (!transactions.some(tr => tr.docChanged)) return null
    const seen = new Set<string>()
    let tr: Transaction | null = null
    state.doc.forEach((node, offset) => {
      const id = node.attrs.id as string
      if (id && !seen.has(id)) {
        seen.add(id)
        return
      }
      tr ??= state.tr
      tr.setNodeMarkup(offset, null, { ...node.attrs, id: newId() })
    })
    return tr
  },
})

// No element grows past the schema's limits (200 characters for a heading, 5000 for a line, 500
// scenes): typing or pasting more is refused, as the server would refuse the version.
const limits = new Plugin({
  filterTransaction(tr, state) {
    if (!tr.docChanged) return true
    const start = tr.doc.content.findDiffStart(state.doc.content)
    if (start === null) return true
    const end = tr.doc.content.findDiffEnd(state.doc.content)?.a ?? tr.doc.content.size
    let ok = true
    tr.doc.nodesBetween(start, Math.max(start, end), (node) => {
      if (node.isTextblock) ok &&= node.textContent.length <= (node.type.name === 'heading' ? LIMITS.heading : LIMITS.lineText)
      return false
    })
    if (ok && tr.doc.childCount > state.doc.childCount) {
      let headings = 0
      tr.doc.forEach((node) => { if (node.type.name === 'heading') headings++ })
      ok = headings <= LIMITS.scenes
    }
    return ok
  },
})

// Marks the element with the cursor; an empty one shows its type ("Dialogue") as a hint.
const current = new Plugin({
  props: {
    decorations(state) {
      const { node, pos } = currentElement(state)
      const label = TYPE_LABELS[elementType(node) as ElementType]
      return DecorationSet.create(state.doc, [Decoration.node(pos, pos + node.nodeSize, { 'class': `sp-current${node.content.size ? '' : ' sp-empty'}`, 'data-label': label })])
    },
  },
})

export function screenplayPlugins(): Plugin[] {
  return [
    history(),
    keymap({ 'Enter': enter, 'Tab': tab(false), 'Shift-Tab': tab(true), 'Mod-z': undo, 'Mod-y': redo, 'Shift-Mod-z': redo }),
    keymap(baseKeymap),
    limits,
    headingRule,
    uniqueIds,
    current,
  ]
}
