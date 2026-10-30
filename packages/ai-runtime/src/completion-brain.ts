// Chat completions in the OpenAI shape, which all three real brains speak: an OpenAI-compatible
// server (Ollama, the llama.cpp server, a provider), wllama (llama.cpp in WASM) and WebLLM.
// CompletionBrain turns their streamed chunks into BrainChunks. Tool calls are native where the
// engine supports them (it parses them itself); otherwise the model writes them in the JSON text
// format (tool-format.ts), which is checked against the tool schemas with one repair retry.

import type { JsonSchema } from '../../core/src/modules'
import { abortError, type Brain, type BrainChunk, type ChatMessage, type ChatRequest, type ToolSpec } from './brain'
import { parseToolCalls, repairPrompt, toolInstructions, VisibleText } from './tool-format'

export type WireTool = { type: 'function'; function: { name: string; description: string; parameters: JsonSchema } }

export type CompletionRequest = { messages: ChatMessage[]; tools?: WireTool[]; maxTokens?: number }

export type CompletionChunk = {
  choices?: {
    delta?: {
      content?: string | null
      tool_calls?: { index: number; id?: string; function?: { name?: string; arguments?: string } }[]
    }
  }[]
  usage?: { completion_tokens?: number; extra?: { decode_tokens_per_s?: number } } | null
  // llama.cpp (wllama) reports its own generation speed.
  timings?: { predicted_n: number; predicted_ms: number }
}

export type Complete = (request: CompletionRequest, signal?: AbortSignal) => Promise<AsyncIterable<CompletionChunk>>

// native: send the tools to the engine. json: the text format. auto: native, and json from then
// on if the endpoint refuses tools (it throws ToolsNotSupported).
export type ToolMode = 'native' | 'json' | 'auto'

export class ToolsNotSupported extends Error {}

export type CompletionBrainOptions = {
  // Where completions come from; subclasses override complete() instead.
  complete?: Complete
  tools?: ToolMode
  // The model's name, e.g. "qwen3:1.7b"; Qwen3 is asked not to think (much faster on a CPU).
  model?: string
  now?: () => number
}

export class CompletionBrain implements Brain {
  private mode: ToolMode
  private maxTokens: number | undefined

  constructor(private options: CompletionBrainOptions) {
    this.mode = options.tools ?? 'auto'
  }

  async *chat({ messages, tools, signal, maxTokens }: ChatRequest): AsyncIterable<BrainChunk> {
    const prepared = noThinking(messages, this.options.model)
    this.maxTokens = maxTokens

    if (tools.length && this.mode !== 'json') {
      try {
        yield* this.native(prepared, tools, signal)
        return
      } catch (error) {
        if (!(error instanceof ToolsNotSupported) || this.mode === 'native') throw error
        this.mode = 'json'
      }
    }

    yield* this.jsonTools(prepared, tools, signal)
  }

  protected complete(request: CompletionRequest, signal?: AbortSignal): Promise<AsyncIterable<CompletionChunk>> {
    if (!this.options.complete) throw new Error('This brain has no model to ask.')
    return this.options.complete(request, signal)
  }

  // The engine parses tool calls. Tool names travel as "notes_append" (OpenAI allows no dots).
  private async *native(messages: ChatMessage[], tools: ToolSpec[], signal?: AbortSignal): AsyncGenerator<BrainChunk> {
    const names = new Map(tools.map(tool => [wireName(tool.name), tool.name]))
    const wire: WireTool[] = tools.map(tool => ({ type: 'function', function: { name: wireName(tool.name), description: tool.description, parameters: tool.parameters } }))

    const visible = new VisibleText()
    const meter = new Meter(this.options.now)
    const pending = new Map<number, { id?: string; name: string; args: string }>()

    for await (const chunk of await this.complete({ messages, tools: wire, maxTokens: this.maxTokens }, signal)) {
      if (signal?.aborted) throw abortError()
      meter.read(chunk)
      const delta = chunk.choices?.[0]?.delta
      if (delta?.content) {
        const text = visible.push(delta.content)
        if (text) yield { type: 'text', text }
      }
      for (const part of delta?.tool_calls ?? []) {
        const call = pending.get(part.index) ?? { name: '', args: '' }
        call.id ||= part.id
        call.name ||= part.function?.name ?? ''
        call.args += part.function?.arguments ?? ''
        pending.set(part.index, call)
      }
    }

    yield* meter.usage()
    for (const [i, call] of [...pending.values()].entries()) {
      yield { type: 'tool_call', call: { id: call.id || `call_${i + 1}`, name: names.get(call.name) ?? call.name, args: parseArgs(call.args) } }
    }
    // A small model may still write its calls as text instead.
    for (const call of parseToolCalls(visible.raw, tools).calls) yield { type: 'tool_call', call }
  }

