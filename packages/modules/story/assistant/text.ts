// The plain-text forms a small model writes well, and code turns into script lines:
//   REASON: …            SUMMARY: …
//   ACTION: what we see  NAME: what they say  NAME (how): what they say  TRANSITION: CUT TO:
// Works in any language: the keywords are fixed, names and text are not.

import { characterName } from '../editor/convert'
import type { LineType, ScriptLine, StoryCharacter } from '../spec'

export type DraftedScene = { reason: string; summary: string; lines: Omit<ScriptLine, 'id'>[]; speakers: string[] }

const KEYWORDS: Record<string, LineType | 'reason' | 'summary'> = {
  ACTION: 'action', TRANSITION: 'transition', NARRATION: 'narration', REASON: 'reason', SUMMARY: 'summary',
}

// cast: the story's character names. A small model sometimes writes several speeches on one
// ACTION line ("ACTION: Jake stares. JAKE: "Mine." MAX: "No.""); they are split at a cast name.
export function parseDraft(output: string, cast: string[] = []): DraftedScene {
  const result: DraftedScene = { reason: '', summary: '', lines: [], speakers: [] }
  const text = output.replace(/<think>[\s\S]*?(<\/think>|$)/g, '').replace(/```[a-z]*\n?/g, '')
  const names = cast.map(name => name.trim()).filter(Boolean).map(name => name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'))
  const inline = names.length ? new RegExp(`\\s+(?=(?:${names.join('|')})\\s*(?:\\([^)]{1,60}\\))?\\s*:)`, 'giu') : null
  const rows = text.split('\n').flatMap(row => (inline ? row.split(inline) : [row]))

  for (const raw of rows) {
    const line = raw.replace(/^\s*[-*•]\s+/, '').replace(/\*\*/g, '').trim()
    if (!line) continue

    const keyword = line.match(/^([A-Z]+)\s*:\s*(.*)$/)
    const kind = keyword ? KEYWORDS[keyword[1]!] : undefined
    if (keyword && kind) {
      const value = keyword[2]!.trim()
      if (kind === 'reason') result.reason ||= value
      else if (kind === 'summary') result.summary ||= value
      else if (value) result.lines.push({ type: kind, text: value })
      continue
    }
    // A heading the model repeats is dropped (the scene has one).
    if (/^(INT|EXT)[./]/i.test(line)) continue

    // NAME: text, or NAME (how): text. A name is short and has no sentence punctuation.
    const speech = line.match(/^([^:()]{1,40}?)\s*(?:\(([^)]{1,60})\))?\s*:\s*(.+)$/)
    // The form's own placeholders, copied: "NAME: JAKE", "NAME (how): Gruff".
    if (speech && /^(NAME|CHARACTER)$/i.test(speech[1]!.trim())) continue
    if (speech && !/[.!?,"]/.test(speech[1]!)) {
      const name = characterName(speech[1]!)
      result.lines.push({ type: 'character', text: name })
      if (speech[2]) result.lines.push({ type: 'parenthetical', text: `(${speech[2].trim()})` })
      result.lines.push({ type: 'dialogue', text: speech[3]!.trim().replace(/^"(.*)"$/s, '$1') })
      if (!result.speakers.includes(name)) result.speakers.push(name)
      continue
    }

    // Anything else carries on the line before it (or is action).
    const last = result.lines.at(-1)
    if (last && (last.type === 'action' || last.type === 'dialogue')) last.text = `${last.text} ${line}`
    else result.lines.push({ type: 'action', text: line })
  }
  return result
}

// Speakers who aren't story characters.
export function unknownSpeakers(speakers: string[], characters: StoryCharacter[]): string[] {
  const known = new Set(characters.map(character => characterName(character.name)))
  return speakers.filter(name => !known.has(name))
}

// Links character, parenthetical and dialogue lines to the character speaking.
export function linkSpeakers(lines: Omit<ScriptLine, 'id'>[], characters: StoryCharacter[]): Omit<ScriptLine, 'id'>[] {
  const ids = new Map(characters.map(character => [characterName(character.name), character.id]))
  let speaker: string | null = null
  return lines.map((line) => {
    if (line.type === 'character') speaker = ids.get(characterName(line.text)) ?? null
    else if (line.type !== 'dialogue' && line.type !== 'parenthetical') speaker = null
    const characterId = line.type === 'character' || line.type === 'dialogue' || line.type === 'parenthetical' ? speaker : null
    const { character_id: _old, ...rest } = line
    return characterId ? { ...rest, character_id: characterId } : rest
  })
}

// "LOGLINE: …" and "SYNOPSIS: …" (the synopsis may go on over several lines).
export function parseLogline(output: string): { reason: string; logline: string; synopsis: string } {
  const text = output.replace(/<think>[\s\S]*?(<\/think>|$)/g, '').replace(/\*\*/g, '')
  const field = (name: string) => text.match(new RegExp(`${name}\\s*:\\s*([\\s\\S]*?)(?=\\n\\s*(?:REASON|LOGLINE|SYNOPSIS)\\s*:|$)`, 'i'))?.[1]?.replace(/\s+/g, ' ').trim() ?? ''
  return { reason: field('REASON'), logline: field('LOGLINE').slice(0, 500), synopsis: field('SYNOPSIS').slice(0, 20000) }
}

// A scene as numbered lines, for reading (getScene) and for the polish stage.
export function sceneText(lines: ScriptLine[]): string {
  return lines.map((line, i) => {
    const n = `${i + 1}`
    switch (line.type) {
      case 'character': return `${n}. [${line.text}]`
      case 'dialogue': return `${n}. ${line.text}`
      case 'parenthetical': return `${n}. ${line.text}`
      default: return `${n}. ${line.type.toUpperCase()}: ${line.text}`
    }
  }).join('\n')
}

// "3: new text" answers from the polish stage.
export function parseEdits(output: string): { reason: string; edits: { number: number; text: string }[] } {
  const reason = output.match(/REASON\s*:\s*(.+)/)?.[1]?.trim() ?? ''
  const edits = [...output.matchAll(/^\s*(\d{1,4})\s*[:.)]\s*(.+)$/gm)].map(match => ({ number: Number(match[1]), text: match[2]!.trim() }))
  return { reason, edits }
}

// Which script the text is written in: share of letters per script.
export function scriptShare(text: string): { latin: number; devanagari: number; gujarati: number } {
  const count = (pattern: RegExp) => (text.match(pattern) ?? []).length
  const latin = count(/[A-Za-z]/g)
  const devanagari = count(/[ऀ-ॿ]/g)
  const gujarati = count(/[઀-૿]/g)
  const all = Math.max(1, latin + devanagari + gujarati)
  return { latin: latin / all, devanagari: devanagari / all, gujarati: gujarati / all }
}

export const LANGUAGE_NAMES: Record<string, string> = {
  en: 'English', hi: 'Hindi (Devanagari script)', gu: 'Gujarati (Gujarati script)', mr: 'Marathi (Devanagari script)',
  bn: 'Bengali', ta: 'Tamil', te: 'Telugu', es: 'Spanish', fr: 'French',
}
