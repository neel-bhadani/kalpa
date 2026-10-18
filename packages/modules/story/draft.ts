// A draft is the editor's working copy of one asset, kept in the task checkpoint (so it survives a
// reload, works offline and resumes on another device). Commands change draft.spec at once and
// queue their patch; a commit turns the queue into one asset version with a short message.

import { specCommands, type Change } from './commands'
import { applyPatch, compact, type Operation } from './patch'
import { newId, type ScriptSpec, type SpecKind, type StorySpec } from './spec'

export type Draft<S = StorySpec | ScriptSpec> = {
  assetId: string
  kind: SpecKind
  // The asset version the queued ops apply to.
  baseVersion: number
  spec: S
  // Since baseVersion, in order.
  ops: Operation[]
  changes: Change[]
  // Goes up with every change, so the editor can tell its own changes from others'.
  rev: number
  // The editor that saved it to the checkpoint last (a random id per open editor).
  writer?: string
}

export function newDraft<S>(assetId: string, kind: SpecKind, version: { number: number; spec: S }): Draft<S> {
  return { assetId, kind, baseVersion: version.number, spec: version.spec, ops: [], changes: [], rev: 0 }
}

export function isDirty(draft: Draft): boolean {
  return draft.ops.length > 0
}

// Run a command on the draft. Returns the new draft (the old one is left as it was) and the
// patch that undoes the change. Throws a plain message if the command can't apply.
export function runCommand<D extends Draft>(draft: D, name: string, args: Record<string, unknown>): { draft: D; inverse: Operation[]; args: Record<string, unknown> } {
  const command = specCommands[name]
  if (!command) throw new Error(`There is no "${name}" command.`)
  if (command.kind !== draft.kind) throw new Error(`"${name}" changes a ${command.kind}, not a ${draft.kind}.`)

  const full = command.creates && !args.id ? { ...args, id: newId() } : args
  const ops = command.patch(draft.spec, full)
  const change = command.change(draft.spec, full)
  if (!ops.length) return { draft, inverse: [], args: full }

  const { doc, inverse } = applyPatch(draft.spec, ops)
  return { draft: { ...draft, spec: doc, ops: [...draft.ops, ...ops], changes: [...draft.changes, change], rev: draft.rev + 1 }, inverse, args: full }
}

// Put back a change made by runCommand (its inverse), as a change of its own.
export function undo<D extends Draft>(draft: D, inverse: Operation[]): D {
  if (!inverse.length) return draft
  const { doc } = applyPatch(draft.spec, inverse)
  return { ...draft, spec: doc, ops: [...draft.ops, ...inverse], rev: draft.rev + 1 }
}

// A long queue is sent as the whole spec instead.
const MAX_PATCH_OPS = 300

// What a commit sends: the queued patch (or the whole spec) and the message.
export function commitBody(draft: Draft): { base_version: number; patch?: Operation[]; spec?: unknown; message: string } {
  const ops = compact(draft.ops)
  const message = summarize(draft.changes)
  return ops.length > MAX_PATCH_OPS
    ? { base_version: draft.baseVersion, spec: draft.spec, message }
    : { base_version: draft.baseVersion, patch: ops, message }
}

// After a commit of the first `ops` ops and `changes` changes (more may have come meanwhile).
export function committed<D extends Draft>(draft: D, version: number, sent: { ops: number; changes: number }): D {
  return { ...draft, baseVersion: version, ops: draft.ops.slice(sent.ops), changes: draft.changes.slice(sent.changes) }
}

const NOUNS: Record<Change['what'], [string, string]> = {
  line: ['line', 'lines'],
  scene: ['scene', 'scenes'],
  act: ['act', 'acts'],
  character: ['character', 'characters'],
  field: ['field', 'fields'],
}
const FIELD_NAMES: Record<string, string> = { target_length: 'target length', target_duration_s: 'target duration' }

// The net effect on each item: something added in this commit is just "added" (its later edits
// and moves are part of that), and something added and removed again is left out.
function net(changes: Change[]): Change[] {
  const added = new Set<string>()
  const removed = new Set<string>()
  for (const change of changes) {
    if (change.verb === 'added') added.add(change.target)
    if (change.verb === 'removed' && added.has(change.target)) removed.add(change.target)
  }
  return changes.filter(change => !removed.has(change.target) && (change.verb === 'added' || !added.has(change.target)))
}

// "Edited 3 lines in Scene 2; Added Scene 4; Edited the logline".
export function summarize(changes: Change[]): string {
  const groups = new Map<string, { change: Change; targets: Set<string> }>()
  for (const change of net(changes)) {
    // A scene added and edited in one go reads as "Added Scene 4".
    const key = change.what === 'field' ? `field:${change.target}` : `${change.verb}:${change.what}:${change.what === 'line' ? change.scene ?? '' : ''}`
    const group = groups.get(key) ?? { change, targets: new Set<string>() }
    group.targets.add(change.target)
    groups.set(key, group)
  }

  const parts = [...groups.values()].map(({ change, targets }) => {
    const verb = change.verb[0]!.toUpperCase() + change.verb.slice(1)
    if (change.what === 'field') return `Edited the ${FIELD_NAMES[change.target] ?? change.target}`
    if (change.what === 'scene' && targets.size === 1 && change.scene) return `${verb} Scene ${change.scene}`
    const [one, many] = NOUNS[change.what]
    const noun = `${targets.size} ${targets.size === 1 ? one : many}`
    return change.what === 'line' && change.scene ? `${verb} ${noun} in Scene ${change.scene}` : `${verb} ${noun}`
  })

  if (!parts.length) return 'Saved'
  const shown = parts.slice(0, 3).join('; ')
  return parts.length > 3 ? `${shown}; and ${parts.length - 3} more changes` : shown
}
