// The tools the Story assistant's model sees. They are small on purpose (a 1.7B model on a CPU
// fills them in reliably): scenes are referred to by number, never by id, and each write tool
// maps to the module's own commands (the same ones the editor uses). Every write tool needs a
// one-line "reason", shown on the step. Read tools return compact text, never the whole script.

import type { JsonSchema } from '../../../core/src/modules'
import type { ToolSpec } from '../../../ai-runtime/src/brain'
import type { CommandCall } from '../diff'
import { characterName } from '../editor/convert'
import { newId, type ScriptSpec, type StorySpec } from '../spec'
import { sceneText } from './text'

const text = (maxLength: number, description?: string): JsonSchema => ({ type: 'string', minLength: 1, maxLength, ...(description ? { description } : {}) })
// Asked for on every write tool; a small model sometimes leaves it out, so it isn't required
// (the step then gets a plain reason written by code).
const reason = text(200, 'Always give: one short line for the user, why this step.')
const scene: JsonSchema = { type: 'integer', description: 'Scene number (1 = first).' }

function tool(name: string, description: string, properties: Record<string, JsonSchema>, required: string[], withReason = true): ToolSpec {
  return {
    name,
    description,
    parameters: { type: 'object', properties: withReason ? { ...properties, reason } : properties, required, additionalProperties: false },
  }
}

export const TOOLS: Record<string, ToolSpec> = {
  'story.setBrief': tool('story.setBrief', 'Set the story\'s title, genre, tone and audience.', { title: text(200), genre: text(100), tone: text(100), audience: text(100) }, ['title', 'genre', 'tone', 'audience']),
  'story.setLogline': tool('story.setLogline', 'Set the logline (one sentence) and the synopsis (3 to 5 sentences).', { logline: text(500), synopsis: text(20000) }, ['logline', 'synopsis']),
  'story.addCharacter': tool('story.addCharacter', 'Add a character.', { name: text(100), role: text(100), description: text(5000) }, ['name', 'role', 'description']),
  'story.updateCharacter': tool('story.updateCharacter', 'Change a character, found by name.', { name: text(100), role: text(100), description: text(5000) }, ['name']),
  'story.addAct': tool('story.addAct', 'Add the next act.', { title: text(200), summary: text(5000) }, ['title', 'summary']),
  'story.setField': tool('story.setField', 'Set one story field.', { field: { type: 'string', enum: ['title', 'logline', 'genre', 'tone', 'audience', 'synopsis'] }, value: text(20000) }, ['field', 'value']),
  'script.addScene': tool('script.addScene', 'Add a scene with a heading (INT./EXT. PLACE - TIME) and a one-line summary.', { heading: text(200), summary: text(2000), after: { type: 'integer', description: 'Scene number it comes after (0 = first). Left out: at the end.' } }, ['heading', 'summary']),
  'script.removeScene': tool('script.removeScene', 'Remove a scene.', { scene }, ['scene']),
  'script.rewriteScene': tool('script.rewriteScene', 'Rewrite a whole scene following an instruction (funnier, shorter, more tense...).', { scene, instruction: text(500) }, ['scene', 'instruction']),
  'ask_user': tool('ask_user', 'Ask the user one short question and wait for the answer.', { question: text(300) }, ['question'], false),
  // Read tools.
  'story.get': tool('story.get', 'Read the story: title, logline, characters, acts.', {}, [], false),
  'script.listScenes': tool('script.listScenes', 'List the scenes: number, heading, one-line summary.', {}, [], false),
  'script.getScene': tool('script.getScene', 'Read one scene\'s lines.', { scene }, ['scene'], false),
  'memory.get': tool('memory.get', 'Read the project\'s story bible (scene summaries so far).', {}, [], false),
}

export const READ_TOOLS = new Set(['story.get', 'script.listScenes', 'script.getScene', 'memory.get'])

export const CHAT_TOOLS = ['script.rewriteScene', 'script.addScene', 'script.removeScene', 'story.setField', 'story.addCharacter', 'story.updateCharacter', 'ask_user', 'script.getScene', 'story.get', 'script.listScenes', 'memory.get']

// A write tool's call, as the module commands it makes and a step title.
export type Mapped = { kind: 'story' | 'script'; title: string; calls: CommandCall[] }

