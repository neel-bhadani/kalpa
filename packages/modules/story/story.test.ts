import fc from 'fast-check'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { specCommands } from './commands'
import { VersionCommitter, type CommitPort } from './committer'
import { diffScript } from './diff'
import { commitBody, newDraft, runCommand, summarize, undo, type Draft } from './draft'
import { applyPatch, compact } from './patch'
import { cycleType, estimate, isSceneHeading, nextType, parseHeading, printedLines, wrappedRows } from './screenplay'
import type { Scene, ScriptSpec, StorySpec } from './spec'

const story = (): StorySpec => ({
  title: 'Night market',
  acts: [{ id: 'a1', title: 'Setup' }, { id: 'a2', title: 'Chase' }, { id: 'a3', title: 'Home' }],
  characters: [{ id: 'c1', name: 'MIRA', role: 'lead' }],
  themes: ['light'],
})

const script = (): ScriptSpec => ({
  title: 'Night market',
  scenes: [
    { id: 's1', heading: 'EXT. NIGHT MARKET - NIGHT', lines: [{ id: 'l1', type: 'action', text: 'Lanterns flicker on.' }, { id: 'l2', type: 'character', text: 'MIRA' }, { id: 'l3', type: 'dialogue', text: 'Late again.' }] },
    { id: 's2', heading: 'EXT. ALLEY - NIGHT', lines: [{ id: 'l4', type: 'action', text: 'A cat waits.' }] },
  ],
})

