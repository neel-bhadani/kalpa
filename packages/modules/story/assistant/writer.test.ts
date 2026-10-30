import { describe, expect, it } from 'vitest'
import type { Brain, BrainChunk, ChatRequest } from '../../../ai-runtime/src/brain'
import type { ModuleAssistantEvent } from '../../../ai-runtime/src/module-assistant'
import type { Mode } from '../../../ai-runtime/src/runner'
import type { ScriptSpec, StorySpec } from '../spec'
import { StoryFakeBrain } from './fake-brain'
import { memoryAi, memoryStore } from './memory-store'
import { parseDraft } from './text'
import { checkScript, StoryWriter, type Plan } from './writer'
import { target } from './stages'

const BRIEF = { brief: 'A 3-minute short about a girl in Surat who fixes old radios', minutes: 3 }

function setup(mode: Mode = 'auto', brain: Brain = new StoryFakeBrain(), plan: Partial<Plan> | null = null) {
  const story: StorySpec = { title: 'Radio', language: 'en', acts: [], characters: [], themes: [] }
  // As a new script task starts: one blank scene.
  const script: ScriptSpec = { title: 'Radio', format: 'screenplay', language: 'en', scenes: [{ id: '0199f0a0-0000-7000-8000-00000000b1a0', heading: '', lines: [] }] }
  const mem = memoryStore(story, script)
  const ai = memoryAi()
  const events: ModuleAssistantEvent[] = []
  let currentMode = mode
  const writer = new StoryWriter({ brain, ai: ai.ai, store: mem.store, mode: () => currentMode, plan, onEvent: event => events.push(event) })
  return {
    writer, ai, events,
    setMode: (next: Mode) => { currentMode = next },
    story: () => mem.drafts.story!.spec as StorySpec,
    script: () => mem.drafts.script!.spec as ScriptSpec,
    versions: mem.versions,
    memory: mem.memory,
    progress: () => events.flatMap(event => (event.type === 'progress' && event.text ? [event.text] : [])),
  }
}

// A brain that answers each call with the next scripted reply (text and/or tool calls).
function scripted(replies: { text?: string; calls?: [string, Record<string, unknown>][] }[]): Brain & { requests: ChatRequest[] } {
  const requests: ChatRequest[] = []
  return {
    requests,
    async *chat(request): AsyncIterable<BrainChunk> {
      requests.push(request)
      const reply = replies.shift() ?? {}
      if (reply.text) yield { type: 'text', text: reply.text }
      for (const [i, [name, args]] of (reply.calls ?? []).entries()) yield { type: 'tool_call', call: { id: `c${i}`, name, args } }
    },
  }
}

