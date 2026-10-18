// The Story & Script commands. The editor and (from P1 step 3) the Story assistant change a story
// or a script only through these. Each one turns its arguments into a JSON Patch on the spec, and
// says what it changed (for the version message, e.g. "Edited 3 lines in Scene 2").
// Scenes, lines, acts and characters are found by their stable id; an "index" places a new or
// moved item (left out: at the end).

import type { JsonSchema } from '../../core/src/modules'
import { join, type Operation } from './patch'
import { parseHeading } from './screenplay'
import { LIMITS, LINE_TYPES, newId, type ScriptSpec, type SpecKind, type StorySpec } from './spec'

export type Change = {
  verb: 'added' | 'edited' | 'moved' | 'removed'
  what: 'line' | 'scene' | 'act' | 'character' | 'field'
  // The id (or field name) changed, so editing one line ten times counts once.
  target: string
  // Scene number (1-based) for lines and scenes.
  scene?: number
}

export type SpecCommand = {
  kind: SpecKind
  description: string
  schema: JsonSchema
  // Commands that add something get an id here when the caller didn't give one.
  creates?: boolean
  patch: (spec: any, args: any) => Operation[]
  change: (spec: any, args: any) => Change
}

const id: JsonSchema = { type: 'string', minLength: 1, maxLength: 64 }
const index: JsonSchema = { type: 'integer', description: 'Position (0 = first). Left out: at the end.' }
const lineType: JsonSchema = { type: 'string', enum: [...LINE_TYPES] }

const STORY_FIELDS = ['title', 'logline', 'genre', 'tone', 'audience', 'language', 'target_length', 'synopsis', 'themes'] as const
const SCRIPT_FIELDS = ['title', 'format', 'language', 'target_duration_s'] as const
const TEXT_LIMITS: Record<string, number> = { title: 200, logline: 500, genre: 100, tone: 100, audience: 100, synopsis: 20000 }

export const storyCommands: Record<string, SpecCommand> = {
  'story.setField': {
    kind: 'story',
    description: 'Set one field of the story: title, logline, genre, tone, audience, language, target_length (minutes), synopsis or themes (a list).',
    schema: object({ field: { type: 'string', enum: [...STORY_FIELDS] }, value: { description: 'Text, a number for target_length, a list of text for themes.' } }, ['field', 'value']),
    patch: (_spec: StorySpec, { field, value }) => [{ op: 'add', path: `/${field}`, value: storyValue(field, value) }],
    change: (_spec, { field }) => ({ verb: 'edited', what: 'field', target: field }),
  },
  'story.addAct': {
    kind: 'story',
    creates: true,
    description: 'Add an act to the story.',
    schema: object({ id, title: { type: 'string', maxLength: 200 }, summary: { type: 'string', maxLength: 5000 }, index }, ['title']),
    patch: (spec: StorySpec, args) => {
      if ((spec.acts?.length ?? 0) >= LIMITS.acts) fail(`A story has at most ${LIMITS.acts} acts.`)
      return [...ensureList(spec, 'acts'), { op: 'add', path: `/acts/${place(args.index, spec.acts?.length ?? 0)}`, value: pick(args, ['id', 'title', 'summary']) }]
    },
    change: (_spec, args) => ({ verb: 'added', what: 'act', target: args.id }),
  },
  'story.updateAct': {
    kind: 'story',
    description: 'Change an act\'s title or summary.',
    schema: object({ id, title: { type: 'string', maxLength: 200 }, summary: { type: 'string', maxLength: 5000 } }, ['id']),
    patch: (spec: StorySpec, args) => fields(`/acts/${find(spec.acts, args.id, 'act')}`, args, ['title', 'summary']),
    change: (_spec, args) => ({ verb: 'edited', what: 'act', target: args.id }),
  },
  'story.moveAct': {
    kind: 'story',
    description: 'Move an act to another position.',
    schema: object({ id, to: index }, ['id', 'to']),
    patch: (spec: StorySpec, args) => move('/acts', find(spec.acts, args.id, 'act'), args.to, spec.acts!.length),
    change: (_spec, args) => ({ verb: 'moved', what: 'act', target: args.id }),
  },
  'story.removeAct': {
    kind: 'story',
    description: 'Remove an act.',
    schema: object({ id }, ['id']),
    patch: (spec: StorySpec, args) => [{ op: 'remove', path: `/acts/${find(spec.acts, args.id, 'act')}` }],
    change: (_spec, args) => ({ verb: 'removed', what: 'act', target: args.id }),
  },
  'story.addCharacter': {
    kind: 'story',
    creates: true,
    description: 'Add a character to the story.',
    schema: object({ id, name: { type: 'string', minLength: 1, maxLength: 100 }, role: { type: 'string', maxLength: 100 }, description: { type: 'string', maxLength: 5000 }, index }, ['name']),
    patch: (spec: StorySpec, args) => {
      if ((spec.characters?.length ?? 0) >= LIMITS.characters) fail(`A story has at most ${LIMITS.characters} characters.`)
      return [...ensureList(spec, 'characters'), { op: 'add', path: `/characters/${place(args.index, spec.characters?.length ?? 0)}`, value: pick(args, ['id', 'name', 'role', 'description']) }]
    },
    change: (_spec, args) => ({ verb: 'added', what: 'character', target: args.id }),
  },
  'story.updateCharacter': {
    kind: 'story',
    description: 'Change a character\'s name, role, description or linked character asset.',
    schema: object({ id, name: { type: 'string', minLength: 1, maxLength: 100 }, role: { type: 'string', maxLength: 100 }, description: { type: 'string', maxLength: 5000 }, character_asset_id: { description: 'A character asset id, or null.' } }, ['id']),
    patch: (spec: StorySpec, args) => fields(`/characters/${find(spec.characters, args.id, 'character')}`, args, ['name', 'role', 'description', 'character_asset_id']),
    change: (_spec, args) => ({ verb: 'edited', what: 'character', target: args.id }),
  },
  'story.removeCharacter': {
    kind: 'story',
    description: 'Remove a character from the story.',
    schema: object({ id }, ['id']),
    patch: (spec: StorySpec, args) => [{ op: 'remove', path: `/characters/${find(spec.characters, args.id, 'character')}` }],
    change: (_spec, args) => ({ verb: 'removed', what: 'character', target: args.id }),
  },
}

