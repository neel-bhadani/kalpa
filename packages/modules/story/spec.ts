// The specs of the module's two asset kinds (same shape as packages/schemas/story|script.schema.json).
// Ids are stable UUIDs made here; the server's script_scenes and script_lines mirror them.

export type Act = { id: string; title: string; summary?: string }
export type StoryCharacter = { id: string; name: string; role?: string; description?: string; character_asset_id?: string | null }

export type StorySpec = {
  title: string
  logline?: string
  genre?: string
  tone?: string
  audience?: string
  language?: string
  target_length?: number
  synopsis?: string
  acts?: Act[]
  characters?: StoryCharacter[]
  themes?: string[]
}

export const LINE_TYPES = ['action', 'character', 'dialogue', 'parenthetical', 'transition', 'narration'] as const
export type LineType = (typeof LINE_TYPES)[number]

export type ScriptLine = { id: string; type: LineType; text: string; character_id?: string | null; emotion?: string }
export type Scene = { id: string; heading: string; location?: string; time_of_day?: string; summary?: string; lines: ScriptLine[] }

export type ScriptSpec = {
  title: string
  format?: 'screenplay' | 'short' | 'ad' | 'story'
  story_asset_id?: string | null
  language?: string
  target_duration_s?: number
  scenes: Scene[]
}

export type SpecKind = 'story' | 'script'

// Limits from the schemas, checked before a change is made (the server checks them again).
export const LIMITS = {
  scenes: 500,
  linesPerScene: 1000,
  lineText: 5000,
  heading: 200,
  acts: 20,
  characters: 200,
  themes: 30,
} as const

export function newId(): string {
  return crypto.randomUUID()
}