describe('commands', () => {
  // [command, args, spec, the patch it must make]
  const cases: [string, Record<string, unknown>, () => any, unknown[]][] = [
    ['story.setField', { field: 'logline', value: 'A girl chases the last light.' }, story, [{ op: 'add', path: '/logline', value: 'A girl chases the last light.' }]],
    ['story.setField', { field: 'target_length', value: '12' }, story, [{ op: 'add', path: '/target_length', value: 12 }]],
    ['story.setField', { field: 'themes', value: [' light ', 'home'] }, story, [{ op: 'add', path: '/themes', value: ['light', 'home'] }]],
    ['story.addAct', { id: 'a4', title: 'Coda', index: 1 }, story, [{ op: 'add', path: '/acts/1', value: { id: 'a4', title: 'Coda' } }]],
    ['story.addAct', { id: 'a1', title: 'First' }, () => ({ title: 'Bare' }), [{ op: 'add', path: '/acts', value: [] }, { op: 'add', path: '/acts/0', value: { id: 'a1', title: 'First' } }]],
    ['story.updateAct', { id: 'a2', summary: 'Through the stalls.' }, story, [{ op: 'add', path: '/acts/1/summary', value: 'Through the stalls.' }]],
    ['story.moveAct', { id: 'a3', to: 0 }, story, [{ op: 'move', from: '/acts/2', path: '/acts/0' }]],
    ['story.removeAct', { id: 'a2' }, story, [{ op: 'remove', path: '/acts/1' }]],
    ['story.addCharacter', { id: 'c2', name: 'CAT' }, story, [{ op: 'add', path: '/characters/1', value: { id: 'c2', name: 'CAT' } }]],
    ['story.updateCharacter', { id: 'c1', description: 'Sells lanterns.' }, story, [{ op: 'add', path: '/characters/0/description', value: 'Sells lanterns.' }]],
    ['story.removeCharacter', { id: 'c1' }, story, [{ op: 'remove', path: '/characters/0' }]],
    ['script.setField', { field: 'language', value: 'hi' }, script, [{ op: 'add', path: '/language', value: 'hi' }]],
    ['script.addScene', { id: 's3', heading: 'INT. ROOF - DAWN', index: 1 }, script, [{ op: 'add', path: '/scenes/1', value: { id: 's3', heading: 'INT. ROOF - DAWN', location: 'ROOF', time_of_day: 'DAWN', lines: [] } }]],
    ['script.updateScene', { id: 's2', heading: 'EXT. ROOF - DAY' }, script, [
      { op: 'add', path: '/scenes/1/heading', value: 'EXT. ROOF - DAY' }, { op: 'add', path: '/scenes/1/location', value: 'ROOF' }, { op: 'add', path: '/scenes/1/time_of_day', value: 'DAY' },
    ]],
    ['script.moveScene', { id: 's2', to: 0 }, script, [{ op: 'move', from: '/scenes/1', path: '/scenes/0' }]],
    ['script.removeScene', { id: 's1' }, script, [{ op: 'remove', path: '/scenes/0' }]],
    ['script.addLine', { id: 'l5', scene_id: 's2', type: 'character', text: 'MIRA', index: 0 }, script, [{ op: 'add', path: '/scenes/1/lines/0', value: { id: 'l5', type: 'character', text: 'MIRA' } }]],
    ['script.updateLine', { id: 'l3', text: 'Late, again.' }, script, [{ op: 'add', path: '/scenes/0/lines/2/text', value: 'Late, again.' }]],
    ['script.moveLine', { id: 'l1', scene_id: 's1', index: 2 }, script, [{ op: 'move', from: '/scenes/0/lines/0', path: '/scenes/0/lines/2' }]],
    ['script.moveLine', { id: 'l3', scene_id: 's2', index: 0 }, script, [{ op: 'move', from: '/scenes/0/lines/2', path: '/scenes/1/lines/0' }]],
    ['script.removeLine', { id: 'l4' }, script, [{ op: 'remove', path: '/scenes/1/lines/0' }]],
  ]

  it.each(cases)('%s %j makes the right patch, and its inverse undoes it', (name, args, make, expected) => {
    const spec = make()
    const before = structuredClone(spec)
    const patch = specCommands[name]!.patch(spec, args)
    expect(patch).toEqual(expected)

    const { doc, inverse } = applyPatch(spec, patch)
    expect(spec).toEqual(before) // never changed in place
    expect(doc).not.toEqual(before)
    expect(applyPatch(doc, inverse).doc).toEqual(before)
  })

  it('refuses what it cannot do, with a plain message', () => {
    expect(() => specCommands['script.updateLine']!.patch(script(), { id: 'nope', text: 'x' })).toThrow('There is no line with id "nope".')
    expect(() => specCommands['story.setField']!.patch(story(), { field: 'title', value: ' ' })).toThrow('The title can\'t be empty.')
    expect(() => specCommands['story.setField']!.patch(story(), { field: 'target_length', value: 900 })).toThrow('0 to 600')
    expect(() => specCommands['script.setField']!.patch(script(), { field: 'language', value: 'Hindi!' })).toThrow('language code')
    const full: ScriptSpec = { title: 'Long', scenes: Array.from({ length: 500 }, (_, i) => ({ id: `s${i}`, heading: '', lines: [] })) }
    expect(() => specCommands['script.addScene']!.patch(full, { id: 'x' })).toThrow('at most 500 scenes')
  })

  it('runs on a draft, queues the patch, and undo puts it back', () => {
    const draft = newDraft('asset-1', 'script', { number: 4, spec: script() })
    const { draft: edited, inverse, args } = runCommand(draft, 'script.addLine', { scene_id: 's2', type: 'action', text: 'It runs.' })

    expect(args.id).toEqual(expect.any(String))
    expect(edited.spec.scenes[1]!.lines.map(line => line.text)).toEqual(['A cat waits.', 'It runs.'])
    expect(edited.ops).toHaveLength(1)
    expect(edited.rev).toBe(1)
    expect(draft.spec.scenes[1]!.lines).toHaveLength(1)

    const back = undo(edited, inverse)
    expect(back.spec).toEqual(draft.spec)
    expect(back.ops).toHaveLength(2)
    expect(() => runCommand(draft, 'story.addAct', { title: 'x' })).toThrow('changes a story, not a script')
  })
})