export const scriptCommands: Record<string, SpecCommand> = {
  'script.setField': {
    kind: 'script',
    description: 'Set one field of the script: title, format (screenplay, short, ad, story), language or target_duration_s.',
    schema: object({ field: { type: 'string', enum: [...SCRIPT_FIELDS] }, value: { description: 'Text, or a number of seconds for target_duration_s.' } }, ['field', 'value']),
    patch: (_spec: ScriptSpec, { field, value }) => [{ op: 'add', path: `/${field}`, value: scriptValue(field, value) }],
    change: (_spec, { field }) => ({ verb: 'edited', what: 'field', target: field }),
  },
  'script.addScene': {
    kind: 'script',
    creates: true,
    description: 'Add a scene, e.g. heading "INT. NIGHT MARKET - NIGHT", optionally with its first lines.',
    schema: object({
      id,
      heading: { type: 'string', maxLength: LIMITS.heading },
      summary: { type: 'string', maxLength: 2000 },
      index,
      lines: { type: 'array', items: object({ id, type: lineType, text: { type: 'string', maxLength: LIMITS.lineText }, character_id: { description: 'A story character id, or null.' }, emotion: { type: 'string', maxLength: 50 } }, ['type']) },
    }, []),
    patch: (spec: ScriptSpec, args) => {
      if (spec.scenes.length >= LIMITS.scenes) fail(`A script has at most ${LIMITS.scenes} scenes.`)
      const heading = String(args.heading ?? '')
      const lines = (args.lines ?? []).map((line: any) => ({ id: line.id ?? newId(), type: line.type, text: String(line.text ?? ''), ...pick(line, ['character_id', 'emotion']) }))
      if (lines.length > LIMITS.linesPerScene) fail(`A scene has at most ${LIMITS.linesPerScene} lines.`)
      const value = { id: args.id, heading, ...parseHeading(heading), ...(args.summary ? { summary: args.summary } : {}), lines }
      return [{ op: 'add', path: `/scenes/${place(args.index, spec.scenes.length)}`, value }]
    },
    change: (spec: ScriptSpec, args) => ({ verb: 'added', what: 'scene', target: args.id, scene: place(args.index, spec.scenes.length) + 1 }),
  },
  'script.updateScene': {
    kind: 'script',
    description: 'Change a scene\'s heading or summary.',
    schema: object({ id, heading: { type: 'string', maxLength: LIMITS.heading }, summary: { type: 'string', maxLength: 2000 } }, ['id']),
    patch: (spec: ScriptSpec, args) => {
      const path = `/scenes/${find(spec.scenes, args.id, 'scene')}`
      const ops = fields(path, args, ['heading', 'summary'])
      if (args.heading === undefined) return ops
      // Location and time of day follow the heading.
      const { location, time_of_day } = parseHeading(String(args.heading))
      return [...ops, { op: 'add', path: `${path}/location`, value: location }, { op: 'add', path: `${path}/time_of_day`, value: time_of_day }]
    },
    change: (spec: ScriptSpec, args) => ({ verb: 'edited', what: 'scene', target: args.id, scene: find(spec.scenes, args.id, 'scene') + 1 }),
  },
  'script.moveScene': {
    kind: 'script',
    description: 'Move a scene to another position.',
    schema: object({ id, to: index }, ['id', 'to']),
    patch: (spec: ScriptSpec, args) => move('/scenes', find(spec.scenes, args.id, 'scene'), args.to, spec.scenes.length),
    change: (spec: ScriptSpec, args) => ({ verb: 'moved', what: 'scene', target: args.id, scene: find(spec.scenes, args.id, 'scene') + 1 }),
  },
  'script.removeScene': {
    kind: 'script',
    description: 'Remove a scene and its lines.',
    schema: object({ id }, ['id']),
    patch: (spec: ScriptSpec, args) => [{ op: 'remove', path: `/scenes/${find(spec.scenes, args.id, 'scene')}` }],
    change: (spec: ScriptSpec, args) => ({ verb: 'removed', what: 'scene', target: args.id, scene: find(spec.scenes, args.id, 'scene') + 1 }),
  },
  'script.addLine': {
    kind: 'script',
    creates: true,
    description: 'Add a line to a scene: action, character (a name), dialogue, parenthetical, transition or narration.',
    schema: object({ id, scene_id: id, index, type: lineType, text: { type: 'string', maxLength: LIMITS.lineText }, character_id: { description: 'A story character id, or null.' }, emotion: { type: 'string', maxLength: 50 } }, ['scene_id', 'type']),
    patch: (spec: ScriptSpec, args) => {
      const s = find(spec.scenes, args.scene_id, 'scene')
      const lines = spec.scenes[s]!.lines
      if (lines.length >= LIMITS.linesPerScene) fail(`A scene has at most ${LIMITS.linesPerScene} lines.`)
      const value = { id: args.id, type: args.type, text: String(args.text ?? ''), ...pick(args, ['character_id', 'emotion']) }
      return [{ op: 'add', path: `/scenes/${s}/lines/${place(args.index, lines.length)}`, value }]
    },
    change: (spec: ScriptSpec, args) => ({ verb: 'added', what: 'line', target: args.id, scene: find(spec.scenes, args.scene_id, 'scene') + 1 }),
  },
  'script.updateLine': {
    kind: 'script',
    description: 'Change a line\'s text, type, character or emotion.',
    schema: object({ id, text: { type: 'string', maxLength: LIMITS.lineText }, type: lineType, character_id: { description: 'A story character id, or null.' }, emotion: { type: 'string', maxLength: 50 } }, ['id']),
    patch: (spec: ScriptSpec, args) => {
      const [s, l] = findLine(spec, args.id)
      return fields(`/scenes/${s}/lines/${l}`, args, ['text', 'type', 'character_id', 'emotion'])
    },
    change: (spec: ScriptSpec, args) => ({ verb: 'edited', what: 'line', target: args.id, scene: findLine(spec, args.id)[0] + 1 }),
  },
  'script.moveLine': {
    kind: 'script',
    description: 'Move a line to a position in a scene (the same scene or another).',
    schema: object({ id, scene_id: id, index }, ['id', 'scene_id', 'index']),
    patch: (spec: ScriptSpec, args) => {
      const [s, l] = findLine(spec, args.id)
      const target = find(spec.scenes, args.scene_id, 'scene')
      if (target === s) return move(`/scenes/${s}/lines`, l, args.index, spec.scenes[s]!.lines.length)
      if (spec.scenes[target]!.lines.length >= LIMITS.linesPerScene) fail(`A scene has at most ${LIMITS.linesPerScene} lines.`)
      return [{ op: 'move', from: `/scenes/${s}/lines/${l}`, path: `/scenes/${target}/lines/${place(args.index, spec.scenes[target]!.lines.length)}` }]
    },
    change: (spec: ScriptSpec, args) => ({ verb: 'moved', what: 'line', target: args.id, scene: find(spec.scenes, args.scene_id, 'scene') + 1 }),
  },
  'script.removeLine': {
    kind: 'script',
    description: 'Remove a line.',
    schema: object({ id }, ['id']),
    patch: (spec: ScriptSpec, args) => {
      const [s, l] = findLine(spec, args.id)
      return [{ op: 'remove', path: `/scenes/${s}/lines/${l}` }]
    },
    change: (spec: ScriptSpec, args) => ({ verb: 'removed', what: 'line', target: args.id, scene: findLine(spec, args.id)[0] + 1 }),
  },
}