describe('the stage machine', () => {
  it('Auto runs every stage: brief to review, each AI step its own version', async () => {
    const { writer, ai, story, script, versions, memory, progress } = setup('auto')
    await writer.start(BRIEF)

    expect(writer.plan).toMatchObject({ state: 'done', stage: 8 })
    expect(story()).toMatchObject({ title: 'The Radio Mender of Surat', genre: 'Drama', logline: expect.stringContaining('Surat') })
    expect(story().characters!.map(character => character.name)).toEqual(['ASHA', 'DADA'])
    expect(story().acts).toHaveLength(3)

    // 3 minutes: 2 scenes (target 1–5), each drafted and summarised.
    expect(script().scenes).toHaveLength(2)
    expect(script().scenes.every(scene => scene.lines.length >= 5 && scene.summary)).toBe(true)
    expect(progress()).toEqual(expect.arrayContaining(['Drafting scene 1 of 2', 'Drafting scene 2 of 2']))

    // Every step is done, has a reason, and made exactly one version naming it.
    const steps = ai.list()
    expect(steps.every(step => step.status === 'done' && step.reason)).toBe(true)
    const aiVersions = [...versions.story, ...versions.script].filter(version => version.stepId)
    expect(aiVersions.map(version => version.stepId).sort()).toEqual(steps.map(step => step.id).sort())
    expect(versions.script.map(version => version.message)).toContain('AI: Drafted Scene 1')

    // The story bible has a one-line summary per drafted scene.
    expect(Object.keys(memory.data.story_bible.scenes)).toEqual(script().scenes.map(scene => scene.id))
    expect(memory.patches.every(patch => patch.stepId)).toBe(true)

    // The polish edited dialogue, and the review is a message.
    expect(script().scenes[0]!.lines[2]!.text).toBe('One valve. That\'s all, Dada.')
    expect(ai.messages.at(-1)!.content.text).toContain('The goal is clear')
  })

  it('Semi stops after each stage with its steps waiting; approving the stage goes on', async () => {
    const { writer, ai, story } = setup('semi')
    await writer.start(BRIEF)

    expect(writer.plan).toMatchObject({ state: 'review', stage: 1 })
    expect(ai.list().map(step => [step.title, step.status])).toEqual([['Set up the brief', 'awaiting_review']])
    expect(story().genre).toBeUndefined() // nothing applied yet

    await writer.approveStage()
    expect(story().genre).toBe('Drama')
    expect(writer.plan).toMatchObject({ state: 'review', stage: 2 }) // stopped after Logline
    expect(ai.list().filter(step => step.status === 'awaiting_review').map(step => step.title)).toEqual(['Wrote the logline and synopsis'])

    // Through to the end, one stage at a time.
    for (let i = 0; i < 10 && writer.plan!.state !== 'done'; i++) await writer.approveStage()
    expect(writer.plan!.state).toBe('done')
    expect(ai.list().every(step => step.status === 'done')).toBe(true)
  })

  it('Manual only suggests, and changes nothing', async () => {
    const { writer, ai, story, versions } = setup('manual')
    await writer.start(BRIEF)
    expect(ai.list().map(step => step.status)).toEqual(['planned'])
    expect(story().title).toBe('Radio')
    expect(versions.story).toHaveLength(1)
  })
})

describe('the per-scene loop', () => {
  it('drafts long scripts one scene at a time, from summaries only', async () => {
    const brain = new StoryFakeBrain()
    const seen: string[] = []
    const watching: Brain = { chat: (request) => { seen.push(request.messages.map(message => message.content).join('\n')); return brain.chat(request) } }
    const { writer, script, progress } = setup('auto', watching)
    await writer.start({ brief: 'A 12-minute drama', minutes: 12 })

    expect(script().scenes).toHaveLength(10)
    expect(progress().filter(text => text.startsWith('Drafting'))).toHaveLength(10)
    // Drafting scene 10 sent the summaries and scene 9's summary, never another scene's lines.
    const last = seen.find(text => text.includes('Draft scene 10 of 10'))!
    expect(last).toContain('The scene before (9): Asha works on the radio in scene 9.')
    expect(last).not.toContain('screwdriver in her teeth')
    // Each call has a token cap.
    expect(last.length).toBeLessThan(4000)
  })
})

describe('Stop and Continue', () => {
  it('a stop discards the unfinished step; Continue goes on from the last completed one', async () => {
    let calls = 0
    const fake = new StoryFakeBrain()
    let stopWriter = () => {}
    const brain: Brain = {
      async *chat(request) {
        // Stop while scene 2 is being drafted.
        if (/Draft scene 2/.test(request.messages[0]!.content) && calls++ === 0) {
          yield { type: 'text', text: 'REASON: half' }
          stopWriter()
          throw new DOMException('stopped', 'AbortError')
        }
        yield* fake.chat(request)
      },
    }
    const { writer, script, ai, events } = setup('auto', brain)
    stopWriter = () => writer.abort()
    await writer.start({ brief: 'A 5-minute short', minutes: 5 })

    expect(events.some(event => event.type === 'stopped')).toBe(true)
    expect(writer.plan).toMatchObject({ state: 'stopped', stage: 5 })
    expect(writer.canContinue).toBe(true)
    // Scene 1 drafted, scene 2 not touched (the half answer was thrown away).
    expect(script().scenes[0]!.lines.length).toBeGreaterThan(0)
    expect(script().scenes[1]!.lines).toEqual([])
    const drafts = () => ai.list().filter(step => step.title.startsWith('Drafted'))
    expect(drafts().map(step => step.title)).toEqual(['Drafted Scene 1'])

    // Continue (as after a reload: a new writer from the saved plan).
    const again = new StoryWriter({ brain: fake, ai: ai.ai, store: (writer as any).options.store, mode: () => 'auto', plan: ai.session.plan as Partial<Plan> })
    await again.continue()
    expect(again.plan!.state).toBe('done')
    expect(drafts().map(step => step.title)).toEqual(['Drafted Scene 1', 'Drafted Scene 2', 'Drafted Scene 3', 'Drafted Scene 4'])
    expect(script().scenes.every(scene => scene.lines.length > 0)).toBe(true)
  })
})

