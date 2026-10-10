// What a brain (a language model) does for an assistant: read the conversation and the tools it
// may use, and stream back text and tool calls.

import type { JsonSchema } from '../../core/src/modules'

export type ChatMessage = { role: 'user' | 'assistant' | 'system'; content: string }

export type ToolSpec = { name: string; description: string; parameters: JsonSchema }

export type ToolCall = { id: string; name: string; args: Record<string, unknown> }

export type BrainChunk = { type: 'text'; text: string } | { type: 'tool_call'; call: ToolCall }

export type ChatRequest = { messages: ChatMessage[]; tools: ToolSpec[]; signal?: AbortSignal }

export interface Brain {
  chat(request: ChatRequest): AsyncIterable<BrainChunk>
}

export function abortError(): DOMException {
  return new DOMException('The assistant was stopped.', 'AbortError')
}

export function isAbortError(error: unknown): boolean {
  return (error as { name?: string } | null)?.name === 'AbortError'
}
