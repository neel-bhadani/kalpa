// Tool calls for models that can't call tools natively: the model writes each call in its reply as
//   <tool_call>{"name": "notes.append", "arguments": {"text": "…"}}</tool_call>
// (the format Qwen3 and other Hermes-style models are trained on). parseToolCalls() reads them back
// and checks each against the tool schemas; the brain asks once for a fix (repairPrompt).

import type { ToolCall, ToolSpec } from './brain'
import { validateArgs } from './validate'

// Qwen3 can "think" first; that's never shown or parsed. An unclosed block runs to the end.
const THINK = /<think>[\s\S]*?(?:<\/think>|$)/g
const CALL = /<tool_call>([\s\S]*?)(?:<\/tool_call>|$)/g
// A fenced JSON block that is really a tool call (small models sometimes write that instead).
const FENCED_CALL = /```(?:json)?\s*(\{[\s\S]*?"name"[\s\S]*?\})\s*```/g

export function toolInstructions(tools: ToolSpec[]): string {
  const list = tools.map(tool => JSON.stringify({ name: tool.name, description: tool.description, parameters: tool.parameters }))
  return [
    'You can change the user\'s task with these tools:',
    '<tools>',
    ...list,
    '</tools>',
    'To use tools, first write a short reply for the user, then one line per call, exactly like this:',
    '<tool_call>{"name": "<tool name>", "arguments": {<arguments as JSON>}}</tool_call>',
    'The arguments must be valid JSON that matches the tool\'s parameters. Use only the tools listed.',
    'If no tool is needed, just reply.',
  ].join('\n')
}

export type ParsedReply = {
  // The reply for the user, without tool calls or thinking.
  text: string
  // Every call that could be read (a call with bad arguments is kept, so the step shows the error).
  calls: ToolCall[]
  // What was wrong, for the repair prompt. Empty if every call is valid.
  problems: string[]
}

export function parseToolCalls(output: string, tools: ToolSpec[], idPrefix = 'call'): ParsedReply {
  const clean = output.replace(THINK, '').replace(FENCED_CALL, '<tool_call>$1</tool_call>')
  const start = clean.indexOf('<tool_call>')
  const text = (start === -1 ? clean : clean.slice(0, start)).trim()

  const calls: ToolCall[] = []
  const problems: string[] = []

  for (const [n, match] of [...clean.matchAll(CALL)].entries()) {
    const label = `Tool call ${n + 1}`
    const body = match[1] ?? ''
    let value: { name?: unknown; arguments?: unknown }
    try {
      value = JSON.parse(body.slice(body.indexOf('{'), body.lastIndexOf('}') + 1))
    } catch {
      problems.push(`${label} is not valid JSON.`)
      continue
    }

    if (typeof value?.name !== 'string') {
      problems.push(`${label} has no "name".`)
      continue
    }
    // "notes_append" and "notes.append" are the same tool.
    const name = tools.find(tool => tool.name === value.name || tool.name.replace(/[^\w-]/g, '_') === value.name)?.name ?? value.name

    let args = value.arguments ?? {}
    if (typeof args === 'string') {
      try {
        args = JSON.parse(args)
      } catch {
        problems.push(`${label} (${name}): the arguments are not valid JSON.`)
        continue
      }
    }

    const record = (typeof args === 'object' && args !== null && !Array.isArray(args) ? args : {}) as Record<string, unknown>
    calls.push({ id: `${idPrefix}_${n + 1}`, name, args: record })

    const tool = tools.find(item => item.name === name)
    if (!tool) {
      problems.push(`There is no "${name}" tool. Use one of: ${tools.map(item => item.name).join(', ')}.`)
      continue
    }
    const errors = validateArgs(tool.parameters, args)
    if (errors.length) problems.push(`${name}: ${errors.join('; ')}.`)
  }

  return { text, calls, problems }
}

export function repairPrompt(problems: string[]): string {
  return [
    'Your tool calls had problems:',
    ...problems.map(problem => `- ${problem}`),
    'Write all your tool calls again, corrected, one per line as <tool_call>{"name": …, "arguments": {…}}</tool_call>, with no other text.',
  ].join('\n')
}

// What of a streamed reply can be shown so far: no thinking, nothing from the first tool call on,
// and a trailing "<tool_ca" (or space) held back until it's clear what it becomes.
export class VisibleText {
  raw = ''
  private shown = 0

  push(chunk: string): string {
    this.raw += chunk
    let text = this.raw.replace(THINK, '')
    const call = text.indexOf('<tool_call>')
    if (call !== -1) text = text.slice(0, call)
    const open = text.lastIndexOf('<')
    if (open !== -1 && ['<tool_call>', '<think>'].some(tag => tag.startsWith(text.slice(open)))) text = text.slice(0, open)
    // Spaces at the edges wait until more text follows (a reply may end where a tool call starts).
    text = text.trim()

    const fresh = text.slice(this.shown)
    this.shown = Math.max(this.shown, text.length)
    return fresh
  }
}
