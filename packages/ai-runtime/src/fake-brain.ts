// A scripted brain for development and tests: no model, always the same answer, so the whole loop
// (streaming, tool calls, steps, review, undo) can be built and checked before real models (P1).

import { abortError, type Brain, type BrainChunk, type ChatRequest } from './brain'

export type FakeBrainOptions = {
  // Pause between streamed words, so "thinking" is visible. 0 in tests.
  chunkDelayMs?: number
}

export class FakeBrain implements Brain {
  constructor(private options: FakeBrainOptions = {}) {}

  async *chat({ messages, signal }: ChatRequest): AsyncIterable<BrainChunk> {
    const last = [...messages].reverse().find(message => message.role === 'user')?.content ?? ''
    const demo = /\bdemo\b/i.test(last)

    const reply = demo
      ? 'Here is a quick start: I will open the details, then add two scenes to your notes.'
      : 'I am the test assistant. Type "demo" to watch me edit this task.'

    for (const word of reply.split(/(?<= )/)) {
      await this.pause(signal)
      yield { type: 'text', text: word }
    }

    if (!demo) return

    const calls = [
      { name: 'view.selectTab', args: { tab: 'details' } },
      { name: 'notes.append', args: { text: 'Scene 1: The lanterns of the night market flicker on, one by one.' } },
      { name: 'notes.append', args: { text: 'Scene 2: A cat darts between the stalls, chasing the last light.' } },
    ]
    for (const [i, call] of calls.entries()) {
      await this.pause(signal)
      yield { type: 'tool_call', call: { id: `call_${i + 1}`, ...call } }
    }
  }

  private async pause(signal?: AbortSignal): Promise<void> {
    if (signal?.aborted) throw abortError()
    const ms = this.options.chunkDelayMs ?? 30
    if (ms <= 0) return
    await new Promise<void>((resolve, reject) => {
      const timer = setTimeout(resolve, ms)
      signal?.addEventListener('abort', () => {
        clearTimeout(timer)
        reject(abortError())
      }, { once: true })
    })
  }
}