export function mapCall(name: string, args: Record<string, any>, story: StorySpec, script: ScriptSpec | null): Mapped {
  const sceneAt = (number: number) => {
    const found = script?.scenes[number - 1]
    if (!found) throw new Error(`There is no scene ${number}.`)
    return found
  }
  switch (name) {
    case 'story.setBrief':
      return { kind: 'story', title: 'Set up the brief', calls: (['title', 'genre', 'tone', 'audience'] as const).map(field => ({ name: 'story.setField', args: { field, value: String(args[field]).trim() } })) }
    case 'story.setLogline':
      return { kind: 'story', title: 'Wrote the logline and synopsis', calls: (['logline', 'synopsis'] as const).map(field => ({ name: 'story.setField', args: { field, value: String(args[field]).trim() } })) }
    case 'story.setField':
      return { kind: 'story', title: `Set the ${args.field}`, calls: [{ name: 'story.setField', args: { field: args.field, value: String(args.value).trim() } }] }
    case 'story.addCharacter': {
      const name = characterName(String(args.name))
      if (story.characters?.some(character => characterName(character.name) === name)) throw new Error(`${name} is already a character.`)
      return { kind: 'story', title: `Added ${name}`, calls: [{ name: 'story.addCharacter', args: { id: newId(), name, role: args.role, description: args.description } }] }
    }
    case 'story.updateCharacter': {
      const found = story.characters?.find(character => characterName(character.name) === characterName(String(args.name)))
      if (!found) throw new Error(`There is no character called ${args.name}.`)
      return { kind: 'story', title: `Changed ${found.name}`, calls: [{ name: 'story.updateCharacter', args: { id: found.id, ...(args.role ? { role: args.role } : {}), ...(args.description ? { description: args.description } : {}) } }] }
    }
    case 'story.addAct':
      return { kind: 'story', title: `Added act "${args.title}"`, calls: [{ name: 'story.addAct', args: { id: newId(), title: args.title, summary: args.summary } }] }
    case 'script.addScene': {
      if (!script) throw new Error('This task has no script.')
      const index = args.after === undefined ? undefined : Math.max(0, Math.min(Number(args.after), script.scenes.length))
      const heading = String(args.heading).trim().toUpperCase()
      if (!/^(INT|EXT|I\/E|INT\.\/EXT)[./ ]/.test(heading)) throw new Error('A scene heading starts with INT. or EXT.')
      // A new script starts with one blank scene: the first planned scene fills it.
      const blank = script.scenes.find(item => !item.heading.trim() && !item.lines.length)
      if (blank && index === undefined) {
        return { kind: 'script', title: `Added scene: ${heading}`, calls: [{ name: 'script.updateScene', args: { id: blank.id, heading, summary: args.summary } }] }
      }
      return { kind: 'script', title: `Added scene: ${heading}`, calls: [{ name: 'script.addScene', args: { id: newId(), heading, summary: args.summary, ...(index !== undefined ? { index } : {}) } }] }
    }
    case 'script.removeScene': {
      const found = sceneAt(Number(args.scene))
      return { kind: 'script', title: `Removed Scene ${args.scene}`, calls: [{ name: 'script.removeScene', args: { id: found.id } }] }
    }
    default:
      throw new Error(`"${name}" is not a write tool.`)
  }
}

// ---- Read tools: compact text.

export function storyGet(story: StorySpec): string {
  const parts = [`Title: ${story.title}`]
  if (story.logline) parts.push(`Logline: ${story.logline}`)
  if (story.genre || story.tone) parts.push(`Genre: ${story.genre ?? '-'}; tone: ${story.tone ?? '-'}; audience: ${story.audience ?? '-'}`)
  if (story.synopsis) parts.push(`Synopsis: ${story.synopsis}`)
  if (story.characters?.length) parts.push(`Characters:\n${story.characters.map(character => `- ${character.name} (${character.role ?? 'role?'}): ${character.description ?? ''}`).join('\n')}`)
  if (story.acts?.length) parts.push(`Acts:\n${story.acts.map((act, i) => `${i + 1}. ${act.title}: ${act.summary ?? ''}`).join('\n')}`)
  return parts.join('\n')
}

export function listScenes(script: ScriptSpec | null): string {
  if (!script?.scenes.length) return 'No scenes yet.'
  return script.scenes.map((scene, i) => `${i + 1}. ${scene.heading || '(no heading)'} — ${scene.summary ?? (scene.lines.length ? 'drafted' : 'not drafted')}`).join('\n')
}

export function getScene(script: ScriptSpec | null, number: number): string {
  const scene = script?.scenes[number - 1]
  if (!scene) return `There is no scene ${number}.`
  return `Scene ${number}: ${scene.heading}\n${scene.lines.length ? sceneText(scene.lines) : '(no lines yet)'}`
}

export function memoryGet(memory: Record<string, any>): string {
  const scenes = memory.story_bible?.scenes as Record<string, string> | undefined
  return scenes && Object.keys(scenes).length ? Object.values(scenes).map(summary => `- ${summary}`).join('\n') : 'The story bible is empty.'
}