export const specCommands: Record<string, SpecCommand> = { ...storyCommands, ...scriptCommands }

function object(properties: Record<string, JsonSchema>, required: string[]): JsonSchema {
  return { type: 'object', properties, required, additionalProperties: false }
}

function fail(message: string): never {
  throw new Error(message)
}

function find(list: { id: string }[] | undefined, itemId: string, name: string): number {
  const i = list?.findIndex(item => item.id === itemId) ?? -1
  if (i === -1) fail(`There is no ${name} with id "${itemId}".`)
  return i
}

export function findLine(spec: ScriptSpec, lineId: string): [number, number] {
  for (const [s, scene] of spec.scenes.entries()) {
    const l = scene.lines.findIndex(line => line.id === lineId)
    if (l !== -1) return [s, l]
  }
  return fail(`There is no line with id "${lineId}".`)
}

// Where a new item goes: index, clamped; or the end.
function place(at: number | undefined, length: number): number {
  return at === undefined ? length : Math.max(0, Math.min(at, length))
}

function move(listPath: string, from: number, to: number, length: number): Operation[] {
  const target = Math.max(0, Math.min(to, length - 1))
  return target === from ? [] : [{ op: 'move', from: `${listPath}/${from}`, path: `${listPath}/${target}` }]
}

// One "add" per given field (add also replaces).
function fields(path: string, args: Record<string, unknown>, names: string[]): Operation[] {
  return names.filter(name => args[name] !== undefined).map(name => ({ op: 'add', path: join([...path.slice(1).split('/'), name]), value: args[name] }))
}