describe('version messages', () => {
  it('summarises the changes since the last version', () => {
    let draft: Draft = newDraft('a', 'script', { number: 1, spec: script() })
    for (const text of ['L', 'La', 'Late']) draft = runCommand(draft, 'script.updateLine', { id: 'l3', text }).draft
    draft = runCommand(draft, 'script.updateLine', { id: 'l1', text: 'Lanterns.' }).draft
    draft = runCommand(draft, 'script.updateLine', { id: 'l2', text: 'MIRA (V.O.)' }).draft
    expect(summarize(draft.changes)).toBe('Edited 3 lines in Scene 1')

    draft = runCommand(draft, 'script.addScene', { heading: 'INT. ROOF - DAWN' }).draft
    draft = runCommand(draft, 'script.setField', { field: 'target_duration_s', value: 300 }).draft
    expect(summarize(draft.changes)).toBe('Edited 3 lines in Scene 1; Added Scene 3; Edited the target duration')

    // A line added and then edited is "added"; one added and removed again isn't mentioned.
    let fresh: Draft = newDraft('a', 'script', { number: 1, spec: script() })
    const added = runCommand(fresh, 'script.addLine', { scene_id: 's2', type: 'action', text: 'E' })
    fresh = runCommand(added.draft, 'script.updateLine', { id: added.args.id, text: 'EXT. ROOF' }).draft
    const gone = runCommand(fresh, 'script.addLine', { scene_id: 's2', type: 'action', text: '' })
    fresh = runCommand(gone.draft, 'script.removeLine', { id: gone.args.id }).draft
    fresh = runCommand(fresh, 'script.addScene', { heading: 'I' }).draft
    fresh = runCommand(fresh, 'script.updateScene', { id: fresh.spec.scenes[2]!.id, heading: 'INT. ROOF' }).draft
    expect(summarize(fresh.changes)).toBe('Added 1 line in Scene 2; Added Scene 3')

    // Typing in one line many times is one replace in the commit.
    expect(commitBody(draft).patch!.filter(op => op.path === '/scenes/0/lines/2/text')).toHaveLength(1)
  })

  it('compacts repeated replaces of the same value', () => {
    expect(compact([
      { op: 'replace', path: '/a', value: 1 }, { op: 'replace', path: '/a', value: 2 },
      { op: 'add', path: '/b', value: 1 }, { op: 'replace', path: '/a', value: 3 },
      { op: 'add', path: '/c', value: 1 }, { op: 'add', path: '/c', value: 2 },
      { op: 'add', path: '/list/0', value: 'x' }, { op: 'add', path: '/list/0', value: 'y' },
    ])).toEqual([
      { op: 'replace', path: '/a', value: 2 }, { op: 'add', path: '/b', value: 1 }, { op: 'replace', path: '/a', value: 3 },
      { op: 'add', path: '/c', value: 2 },
      // Two inserts into a list are two items.
      { op: 'add', path: '/list/0', value: 'x' }, { op: 'add', path: '/list/0', value: 'y' },
    ])
  })
})

describe('screenplay rules', () => {
  it('moves between element types like screenwriting tools', () => {
    expect(nextType('heading')).toBe('action')
    expect(nextType('action')).toBe('action')
    expect(nextType('character')).toBe('dialogue')
    expect(nextType('parenthetical')).toBe('dialogue')
    expect(nextType('dialogue')).toBe('character')
    expect(nextType('transition')).toBe('heading')

    const seen = ['action']
    for (let i = 0; i < 6; i++) seen.push(cycleType(seen.at(-1) as any))
    expect(seen).toEqual(['action', 'character', 'dialogue', 'parenthetical', 'transition', 'narration', 'heading'])
    expect(cycleType('heading')).toBe('action')
    expect(cycleType('action', true)).toBe('heading')
  })

  it('knows a scene heading', () => {
    for (const text of ['INT. KITCHEN - DAY', 'ext. roof', 'INT./EXT. CAR - NIGHT', 'I/E VAN', 'EXT.']) expect(isSceneHeading(text)).toBe(true)
    for (const text of ['Interior design', 'EXTRA', 'He walks INT. the room']) expect(isSceneHeading(text)).toBe(false)
    expect(parseHeading('INT. NIGHT MARKET - NIGHT')).toEqual({ location: 'NIGHT MARKET', time_of_day: 'NIGHT' })
    expect(parseHeading('EXT. ROAD - EAST - CONTINUOUS')).toEqual({ location: 'ROAD - EAST', time_of_day: 'CONTINUOUS' })
    expect(parseHeading('INT. घर - रात')).toEqual({ location: 'घर', time_of_day: 'रात' })
  })

  it('counts printed lines and pages the standard way', () => {
    expect(wrappedRows('', 35)).toBe(1)
    expect(wrappedRows('word '.repeat(7).trim(), 35)).toBe(1) // 34 columns
    expect(wrappedRows('word '.repeat(8).trim(), 35)).toBe(2)
    // 61 columns of action: 100 one-letter words take 2 rows (61 + 39).
    expect(wrappedRows('a '.repeat(100).trim(), 61)).toBe(4)
    // Hindi counts what you see: "नमस्ते" is 3 grapheme clusters, not 6 code points.
    expect(wrappedRows('नमस्ते '.repeat(10).trim(), 35)).toBe(2)

    // Blank lines: 2 before a heading (not the first), 1 before action/character, 0 before dialogue.
    expect(printedLines('heading', 'INT. A', true)).toBe(1)
    expect(printedLines('heading', 'INT. A')).toBe(3)
    expect(printedLines('dialogue', 'Hi.')).toBe(1)

    // One scene: heading 1 + action 2 + character 2 + dialogue 1 = 6 lines.
    expect(estimate(script().scenes.slice(0, 1))).toEqual({ lines: 6, pages: 6 / 55, minutes: 6 / 55 })
    // 120 scenes of 10 exchanges (heading 3, then 10 × (character 2 + dialogue 1)) = 33 lines each.
    const scenes: Scene[] = Array.from({ length: 120 }, (_, s) => ({
      id: `s${s}`, heading: 'INT. ROOM - DAY',
      lines: Array.from({ length: 20 }, (_, l) => ({ id: `${s}-${l}`, type: l % 2 ? 'dialogue' : 'character', text: l % 2 ? 'Fine.' : 'MIRA' })),
    }))
    expect(estimate(scenes).lines).toBe(1 + 119 * 3 + 120 * 30)
    expect(estimate(scenes).pages).toBeCloseTo(72, 0)
  })
})

