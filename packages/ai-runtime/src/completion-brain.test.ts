import { describe, expect, it } from 'vitest'
import { placeholderCommands } from '../../ui/src/placeholder-commands'
import type { BrainChunk, ChatRequest, ToolSpec } from './brain'
import { CompletionBrain, readSse, ToolsNotSupported, type CompletionChunk, type CompletionRequest } from './completion-brain'
import { OpenAICompatibleBrain } from './openai-compatible-brain'
import { parseToolCalls, VisibleText } from './tool-format'

const tools: ToolSpec[] = Object.entries(placeholderCommands).map(([name, command]) => ({ name, description: command.description, parameters: command.schema }))

const request = (text = 'demo'): ChatRequest => ({ messages: [{ role: 'user', content: text }], tools })

async function collect(chunks: AsyncIterable<BrainChunk>) {
  const all: BrainChunk[] = []
  for await (const chunk of chunks) all.push(chunk)
  return {
    text: all.flatMap(chunk => (chunk.type === 'text' ? [chunk.text] : [])).join(''),
    calls: all.flatMap(chunk => (chunk.type === 'tool_call' ? [chunk.call] : [])),
    usage: all.find(chunk => chunk.type === 'usage'),
  }
}

// A model that answers each request with the next scripted text, split into small pieces.
function scripted(replies: string[]) {
  const requests: CompletionRequest[] = []
  const complete = async (req: CompletionRequest) => {
    requests.push(req)
    const reply = replies.shift() ?? ''
    return (async function* (): AsyncIterable<CompletionChunk> {
      for (const piece of reply.match(/[\s\S]{1,7}/g) ?? []) yield { choices: [{ delta: { content: piece } }] }
    })()
  }
  return { complete, requests }
}

describe('parseToolCalls', () => {
  it('reads tool calls and the reply around them', () => {
    const parsed = parseToolCalls(
      '<think>\nok\n</think>\n\nAdding a scene.\n<tool_call>\n{"name": "notes.append", "arguments": {"text": "Scene 1"}}\n</tool_call>\n'
      + '<tool_call>{"name": "view.selectTab", "arguments": "{\\"tab\\": \\"details\\"}"}</tool_call>',
      tools,
    )
    expect(parsed).toEqual({
      text: 'Adding a scene.',
      calls: [
        { id: 'call_1', name: 'notes.append', args: { text: 'Scene 1' } },
        { id: 'call_2', name: 'view.selectTab', args: { tab: 'details' } },
      ],
      problems: [],
    })
  })

  it('accepts a fenced JSON call and an unclosed last tag', () => {
    expect(parseToolCalls('Sure.\n```json\n{"name": "notes.append", "arguments": {"text": "A"}}\n```', tools).calls).toEqual([{ id: 'call_1', name: 'notes.append', args: { text: 'A' } }])
    expect(parseToolCalls('<tool_call>{"name": "notes.append", "arguments": {"text": "B"}}', tools).calls[0]!.args).toEqual({ text: 'B' })
  })

  it('lists every problem', () => {
    const parsed = parseToolCalls(
      '<tool_call>{"name": "notes.append", "arguments": {}}</tool_call>'
      + '<tool_call>{"name": "render.all", "arguments": {}}</tool_call>'
      + '<tool_call>{"name": "notes.append", "arguments": {"text": "x",}}</tool_call>'
      + '<tool_call>{"arguments": {}}</tool_call>'
      + '<tool_call>{"name": "view.selectTab", "arguments": {"tab": "timeline"}}</tool_call>',
      tools,
    )
    expect(parsed.problems).toEqual([
      'notes.append: text is required.',
      'There is no "render.all" tool. Use one of: notes.append, notes.replace, view.selectTab.',
      'Tool call 3 is not valid JSON.',
      'Tool call 4 has no "name".',
      'view.selectTab: tab must be one of: notes, details.',
    ])
    // Readable calls are kept, so the step shows its error.
    expect(parsed.calls.map(call => call.name)).toEqual(['notes.append', 'render.all', 'view.selectTab'])
  })
})

