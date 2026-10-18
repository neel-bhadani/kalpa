// Between the script spec and the editor's document. readScript() runs after every edit, so it
// reuses what didn't change: an unchanged element is the same ProseMirror node, which gives back
// the same line object, and a scene whose lines are all the same comes back as the same scene
// (diffScript skips those). Only the edited elements are read again.

import type { Node } from 'prosemirror-model'
import { parseHeading } from '../screenplay'
import { newId, type Scene, type ScriptLine, type ScriptSpec, type StoryCharacter } from '../spec'
import { schema } from './schema'

export function scriptToDoc(spec: ScriptSpec): Node {
  const nodes: Node[] = []
  for (const scene of spec.scenes) {
    nodes.push(schema.nodes.heading!.create({ id: scene.id, summary: scene.summary ?? '' }, scene.heading ? schema.text(scene.heading) : undefined))
    for (const line of scene.lines) {
      nodes.push(schema.nodes.line!.create({ id: line.id, type: line.type, character_id: line.character_id ?? null, emotion: line.emotion ?? '' }, line.text ? schema.text(line.text) : undefined))
    }
  }
  if (!nodes.length) nodes.push(schema.nodes.heading!.create({ id: newId() }))
  return schema.nodes.doc!.create(null, nodes)
}

// Scene headings, character names and transitions are written in capitals.
const CAPITALS = new Set(['heading', 'character', 'transition'])

// A character line's name without extensions, e.g. "MIRA (V.O.)" → "MIRA".
export function characterName(text: string): string {
  return text.replace(/\s*\(.*\)\s*$/, '').trim().toUpperCase()
}

// The line read from each node, with the speaker before it and the cast and language it was read
// with (a new character or language reads every line again).
const linesByNode = new WeakMap<Node, { line: ScriptLine; speaker: string | null; context: string }>()
const scenesById = new WeakMap<ScriptSpec, Map<string, Scene>>()

export function readScript(doc: Node, prev: ScriptSpec, characters: StoryCharacter[], language = 'en'): ScriptSpec {
  const oldScenes = index(scenesById, prev, () => prev.scenes)
  const castIds = new Map(characters.map(character => [characterName(character.name), character.id]))
  const context = `${language}|${[...castIds].join(',')}`
  const upper = (type: string, text: string) => (CAPITALS.has(type) ? text.toLocaleUpperCase(language) : text)

  const scenes: Scene[] = []
  let heading: Node | null = null
  let lines: ScriptLine[] = []
  let speaker: string | null = null

  const close = () => {
    if (!heading) return
    const id = heading.attrs.id as string
    const text = upper('heading', heading.textContent)
    const summary = heading.attrs.summary as string
    const old = oldScenes.get(id)
    const same = old && old.heading === text && (old.summary ?? '') === summary && old.lines.length === lines.length && old.lines.every((line, i) => line === lines[i])
    scenes.push(same ? old : { id, heading: text, ...parseHeading(text), ...(summary ? { summary } : {}), lines })
  }

  doc.forEach((node) => {
    if (node.type.name === 'heading') {
      close()
      heading = node
      lines = []
      speaker = null
      return
    }
    const type = node.attrs.type as ScriptLine['type']
    const cached = linesByNode.get(node)
    // The same node read with the same speaker before it gives the same line.
    if (cached && cached.context === context && (type !== 'dialogue' && type !== 'parenthetical' || cached.speaker === speaker)) {
      if (type === 'character') speaker = cached.line.character_id ?? null
      lines.push(cached.line)
      return
    }
    const text = upper(type, node.textContent)
    if (type === 'character') speaker = castIds.get(characterName(text)) ?? null
    const characterId = type === 'character' || type === 'dialogue' || type === 'parenthetical' ? speaker : null
    const emotion = node.attrs.emotion as string
    const line: ScriptLine = { id: node.attrs.id, type, text, ...(characterId ? { character_id: characterId } : {}), ...(emotion ? { emotion } : {}) }
    linesByNode.set(node, { line, speaker: type === 'character' ? null : speaker, context })
    lines.push(line)
  })
  close()

  const unchanged = scenes.length === prev.scenes.length && scenes.every((scene, i) => scene === prev.scenes[i])
  return unchanged ? prev : { ...prev, scenes }
}

function index<T extends { id: string }>(cache: WeakMap<ScriptSpec, Map<string, T>>, spec: ScriptSpec, items: () => T[]): Map<string, T> {
  let map = cache.get(spec)
  if (!map) {
    map = new Map(items().map(item => [item.id, item]))
    cache.set(spec, map)
  }
  return map
}