describe('VersionCommitter', () => {
  afterEach(() => { vi.useRealTimers() })

  function setup(answer: (body: any) => any = () => ({ ok: true, version: 5 })) {
    let draft: Draft = newDraft('asset-1', 'script', { number: 4, spec: script() })
    const sent: any[] = []
    const port: CommitPort = {
      get: () => draft,
      set: (next) => { draft = next },
      send: async (_id, body) => {
        sent.push(body)
        return answer(body)
      },
    }
    const states: string[] = []
    const committer = new VersionCommitter(port, state => states.push(state))
    const edit = (text: string) => {
      draft = runCommand(draft, 'script.updateLine', { id: 'l1', text }).draft
      committer.touched()
    }
    return { committer, sent, states, edit, draft: () => draft }
  }

  it('commits 10 s after the last edit, once', async () => {
    vi.useFakeTimers()
    const { sent, edit, draft } = setup()

    edit('L')
    await vi.advanceTimersByTimeAsync(9_000)
    edit('La') // the 10 s start again
    await vi.advanceTimersByTimeAsync(9_000)
    expect(sent).toHaveLength(0)

    await vi.advanceTimersByTimeAsync(1_000)
    expect(sent).toEqual([{ base_version: 4, patch: [{ op: 'add', path: '/scenes/0/lines/0/text', value: 'La' }], message: 'Edited 1 line in Scene 1' }])
    expect(draft()).toMatchObject({ baseVersion: 5, ops: [], changes: [] })

    // Nothing new: nothing sent.
    await vi.advanceTimersByTimeAsync(60_000)
    expect(sent).toHaveLength(1)
  })

  it('commits at once on request, keeping edits made while it saves', async () => {
    let finish!: (value: unknown) => void
    const { committer, sent, edit, draft, states } = setup(() => new Promise(resolve => { finish = resolve }))
    edit('A')
    const saving = committer.commit()
    await Promise.resolve()
    edit('AB') // during the request
    finish({ ok: true, version: 5 })
    await saving

    expect(sent).toHaveLength(1)
    expect(draft().ops).toHaveLength(1)
    expect(draft().baseVersion).toBe(5)
    expect(states.at(-1)).toBe('unsaved')
  })

  it('saves on top of another device\'s version, and keeps the draft when offline', async () => {
    const answers = [{ ok: false, conflict: 9 }, { ok: true, version: 10 }]
    const first = setup(() => answers.shift())
    first.edit('Mine')
    await first.committer.commit()
    expect(first.sent[1]).toMatchObject({ base_version: 9, message: 'Edited 1 line in Scene 1 (after v9 from another device)' })
    expect(first.sent[1].spec.scenes[0].lines[0].text).toBe('Mine')
    expect(first.draft().baseVersion).toBe(10)

    const offline = setup(() => { throw new TypeError('Failed to fetch') })
    offline.edit('Offline')
    await offline.committer.commit()
    expect(offline.states.at(-1)).toBe('error')
    expect(offline.draft().ops).toHaveLength(1)
  })
})

