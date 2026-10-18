// Screenplay rules, without any editor: which element comes next, what a scene heading looks like,
// and how long a script runs.

import type { LineType, Scene } from './spec'

// The editor's element types: a scene heading, or one of the line types.
export type ElementType = 'heading' | LineType

// Enter at the end of an element starts the next one, as in screenwriting tools.
const NEXT: Record<ElementType, ElementType> = {
  heading: 'action',
  action: 'action',
  character: 'dialogue',
  parenthetical: 'dialogue',
  dialogue: 'character',
  transition: 'heading',
  narration: 'narration',
}

export function nextType(type: ElementType): ElementType {
  return NEXT[type]
}

// Tab on an empty line cycles through the line types (Shift+Tab backwards).
const CYCLE: ElementType[] = ['action', 'character', 'dialogue', 'parenthetical', 'transition', 'narration', 'heading']

export function cycleType(type: ElementType, backwards = false): ElementType {
  const i = CYCLE.indexOf(type)
  return CYCLE[(i + (backwards ? CYCLE.length - 1 : 1)) % CYCLE.length]!
}

// "INT.", "EXT.", "INT./EXT.", "I/E", "EST." at the start make a line a scene heading.
const HEADING_START = /^(INT\.?\/EXT\.|I\/E\.?|INT\.|EXT\.|EST\.)(\s|$)/i

export function isSceneHeading(text: string): boolean {
  return HEADING_START.test(text.trimStart())
}

// "INT. NIGHT MARKET - NIGHT" → location "NIGHT MARKET", time of day "NIGHT".
export function parseHeading(heading: string): { location: string; time_of_day: string } {
  const rest = heading.trim().replace(HEADING_START, '').trim()
  const dash = rest.search(/\s[-–—]\s(?=[^-–—]*$)/)
  if (dash === -1) return { location: rest, time_of_day: '' }
  return { location: rest.slice(0, dash).trim(), time_of_day: rest.slice(dash).replace(/^\s[-–—]\s/, '').trim() }
}

// Page count the standard way: 55 lines a page in 12 pt Courier, each element wrapped at its own
// width and with its usual blank lines; about one page a minute on screen.
export const LINES_PER_PAGE = 55

const LAYOUT: Record<ElementType, { width: number; blankBefore: number }> = {
  heading: { width: 61, blankBefore: 2 },
  action: { width: 61, blankBefore: 1 },
  narration: { width: 61, blankBefore: 1 },
  character: { width: 38, blankBefore: 1 },
  parenthetical: { width: 25, blankBefore: 0 },
  dialogue: { width: 35, blankBefore: 0 },
  transition: { width: 15, blankBefore: 1 },
}

export function printedLines(type: ElementType, text: string, first = false): number {
  const { width, blankBefore } = LAYOUT[type]
  return (first ? 0 : blankBefore) + wrappedRows(text, width)
}

// Printed lines of each scene's body (without its heading), kept while the scene is the same object.
const sceneLines = new WeakMap<Scene, number>()

export function estimate(scenes: Scene[]): { lines: number; pages: number; minutes: number } {
  let lines = 0
  for (const [i, scene] of scenes.entries()) {
    lines += printedLines('heading', scene.heading, i === 0)
    let body = sceneLines.get(scene)
    if (body === undefined) {
      body = scene.lines.reduce((sum, line) => sum + printedLines(line.type, line.text), 0)
      sceneLines.set(scene, body)
    }
    lines += body
  }
  const pages = lines / LINES_PER_PAGE
  return { lines, pages, minutes: pages }
}

// Rows the text takes when wrapped at whole words (a word longer than the row is broken).
// Characters are counted as the eye sees them (grapheme clusters), so Hindi and Gujarati
// combining marks don't count twice.
export function wrappedRows(text: string, width: number): number {
  const words = text.split(/\s+/).filter(Boolean)
  if (!words.length) return 1
  let rows = 1
  let used = 0
  for (const word of words) {
    let length = visibleLength(word)
    if (used && used + 1 + length <= width) {
      used += 1 + length
      continue
    }
    if (used) rows++
    while (length > width) {
      rows++
      length -= width
    }
    used = length
  }
  return rows
}

const segmenter = typeof Intl !== 'undefined' && 'Segmenter' in Intl ? new Intl.Segmenter(undefined, { granularity: 'grapheme' }) : null

function visibleLength(text: string): number {
  if (!segmenter || /^[\x20-\x7e]*$/.test(text)) return text.length
  let count = 0
  for (const _ of segmenter.segment(text)) count++
  return count
}

export function formatRuntime(minutes: number): string {
  const total = Math.round(minutes * 60)
  return `${Math.floor(total / 60)}:${String(total % 60).padStart(2, '0')}`
}