describe('VisibleText', () => {
  it('streams the reply without thinking or tool calls, holding back a half tag', () => {
    const visible = new VisibleText()
    const shown = ['<thi', 'nk>hmm</think>\n\nHello', ' there <', 'tool_', 'call>{"name"', '}</tool_call>'].map(piece => visible.push(piece))
    expect(shown).toEqual(['', 'Hello', ' there', '', '', ''])
    expect(new VisibleText().push('a < b')).toBe('a < b')
  })
})

describe('CompletionBrain with JSON tool calls', () => {
  it('streams the reply and returns valid calls, asking Qwen3 not to think', async () => {
    const model = scripted(['Opening the details.\n<tool_call>{"name": "view.selectTab", "arguments": {"tab": "details"}}</tool_call>'])
    const brain = new CompletionBrain({ complete: model.complete, tools: 'json', model: 'qwen3-0.6b' })

    const result = await collect(brain.chat(request()))
    expect(result.text).toBe('Opening the details.')
    expect(result.calls).toEqual([{ id: 'call_1', name: 'view.selectTab', args: { tab: 'details' } }])
    expect(model.requests).toHaveLength(1)
    expect(model.requests[0]!.tools).toBeUndefined()
    expect(model.requests[0]!.messages[0]!.role).toBe('system')
    expect(model.requests[0]!.messages[0]!.content).toContain('"name":"notes.append"')
    expect(model.requests[0]!.messages[0]!.content).toContain('/no_think')
  })

  it('repairs bad calls once', async () => {
    const model = scripted([
      'Adding it.\n<tool_call>{"name": "notes.append", "arguments": {"txt": "Scene 1"}}</tool_call>',
      '<tool_call>{"name": "notes.append", "arguments": {"text": "Scene 1"}}</tool_call>',
    ])
    const result = await collect(new CompletionBrain({ complete: model.complete, tools: 'json' }).chat(request()))

    expect(result.text).toBe('Adding it.')
    expect(result.calls).toEqual([{ id: 'fix_1', name: 'notes.append', args: { text: 'Scene 1' } }])
    expect(model.requests).toHaveLength(2)
    expect(model.requests[1]!.messages.at(-1)!.content).toContain('- notes.append: text is required; txt is not allowed.')
  })

  it('keeps the first calls if the repair is no better, and retries only once', async () => {
    const model = scripted([
      '<tool_call>{"name": "notes.append", "arguments": {}}</tool_call>',
      'Sorry!',
      'never asked',
    ])
    const result = await collect(new CompletionBrain({ complete: model.complete, tools: 'json' }).chat(request()))
    expect(result.calls).toEqual([{ id: 'call_1', name: 'notes.append', args: {} }])
    expect(model.requests).toHaveLength(2)
  })

  it('does not retry a plain answer', async () => {
    const model = scripted(['Hello! How can I help?'])
    const result = await collect(new CompletionBrain({ complete: model.complete, tools: 'json' }).chat(request('hi')))
    expect(result).toMatchObject({ text: 'Hello! How can I help?', calls: [] })
    expect(model.requests).toHaveLength(1)
  })
})

