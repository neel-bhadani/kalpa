import { describe, expect, it, vi } from 'vitest'
import { placeholderCommands } from '../../ui/src/placeholder-commands'
import type { Brain, BrainChunk } from './brain'
import { FakeBrain } from './fake-brain'
import { AssistantRunner, type AiApi, type Mode, type Step, type StepStatus } from './runner'

// An in-memory server that checks step moves like the real one.
function fakeApi() {
  const steps = new Map<string, Step>()
  const messages: { role: string; content: Record<string, unknown> }[] = []
  const moves: Record<StepStatus, StepStatus[]> = {
    planned: ['awaiting_review', 'running', 'rejected'],
    awaiting_review: ['running', 'rejected'],
    running: ['done', 'failed'],
    done: ['undone'],
    failed: ['running'],
    rejected: [],
    undone: [],
  }
  const move = (id: string, status: StepStatus, patch: Partial<Step> = {}) => {
    const step = steps.get(id)!
    if (!moves[step.status].includes(status)) throw new Error(`can't move ${step.status} → ${status}`)
    const next = { ...step, ...patch, status }
    steps.set(id, next)
    return next
  }
  const api: AiApi = {
    addMessage: async (role, content) => messages.push({ role, content }),
    addStep: async (step) => {
      const created: Step = { id: `step-${steps.size + 1}`, seq: steps.size + 1, ...step }
      steps.set(created.id, created)
      return created
    },
    updateStep: async (id, { status, ...patch }) => move(id, status, patch as Partial<Step>),
    approve: async id => move(id, 'running'),
    reject: async id => move(id, 'rejected'),
    undo: async id => move(id, 'undone'),
    retry: async id => move(id, 'running', { error: null }),
  }
  return { api, steps, messages }
}

function setup(mode: Mode, brain: Brain = new FakeBrain({ chunkDelayMs: 0 })) {
  const server = fakeApi()
  let checkpoint: Record<string, any> = { view: { tab: 'notes' }, data: { notes: 'Opening line' } }
  const changes: string[] = []
  const thinking: string[] = []
  const runner = new AssistantRunner({
    brain,
    commands: placeholderCommands,
    api: server.api,
    mode: () => mode,
    checkpoint: {
      get: () => checkpoint,
      set: (next, change) => {
        checkpoint = next
        changes.push(change.tool)
      },
    },
    onEvent: event => event.type === 'thinking' && thinking.push(event.text),
  })
  return { runner, server, changes, thinking, checkpoint: () => checkpoint }
}

describe('AssistantRunner with FakeBrain', () => {
  it('Auto: runs the three steps at once, in order', async () => {
    const { runner, server, changes, checkpoint } = setup('auto')

    const steps = await runner.send('show me a demo')

    expect(steps.map(step => step.status)).toEqual(['done', 'done', 'done'])
    expect(changes).toEqual(['view.selectTab', 'notes.append', 'notes.append'])
    expect(checkpoint()).toEqual({
      view: { tab: 'details' },
      data: { notes: 'Opening line\nScene 1: The lanterns of the night market flicker on, one by one.\nScene 2: A cat darts between the stalls, chasing the last light.' },
    })
    // Only what each step touched is kept.
    expect(steps[0]).toMatchObject({ before: { 'view.tab': 'notes' }, after: { 'view.tab': 'details' } })
    expect(Object.keys(steps[1]!.after!)).toEqual(['data.notes'])
    expect(server.messages.map(message => message.role)).toEqual(['user', 'assistant'])
    expect((server.messages[1]!.content.tool_calls as unknown[]).length).toBe(3)
  })

  it('Semi: steps wait for review, and each runs when approved', async () => {
    const { runner, changes, checkpoint } = setup('semi')

    const steps = await runner.send('demo')
    expect(steps.map(step => step.status)).toEqual(['awaiting_review', 'awaiting_review', 'awaiting_review'])
    expect(changes).toEqual([])

    expect((await runner.approve(steps[0]!)).status).toBe('done')
    expect(checkpoint().view.tab).toBe('details')

    expect((await runner.reject(steps[1]!)).status).toBe('rejected')
    expect((await runner.approve(steps[2]!)).status).toBe('done')
    expect(checkpoint().data.notes).toBe('Opening line\nScene 2: A cat darts between the stalls, chasing the last light.')
  })

  it('Manual: the assistant only answers; tool calls are suggestions to approve one by one', async () => {
    const { runner, changes } = setup('manual')

    const steps = await runner.send('demo')

    expect(steps.map(step => step.status)).toEqual(['planned', 'planned', 'planned'])
    expect(changes).toEqual([])
    expect((await runner.approve(steps[1]!)).status).toBe('done')
    expect(changes).toEqual(['notes.append'])
  })

  it('answers without tool calls when not asked for the demo', async () => {
    const { runner, server } = setup('auto')
    expect(await runner.send('hello')).toEqual([])
    expect(server.messages[1]!.content.text).toContain('Type "demo"')
  })
})