describe('diffScript', () => {
  // Random scripts made from a small pool of ids, so scenes and lines move, appear and go.
  const line = fc.record({
    id: fc.constantFrom('l1', 'l2', 'l3', 'l4', 'l5', 'l6', 'l7', 'l8'),
    type: fc.constantFrom('action', 'character', 'dialogue'),
    text: fc.constantFrom('', 'Hi.', 'MIRA', 'Lanterns.'),
  })
  const spec = fc.uniqueArray(fc.record({ id: fc.constantFrom('s1', 's2', 's3', 's4'), heading: fc.constantFrom('', 'INT. A - DAY', 'EXT. B') }), { selector: scene => scene.id, maxLength: 4 })
    .chain(scenes => fc.uniqueArray(line, { selector: item => item.id, maxLength: 8 }).chain(lines => fc.array(fc.nat(Math.max(scenes.length - 1, 0)), { minLength: lines.length, maxLength: lines.length }).map(where => ({
      title: 'T',
      scenes: scenes.map((scene, s) => ({ ...scene, lines: lines.filter((_, i) => where[i] === s) as any })),
    }))))

  const shape = (value: ScriptSpec) => value.scenes.map(scene => ({ id: scene.id, heading: scene.heading, lines: scene.lines.map(({ id, type, text }) => ({ id, type, text })) }))

  it('makes commands that turn one script into the other', () => {
    fc.assert(fc.property(spec, spec, (prev, next) => {
      let draft: Draft<ScriptSpec> = newDraft('a', 'script', { number: 1, spec: prev as ScriptSpec })
      for (const call of diffScript(prev as ScriptSpec, next as ScriptSpec)) draft = runCommand(draft, call.name, call.args).draft
      expect(shape(draft.spec)).toEqual(shape(next as ScriptSpec))
    }), { numRuns: 400 })
  })

  it('costs one updateLine for a keystroke', () => {
    const prev = script()
    const next = { ...prev, scenes: [prev.scenes[0]!, { ...prev.scenes[1]!, lines: [{ ...prev.scenes[1]!.lines[0]!, text: 'A cat waits!' }] }] }
    expect(diffScript(prev, next)).toEqual([{ name: 'script.updateLine', args: { id: 'l4', text: 'A cat waits!' } }])
    expect(diffScript(prev, prev)).toEqual([])
  })
})

describe('reading the screenplay page', async () => {
  const { readScript, scriptToDoc } = await import('./editor/convert')
  const { EditorState } = await import('prosemirror-state')

  it('reads back what it shows, reusing every unchanged line and scene', () => {
    const spec = script()
    const doc = scriptToDoc(spec)
    const first = readScript(doc, spec, [])
    expect(shape(first)).toEqual(shape(spec))

    // Read again from the same document: the very same script.
    expect(readScript(doc, first, [])).toBe(first)

    // Type at the end of "Late again." (the 4th element): only that scene is new.
    let end = 0
    for (let i = 0; i <= 3; i++) end += doc.child(i).nodeSize
    const edited = EditorState.create({ doc }).tr.insertText('!', end - 1).doc
    const next = readScript(edited, first, [])
    expect(next.scenes[0]!.lines[2]!.text).toBe('Late again.!')
    expect(next.scenes[1]).toBe(first.scenes[1])
    expect(next.scenes[0]!.lines[0]).toBe(first.scenes[0]!.lines[0])
  })

  it('links speakers to story characters, and relinks when the cast changes', () => {
    const spec = script()
    const doc = scriptToDoc(spec)
    const unlinked = readScript(doc, spec, [])
    expect(unlinked.scenes[0]!.lines[2]!.character_id).toBeUndefined()

    const cast = [{ id: 'c1', name: 'Mira' }]
    const linked = readScript(doc, unlinked, cast)
    expect(linked.scenes[0]!.lines.map(line => line.character_id ?? null)).toEqual([null, 'c1', 'c1'])
  })

  function shape(value: ScriptSpec) {
    return value.scenes.map(scene => ({ id: scene.id, heading: scene.heading, lines: scene.lines.map(({ id, type, text }) => ({ id, type, text })) }))
  }
})

describe('the page keeps to the limits', async () => {
  const { EditorState } = await import('prosemirror-state')
  const { scriptToDoc } = await import('./editor/convert')
  const { screenplayPlugins } = await import('./editor/plugins')

  it('refuses typing past 200 characters in a heading, and allows it in a line', () => {
    const state = EditorState.create({ doc: scriptToDoc(script()), plugins: screenplayPlugins() })
    const headingEnd = state.doc.child(0).nodeSize - 1
    const long = state.apply(state.tr.insertText('x'.repeat(200), headingEnd))
    expect(long.doc.child(0).textContent).toBe('EXT. NIGHT MARKET - NIGHT')

    const fits = state.apply(state.tr.insertText('x'.repeat(175), headingEnd))
    expect(fits.doc.child(0).textContent).toHaveLength(200)

    const lineEnd = state.doc.child(0).nodeSize + state.doc.child(1).nodeSize - 1
    expect(state.apply(state.tr.insertText('x'.repeat(1000), lineEnd)).doc.child(1).textContent).toHaveLength(1020)
  })
})