function pick(args: Record<string, unknown>, names: string[]): Record<string, unknown> {
  return Object.fromEntries(names.filter(name => args[name] !== undefined).map(name => [name, args[name]]))
}

function ensureList(spec: StorySpec, key: 'acts' | 'characters'): Operation[] {
  return spec[key] ? [] : [{ op: 'add', path: `/${key}`, value: [] }]
}

function storyValue(field: string, value: unknown): unknown {
  if (field === 'target_length') {
    const minutes = Number(value)
    if (!Number.isFinite(minutes) || minutes < 0 || minutes > 600) fail('target_length must be a number of minutes from 0 to 600.')
    return minutes
  }
  if (field === 'themes') {
    if (!Array.isArray(value) || value.some(theme => typeof theme !== 'string' || !theme.trim() || theme.length > 100)) fail('themes must be a list of short texts.')
    if (value.length > LIMITS.themes) fail(`A story has at most ${LIMITS.themes} themes.`)
    return value.map(theme => theme.trim())
  }
  return text(field, value)
}

function scriptValue(field: string, value: unknown): unknown {
  if (field === 'target_duration_s') {
    const seconds = Number(value)
    if (!Number.isInteger(seconds) || seconds < 0 || seconds > 36000) fail('target_duration_s must be whole seconds from 0 to 36000.')
    return seconds
  }
  if (field === 'format' && !['screenplay', 'short', 'ad', 'story'].includes(String(value))) fail('format must be screenplay, short, ad or story.')
  return text(field, value)
}

function text(field: string, value: unknown): string {
  if (typeof value !== 'string') fail(`${field} must be text.`)
  if (field === 'title' && !value.trim()) fail('The title can\'t be empty.')
  if (field === 'language' && !/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/.test(value)) fail('language must be a language code like en, hi or gu.')
  const limit = TEXT_LIMITS[field] ?? 200
  if (value.length > limit) fail(`${field} must have at most ${limit} characters.`)
  return value
}