describe('undo', () => {
  it('puts back exactly what the step changed', async () => {
    const { runner, checkpoint } = setup('auto')
    const steps = await runner.send('demo')

    const undone = await runner.undo(steps[2]!)

    expect(undone.status).toBe('undone')
    expect(checkpoint().data.notes).toBe('Opening line\nScene 1: The lanterns of the night market flicker on, one by one.')
    // The tab (another step's change) stays.
    expect(checkpoint().view.tab).toBe('details')
  })

  it('refuses when the same values changed again since', async () => {
    const { runner } = setup('auto')
    const steps = await runner.send('demo')

    // Step 2's notes were changed by step 3 afterwards.
    await expect(runner.undo(steps[1]!)).rejects.toThrow('Undo the later changes first')
    await runner.undo(steps[2]!)
    expect((await runner.undo(steps[1]!)).status).toBe('undone')
  })
})

describe('bad tool calls', () => {
  // A brain that asks for things the commands don't accept.
  const badBrain: Brain = {
    async *chat(): AsyncIterable<BrainChunk> {
      yield { type: 'text', text: 'Trying.' }
      yield { type: 'tool_call', call: { id: '1', name: 'view.selectTab', args: { tab: 'timeline' } } }
      yield { type: 'tool_call', call: { id: '2', name: 'notes.append', args: {} } }
      yield { type: 'tool_call', call: { id: '3', name: 'render.everything', args: {} } }
    },
  }

  it('fail their step with a clear error, and can be retried', async () => {
    const { runner, checkpoint } = setup('auto', badBrain)

    const steps = await runner.send('go')

    expect(steps.map(step => [step.status, step.error])).toEqual([
      ['failed', 'Bad arguments for view.selectTab: tab must be one of: notes, details.'],
      ['failed', 'Bad arguments for notes.append: text is required.'],
      ['failed', 'There is no "render.everything" command here.'],
    ])
    expect(checkpoint().view.tab).toBe('notes')

    // Retrying runs it again (and fails again: the arguments haven't changed).
    expect((await runner.retry(steps[0]!)).status).toBe('failed')
  })
})

describe('streaming', () => {
  it('assembles the reply from the streamed chunks', async () => {
    const chunks = ['Here ', 'is ', 'the ', 'plan.']
    const brain: Brain = { async *chat() { for (const text of chunks) yield { type: 'text', text } } }
    const { runner, thinking, server } = setup('auto', brain)

    await runner.send('hi')

    expect(thinking).toEqual(['Here ', 'Here is ', 'Here is the ', 'Here is the plan.'])
    expect(server.messages[1]!.content.text).toBe('Here is the plan.')
  })

  it('stops when aborted: no reply, no steps', async () => {
    vi.useFakeTimers()
    const { runner, server, changes } = setup('auto', new FakeBrain({ chunkDelayMs: 50 }))

    const run = runner.send('demo')
    await vi.advanceTimersByTimeAsync(120)
    runner.abort()
    await vi.advanceTimersByTimeAsync(1000)

    expect(await run).toEqual([])
    expect(server.messages.map(message => message.role)).toEqual(['user'])
    expect(server.steps.size).toBe(0)
    expect(changes).toEqual([])
    expect(runner.busy).toBe(false)
    vi.useRealTimers()
  })
})