describe('ask_user', () => {
  it('waits for the answer, then resumes the stage with it', async () => {
    const fake = new StoryFakeBrain()
    let asked = false
    const brain: Brain = {
      async *chat(request) {
        if (!asked && /Stage: Brief/.test(request.messages[0]!.content)) {
          asked = true
          yield { type: 'tool_call', call: { id: 'q', name: 'ask_user', args: { question: 'Should it be funny or sad?' } } }
          return
        }
        if (/Stage: Brief/.test(request.messages[0]!.content)) expect(request.messages.map(message => message.content).join()).toContain('The user answered: Bittersweet')
        yield* fake.chat(request)
      },
    }
    const { writer, ai, events, story } = setup('auto', brain)
    await writer.start({ brief: 'Something about radios', minutes: 2 })

    expect(ai.session.status).toBe('waiting_user')
    expect(writer.plan).toMatchObject({ state: 'waiting', question: 'Should it be funny or sad?' })
    expect(events.some(event => event.type === 'question' && event.text === 'Should it be funny or sad?')).toBe(true)
    expect(story().genre).toBeUndefined()

    await writer.answer('Bittersweet')
    expect(ai.session.status).toBe('active')
    expect(writer.plan!.state).toBe('done')
    expect(story().genre).toBe('Drama')
  })
})

describe('names', () => {
  it('adds an unknown speaker as a character in a visible step first, and links every line', async () => {
    const fake = new StoryFakeBrain()
    const brain: Brain = {
      async *chat(request) {
        if (/Draft scene 1 /.test(request.messages[0]!.content)) {
          yield { type: 'text', text: 'REASON: A stranger helps\nSUMMARY: Ravi sells Asha a valve.\nACTION: A stall of old parts.\nRAVI: Last one in Surat.\nASHA: I\'ll take it.\nACTION: She pays with coins.\nRAVI: Good luck.\nASHA: Thank you.' }
          return
        }
        yield* fake.chat(request)
      },
    }
    const { writer, ai, story, script } = setup('auto', brain)
    await writer.start({ brief: 'Radios', minutes: 2 })

    const titles = ai.list().map(step => step.title)
    expect(titles.indexOf('Added RAVI')).toBe(titles.indexOf('Drafted Scene 1') - 1)
    expect(ai.list().find(step => step.title === 'Added RAVI')!.reason).toBe('RAVI speaks in Scene 1 but isn\'t in the story yet')
    const ravi = story().characters!.find(character => character.name === 'RAVI')!
    const lines = script().scenes[0]!.lines
    expect(lines.filter(line => line.type === 'dialogue' && line.character_id === ravi.id)).toHaveLength(2)
    expect(checkScript(story(), script(), target(2), 'en').find(check => check.label === 'Known speakers')!.ok).toBe(true)
  })

  it('reads the draft format in any language', () => {
    expect(parseDraft('REASON: r\nSUMMARY: s\nACTION: आशा रेडियो खोलती है।\nआशा (धीरे से): बस एक वाल्व।\n**DADA**: ઠીક છે.\nINT. SHOP - DAY\n- extra words').lines).toEqual([
      { type: 'action', text: 'आशा रेडियो खोलती है।' },
      { type: 'character', text: 'आशा' },
      { type: 'parenthetical', text: '(धीरे से)' },
      { type: 'dialogue', text: 'बस एक वाल्व।' },
      { type: 'character', text: 'DADA' },
      { type: 'dialogue', text: 'ઠીક છે. extra words' },
    ])
  })
})