describe('CompletionBrain with native tool calls', () => {
  it('joins streamed call pieces and maps names back', async () => {
    const requests: CompletionRequest[] = []
    let clock = 0
    const brain = new CompletionBrain({
      tools: 'native',
      now: () => (clock += 250),
      complete: async (req) => {
        requests.push(req)
        return (async function* (): AsyncIterable<CompletionChunk> {
          yield { choices: [{ delta: { content: 'On it.' } }] }
          yield { choices: [{ delta: { tool_calls: [{ index: 0, id: 'a', function: { name: 'notes_append', arguments: '{"te' } }] } }] }
          yield { choices: [{ delta: { tool_calls: [{ index: 0, function: { arguments: 'xt": "Scene 1"}' } }] } }] }
          yield { choices: [], usage: { completion_tokens: 12 } }
        })()
      },
    })

    const result = await collect(brain.chat(request()))
    expect(requests[0]!.tools!.map(tool => tool.function.name)).toEqual(['notes_append', 'notes_replace', 'view_selectTab'])
    expect(result.text).toBe('On it.')
    expect(result.calls).toEqual([{ id: 'a', name: 'notes.append', args: { text: 'Scene 1' } }])
    expect(result.usage).toEqual({ type: 'usage', tokens: 12, seconds: 0.5 })
  })

  it('falls back to JSON calls when the endpoint refuses tools, and remembers', async () => {
    const model = scripted(['<tool_call>{"name": "view.selectTab", "arguments": {"tab": "notes"}}</tool_call>', 'Hi'])
    let refused = 0
    const brain = new CompletionBrain({
      tools: 'auto',
      complete: async (req) => {
        if (req.tools) {
          refused++
          throw new ToolsNotSupported('tools are not supported')
        }
        return model.complete(req)
      },
    })

    expect((await collect(brain.chat(request()))).calls).toEqual([{ id: 'call_1', name: 'view.selectTab', args: { tab: 'notes' } }])
    await collect(brain.chat(request('hi')))
    expect(refused).toBe(1)
  })
})

function sse(events: unknown[]): Response {
  const text = events.map(event => `data: ${typeof event === 'string' ? event : JSON.stringify(event)}\n\n`).join('')
  // Split awkwardly, as a network would.
  const bytes = new TextEncoder().encode(text)
  return new Response(new ReadableStream({
    start(controller) {
      for (let i = 0; i < bytes.length; i += 13) controller.enqueue(bytes.slice(i, i + 13))
      controller.close()
    },
  }), { headers: { 'Content-Type': 'text/event-stream' } })
}

describe('OpenAICompatibleBrain', () => {
  it('reads a streamed answer', async () => {
    const bodies: Record<string, unknown>[] = []
    const brain = new OpenAICompatibleBrain({
      model: 'qwen3:1.7b',
      post: async (body) => {
        bodies.push(body)
        return sse([
          { choices: [{ delta: { content: 'Hel' } }] },
          { choices: [{ delta: { content: 'lo' } }] },
          { choices: [{ delta: { tool_calls: [{ index: 0, id: 'x', function: { name: 'view_selectTab', arguments: '{"tab":"details"}' } }] } }] },
          '[DONE]',
        ])
      },
    })

    const result = await collect(brain.chat(request()))
    expect(result.text).toBe('Hello')
    expect(result.calls).toEqual([{ id: 'x', name: 'view.selectTab', args: { tab: 'details' } }])
    expect(bodies[0]).toMatchObject({ model: 'qwen3:1.7b', stream: true })
  })

  it('switches to JSON calls when the proxy reports the provider refused tools', async () => {
    const posts: Record<string, unknown>[] = []
    const brain = new OpenAICompatibleBrain({
      model: 'm',
      post: async (body) => {
        posts.push(body)
        if (body.tools) return Response.json({ message: 'The provider refused the request.', upstream_status: 400, upstream_error: 'tools not supported' }, { status: 502 })
        return sse([{ choices: [{ delta: { content: 'OK <tool_call>{"name": "notes.append", "arguments": {"text": "A"}}</tool_call>' } }] }])
      },
    })
    const result = await collect(brain.chat(request()))
    expect(result).toMatchObject({ text: 'OK', calls: [{ name: 'notes.append', args: { text: 'A' } }] })
    expect(posts.map(body => Boolean(body.tools))).toEqual([true, false])
  })

  it('explains other errors', async () => {
    const brain = new OpenAICompatibleBrain({ model: 'm', post: async () => Response.json({ error: 'model "m" not found' }, { status: 404 }) })
    await expect(collect(brain.chat(request()))).rejects.toThrow('The model server answered 404: model "m" not found')
  })
})

describe('readSse', () => {
  it('handles events split across reads and stops at [DONE]', async () => {
    const data: string[] = []
    for await (const item of readSse(sse(['{"a":1}', '{"b":2}', '[DONE]', '{"c":3}']).body!)) data.push(item)
    expect(data).toEqual(['{"a":1}', '{"b":2}'])
  })
})
