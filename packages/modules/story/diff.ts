// The commands that turn one script into another. The editor works on a document; after each
// edit it reads the scenes back from it and runs the commands from here, so typing changes the
// script exactly the way the assistant's commands do. Scenes and lines are matched by id, and
// unchanged ones (the same objects) are skipped, so a keystroke costs one updateLine.

import { scriptCommands } from './commands'
import { applyPatch } from './patch'
import type { Scene, ScriptLine, ScriptSpec } from './spec'

export type CommandCall = { name: string; args: Record<string, unknown> }

export function diffScript(prev: ScriptSpec, next: ScriptSpec): CommandCall[] {
  const calls: CommandCall[] = []
  let work = prev
  const run = (name: string, args: Record<string, unknown>) => {
    calls.push({ name, args })
    work = applyPatch(work, scriptCommands[name]!.patch(work, args)).doc
  }

  if (prev.scenes === next.scenes) return calls

  const prevScenes = new Map(prev.scenes.map(scene => [scene.id, scene]))
  const nextScenes = new Map(next.scenes.map(scene => [scene.id, scene]))
  // Where each line of a changed scene is now. A line can only leave a scene for another
  // changed (or new) scene, so unchanged scenes need no look.
  const nextLineScene = new Map<string, string>()
  for (const scene of next.scenes) {
    if (prevScenes.get(scene.id) !== scene) for (const line of scene.lines) nextLineScene.set(line.id, scene.id)
  }

  // 1. Lines that are gone, or now in another scene (they come back with that scene below).
  for (const scene of prev.scenes) {
    if (nextScenes.get(scene.id) === scene) continue
    for (const line of scene.lines) {
      if (nextLineScene.get(line.id) !== scene.id) run('script.removeLine', { id: line.id })
    }
  }
  // 2. Scenes that are gone.
  for (const scene of prev.scenes) {
    if (!nextScenes.has(scene.id)) run('script.removeScene', { id: scene.id })
  }

  // 3. New scenes, in place, with their lines.
  const known = new Set(work.scenes.map(scene => scene.id))
  for (const [index, scene] of next.scenes.entries()) {
    if (known.has(scene.id)) continue
    run('script.addScene', { id: scene.id, index, heading: scene.heading, ...(scene.summary ? { summary: scene.summary } : {}), lines: scene.lines.map(lineArgs) })
  }

  // 4. Scene order.
  for (const [index, scene] of next.scenes.entries()) {
    if (work.scenes[index]?.id !== scene.id) run('script.moveScene', { id: scene.id, to: index })
  }

  // 5. Each changed scene: heading and summary, then its lines.
  for (const scene of next.scenes) {
    if (prevScenes.get(scene.id) === scene) continue
    const old = work.scenes.find(item => item.id === scene.id)!
    if (old === scene) continue
    if (old.heading !== scene.heading || (old.summary ?? '') !== (scene.summary ?? '')) {
      run('script.updateScene', { id: scene.id, ...(old.heading !== scene.heading ? { heading: scene.heading } : {}), ...((old.summary ?? '') !== (scene.summary ?? '') ? { summary: scene.summary ?? '' } : {}) })
    }
    diffLines(scene, work.scenes.find(item => item.id === scene.id)!, run)
  }

  return calls
}

function diffLines(next: Scene, current: Scene, run: (name: string, args: Record<string, unknown>) => void): void {
  const present = new Set(current.lines.map(line => line.id))
  const oldById = new Map(current.lines.map(line => [line.id, line]))

  for (const [index, line] of next.lines.entries()) {
    if (!present.has(line.id)) run('script.addLine', { scene_id: next.id, index, ...lineArgs(line) })
  }
  // Order: put each line where it belongs, front to back (a line still out of place is moved).
  let order = [...current.lines.map(line => line.id)]
  for (const line of next.lines) if (!present.has(line.id)) order.splice(next.lines.indexOf(line), 0, line.id)
  for (const [index, line] of next.lines.entries()) {
    if (order[index] !== line.id) {
      run('script.moveLine', { id: line.id, scene_id: next.id, index })
      order = order.filter(lineId => lineId !== line.id)
      order.splice(index, 0, line.id)
    }
  }

  for (const line of next.lines) {
    const old = oldById.get(line.id)
    if (!old || old === line) continue
    const changed: Record<string, unknown> = {}
    if (old.text !== line.text) changed.text = line.text
    if (old.type !== line.type) changed.type = line.type
    if ((old.character_id ?? null) !== (line.character_id ?? null)) changed.character_id = line.character_id ?? null
    if ((old.emotion ?? '') !== (line.emotion ?? '')) changed.emotion = line.emotion ?? ''
    if (Object.keys(changed).length) run('script.updateLine', { id: line.id, ...changed })
  }
}

function lineArgs(line: ScriptLine): Record<string, unknown> {
  return { id: line.id, type: line.type, text: line.text, ...(line.character_id ? { character_id: line.character_id } : {}), ...(line.emotion ? { emotion: line.emotion } : {}) }
}
