// Client-side module registry. Each module lives in packages/modules/<key>/manifest.ts;
// this is the one place that lists them, in sidebar order. Plain TypeScript, no Vue.

import story from '../../modules/story/manifest'
import character from '../../modules/character/manifest'
import assets3d from '../../modules/assets3d/manifest'
import cgi from '../../modules/cgi/manifest'
import voice from '../../modules/voice/manifest'
import music from '../../modules/music/manifest'
import video from '../../modules/video/manifest'
import ads from '../../modules/ads/manifest'
import player from '../../modules/player/manifest'
import io from '../../modules/io/manifest'

// The task fields an editor receives.
export type EditorTask = {
  id: string
  module: string
  title: string
  status: string
  mode: string
  checkpoint: unknown
  // Assets the task produced.
  outputs?: { role: string; asset: { id: string; name: string; kind: string; kind_label: string; current_version?: { number: number } } }[]
}

// The app's API client, handed to editors (it adds the session, CSRF and workspace headers).
// Errors carry the HTTP status and body (status, data), e.g. 409 with the current version.
export type EditorApi = <T = any>(url: string, options?: { method?: string; body?: unknown; query?: Record<string, unknown> }) => Promise<T>

// A subset of JSON Schema, enough to describe command arguments.
export type JsonSchema = {
  type?: 'object' | 'string' | 'number' | 'integer' | 'boolean' | 'array'
  description?: string
  properties?: Record<string, JsonSchema>
  required?: string[]
  additionalProperties?: boolean
  enum?: (string | number)[]
  minLength?: number
  maxLength?: number
  items?: JsonSchema
}

// A module command: something both the UI and an assistant can do to a task's checkpoint.
// The UI's buttons and the assistant's tool calls go through the same apply().
export type ModuleCommand = {
  description: string
  // The arguments, as JSON Schema (also what the assistant's brain sees).
  schema: JsonSchema
  // Returns the new checkpoint; never changes the one given.
  apply: (checkpoint: Record<string, any>, args: Record<string, any>) => Record<string, any>
}

// Shown by an editor while an assistant step changes it ("visible process").
export type EditorHighlight = { tool: string; args: Record<string, unknown>; at: number } | null

export type ClientModule = {
  // Same key as the server manifest (app/Modules/<Name>/Manifest.php).
  key: string
  name: string
  // A lucide icon name.
  icon: string
  // Asset kinds this module owns; each kind has packages/schemas/<kind>.schema.json.
  assetKinds: string[]
  // Commands the editor offers: the UI and the assistant use them alike.
  commands: Record<string, ModuleCommand>
  // Lazy-loads the editor component (a Vue component; typed loosely to keep core free of Vue).
  editor: () => Promise<{ default: unknown }>
  // Lazy-loads the module's own assistant (an ai-runtime ModuleAssistantFactory; typed loosely so
  // core doesn't depend on ai-runtime). Without one, the panel uses the plain assistant loop.
  assistant?: () => Promise<{ default: unknown }>
}

// Generic asset kinds owned by the core; any module may create them.
export const coreAssetKinds = ['image', 'audio', 'video', 'file']

export const modules: ClientModule[] = [story, character, assets3d, cgi, voice, music, video, ads, player, io]

export function findModule(key: string): ClientModule | undefined {
  return modules.find(module => module.key === key)
}

// Who owns each asset kind: "core" or a module key.
export function assetKindOwners(): Record<string, string> {
  const owners: Record<string, string> = {}
  for (const kind of coreAssetKinds) owners[kind] = 'core'
  for (const module of modules) {
    for (const kind of module.assetKinds) owners[kind] = module.key
  }
  return owners
}
