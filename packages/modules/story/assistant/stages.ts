// The writer's stages, as a professional writer works: each has its instructions (a plain text
// file in prompts/, easy to improve), the tools it may use, a token cap per call, and what "done" means.

import brief from './prompts/brief.txt?raw'
import characters from './prompts/characters.txt?raw'
import chat from './prompts/chat.txt?raw'
import draft from './prompts/draft.txt?raw'
import logline from './prompts/logline.txt?raw'
import outline from './prompts/outline.txt?raw'
import polish from './prompts/polish.txt?raw'
import review from './prompts/review.txt?raw'
import scenes from './prompts/scenes.txt?raw'
import system from './prompts/system.txt?raw'
import type { ScriptSpec, StorySpec } from '../spec'

export type StageId = 'brief' | 'logline' | 'characters' | 'outline' | 'scenes' | 'draft' | 'polish' | 'review'

export type Stage = {
  id: StageId
  label: string
  prompt: string
  // Model-facing tools for one-call stages (draft, polish and review write plain text instead).
  tools: string[]
  maxTokens: number
  done: (story: StorySpec, script: ScriptSpec | null, target: Target) => boolean
}

export const PROMPTS = { system, chat, draft, polish, review }

export const STAGES: Stage[] = [
  { id: 'brief', label: 'Brief', prompt: brief, tools: ['story.setBrief', 'ask_user'], maxTokens: 250, done: story => Boolean(story.genre && story.tone) },
  // Long text is written as plain lines (small models drop long tool arguments).
  { id: 'logline', label: 'Logline', prompt: logline, tools: [], maxTokens: 400, done: story => Boolean(story.logline && story.synopsis) },
  { id: 'characters', label: 'Characters', prompt: characters, tools: ['story.addCharacter'], maxTokens: 600, done: story => (story.characters?.length ?? 0) > 0 },
  { id: 'outline', label: 'Outline', prompt: outline, tools: ['story.addAct'], maxTokens: 600, done: story => (story.acts?.length ?? 0) > 0 },
  { id: 'scenes', label: 'Scene list', prompt: scenes, tools: ['script.addScene'], maxTokens: 900, done: (_story, script, target) => sceneCount(script) >= target.scenes.min },
  { id: 'draft', label: 'Draft scenes', prompt: draft, tools: [], maxTokens: 800, done: (_story, script) => Boolean(script?.scenes.length && script.scenes.every(scene => scene.lines.length > 0)) },
  { id: 'polish', label: 'Polish dialogue', prompt: polish, tools: [], maxTokens: 300, done: () => true },
  { id: 'review', label: 'Review', prompt: review, tools: [], maxTokens: 250, done: () => true },
]

// How big the script should be for a target length.
export type Target = { minutes: number; scenes: { min: number; ideal: number; max: number }; characters: number; acts: number; linesPerScene: number }

export function target(minutes: number): Target {
  const m = Math.max(0.5, minutes)
  const ideal = Math.min(40, Math.max(1, Math.round(m * 0.8)))
  const scenes = { min: Math.max(1, Math.floor(m * 0.5)), ideal, max: Math.max(2, Math.ceil(m * 1.5)) }
  // About 16 written lines ("NAME: …" counts once) fill a page, and a page is about a minute.
  return { minutes: m, scenes, characters: m <= 3 ? 2 : m <= 10 ? 3 : 4, acts: m <= 2 ? 2 : 3, linesPerScene: Math.max(6, Math.round((16 * m) / ideal)) }
}

function sceneCount(script: ScriptSpec | null): number {
  return script?.scenes.filter(scene => scene.heading.trim()).length ?? 0
}

// Fills {{name}} placeholders.
export function fill(text: string, values: Record<string, string | number>): string {
  return text.replace(/\{\{(\w+)\}\}/g, (_, key: string) => String(values[key] ?? ''))
}