  // The model writes tool calls in its text; bad ones get one chance to be fixed.
  private async *jsonTools(messages: ChatMessage[], tools: ToolSpec[], signal?: AbortSignal): AsyncGenerator<BrainChunk> {
    const prompt = tools.length ? withSystem(messages, toolInstructions(tools)) : messages
    const output = yield* this.text(prompt, signal, true)
    let parsed = parseToolCalls(output, tools)

    if (parsed.problems.length) {
      const fix = yield* this.text([...prompt, { role: 'assistant', content: output }, { role: 'user', content: repairPrompt(parsed.problems) }], signal, false)
      const repaired = parseToolCalls(fix, tools, 'fix')
      if (repaired.calls.length && repaired.problems.length < parsed.problems.length) parsed = repaired
    }

    for (const call of parsed.calls) yield { type: 'tool_call', call }
  }

  // One completion without tools; streams the visible text if show, returns the whole output.
  private async *text(messages: ChatMessage[], signal: AbortSignal | undefined, show: boolean): AsyncGenerator<BrainChunk, string> {
    const visible = new VisibleText()
    const meter = new Meter(this.options.now)
    for await (const chunk of await this.complete({ messages, maxTokens: this.maxTokens }, signal)) {
      if (signal?.aborted) throw abortError()
      meter.read(chunk)
      const content = chunk.choices?.[0]?.delta?.content
      if (!content) continue
      const text = visible.push(content)
      if (show && text) yield { type: 'text', text }
    }
    yield* meter.usage()
    return visible.raw
  }
}

// Server-sent events (an OpenAI-style stream): the data of each event, until [DONE].
export async function* readSse(body: ReadableStream<Uint8Array>): AsyncIterable<string> {
  const reader = body.getReader()
  const decoder = new TextDecoder()
  let buffer = ''
  try {
    while (true) {
      const { value, done } = await reader.read()
      buffer += decoder.decode(value, { stream: !done })
      const events = buffer.split(/\r?\n\r?\n/)
      buffer = done ? '' : events.pop()!
      for (const event of events) {
        const data = event.split(/\r?\n/).filter(line => line.startsWith('data:')).map(line => line.slice(5).trimStart()).join('\n')
        if (data === '[DONE]') return
        if (data) yield data
      }
      if (done) return
    }
  } finally {
    await reader.cancel().catch(() => {})
  }
}

// Counts generated tokens and their time, from the first token to the last.
class Meter {
  private tokens = 0
  private first: number | null = null
  private last = 0
  private reported: { tokens: number; seconds: number } | null = null

  constructor(private now: () => number = () => performance.now()) {}

  read(chunk: CompletionChunk): void {
    const delta = chunk.choices?.[0]?.delta
    if (delta?.content || delta?.tool_calls?.length) {
      this.last = this.now()
      this.first ??= this.last
      this.tokens++
    }
    if (chunk.timings?.predicted_n) {
      this.reported = { tokens: chunk.timings.predicted_n, seconds: chunk.timings.predicted_ms / 1000 }
    } else if (chunk.usage?.completion_tokens && chunk.usage.extra?.decode_tokens_per_s) {
      this.reported = { tokens: chunk.usage.completion_tokens, seconds: chunk.usage.completion_tokens / chunk.usage.extra.decode_tokens_per_s }
    } else if (chunk.usage?.completion_tokens) {
      this.tokens = chunk.usage.completion_tokens
    }
  }

  *usage(): Generator<BrainChunk> {
    if (this.reported) yield { type: 'usage', ...this.reported }
    else if (this.first !== null && this.tokens > 1) yield { type: 'usage', tokens: this.tokens, seconds: (this.last - this.first) / 1000 }
  }
}

// A tool's name as engines take it: OpenAI-style names allow no dots ("notes.append" → "notes_append").
// Prompts that name a tool should use this form, or a model may call a name the engine doesn't know.
export function wireName(name: string): string {
  return name.replace(/[^\w-]/g, '_')
}

export function tokensPerSecond(usage: { tokens: number; seconds: number }): number {
  return usage.seconds > 0 ? usage.tokens / usage.seconds : 0
}

function parseArgs(text: string): Record<string, unknown> {
  try {
    const value = JSON.parse(text || '{}')
    return typeof value === 'object' && value !== null && !Array.isArray(value) ? value : {}
  } catch {
    return {}
  }
}

function withSystem(messages: ChatMessage[], extra: string): ChatMessage[] {
  const [first, ...rest] = messages
  if (first?.role === 'system') return [{ role: 'system', content: `${first.content}\n\n${extra}` }, ...rest]
  return [{ role: 'system', content: extra }, ...messages]
}

// Qwen3 thinks before answering unless told "/no_think"; on a CPU that's many seconds per reply.
function noThinking(messages: ChatMessage[], model?: string): ChatMessage[] {
  return model && /qwen3/i.test(model) ? withSystem(messages, '/no_think') : messages
}