describe('bad answers', () => {
  it('invalid tool args get one repair; still invalid, the step fails and nothing is written', async () => {
    const bad: [string, Record<string, unknown>] = ['story.setBrief', { title: '', genre: 'Drama', reason: 'x' }]
    const brain = scripted([{ calls: [bad] }, { calls: [bad] }])
    const { writer, ai, story, versions } = setup('auto', brain)
    await writer.start({ brief: 'Radios', minutes: 2 })

    expect(brain.requests).toHaveLength(2)
    const repair = brain.requests[1]!.messages.at(-1)!.content
    expect(repair).toMatch(/^Problems with your tool calls:\n- story_setBrief: /)
    for (const problem of ['tone is required', 'audience is required', 'title must have at least 1 character(s)']) expect(repair).toContain(problem)
    const [step] = ai.list()
    expect(step).toMatchObject({ title: 'Couldn\'t finish: Brief', status: 'failed' })
    expect(writer.plan!.state).toBe('failed')
    expect(story().title).toBe('Radio')
    expect(versions.story).toHaveLength(1)

    // Retry with a model that answers well: the stage is done, and Continue finishes.
    ;(writer as any).options.brain = new StoryFakeBrain()
    await writer.retry(step!)
    expect(ai.steps.get(step!.id)!.status).toBe('done')
    expect(story().genre).toBe('Drama')
    await writer.continue()
    expect(writer.plan!.state).toBe('done')
  })

  it('a repaired answer is used', async () => {
    const brain = scripted([
      { calls: [['story.setBrief', { title: 'X', reason: 'r' }]] },
      { calls: [['story.setBrief', { title: 'X', genre: 'Comedy', tone: 'Light', audience: 'Kids', reason: 'r' }]] },
    ])
    const { writer, story } = setup('manual', brain)
    await writer.start({ brief: 'Radios', minutes: 2 })
    await writer.approveStage().catch(() => {})
    expect(story().genre).toBe('Comedy')
  })
})

describe('undo and chat', () => {
  it('undoes one AI step as a new version, and refuses when it changed again since', async () => {
    const { writer, ai, script, versions } = setup('auto')
    await writer.start({ brief: 'Radios', minutes: 2 })
    const draft1 = ai.list().find(step => step.title === 'Drafted Scene 1')!
    const polish1 = ai.list().find(step => step.title === 'Polished dialogue in Scene 1')!

    // Scene 1 was polished after it was drafted: undo the polish first.
    await expect(writer.undo(ai.steps.get(draft1.id)!)).rejects.toThrow('Undo the later changes first')
    await writer.undo(ai.steps.get(polish1.id)!)
    expect(script().scenes[0]!.lines[2]!.text).toBe('It only needs one valve, Dada.')
    await writer.undo(ai.steps.get(draft1.id)!)
    expect(script().scenes[0]!.lines).toEqual([])
    expect(versions.script.at(-1)!.message).toBe('Undid AI: Drafted Scene 1')
    expect(ai.steps.get(draft1.id)!.status).toBe('undone')
  })

  it('"make scene 1 funnier" rewrites it; "add a scene" adds and drafts one', async () => {
    const { writer, ai, script } = setup('auto')
    await writer.start({ brief: 'Radios', minutes: 2 })
    const before = script().scenes.length

    await writer.send('make scene 1 funnier')
    expect(ai.list().at(-1)).toMatchObject({ title: 'Rewrote Scene 1', status: 'done' })
    expect(script().scenes[0]!.lines.some(line => line.text.includes('tired of your news'))).toBe(true)

    await writer.send('add a scene where they meet')
    expect(script().scenes).toHaveLength(before + 1)
    expect(script().scenes.at(-1)!.lines.length).toBeGreaterThan(0)
  })
})

describe('what small models really write', () => {
  it('splits speeches written inside an action line, and drops the form\'s placeholders', () => {
    const output = [
      'REASON: r', 'SUMMARY: s',
      'ACTION: JAKE stares at the mango. JAKE: "This is yours." MAX (smiling): "No, it\'s not."',
      'NAME: JAKE',
      'NAME (how): Gruff',
    ].join('\n')
    expect(parseDraft(output, ['JAKE', 'MAX']).lines).toEqual([
      { type: 'action', text: 'JAKE stares at the mango.' },
      { type: 'character', text: 'JAKE' },
      { type: 'dialogue', text: 'This is yours.' },
      { type: 'character', text: 'MAX' },
      { type: 'parenthetical', text: '(smiling)' },
      { type: 'dialogue', text: 'No, it\'s not.' },
    ])
  })
})
