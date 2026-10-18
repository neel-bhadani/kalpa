// The assistant loop: a user message goes to the brain; its text streams back and each tool call
// becomes a visible step that runs a module command on the task's checkpoint — the same command
// the editor's own buttons use. What happens next depends on the mode:
//   auto   — steps run at once, one after another
//   semi   — steps wait for the user's Approve (awaiting_review)
//   manual — the assistant only answers; tool calls are suggestions (planned) to approve one by one
// Every step keeps the values it touched before and after, so it can be undone. Steps and messages
// are recorded on the server (AiApi), which also sends them to the user's other devices.

import type { ModuleCommand } from '../../core/src/modules'
import { isAbortError, type Brain, type ChatMessage, type ToolCall } from './brain'
import { validateArgs } from './validate'

export type Mode = 'auto' | 'semi' | 'manual'

export type StepStatus = 'planned' | 'awaiting_review' | 'running' | 'done' | 'rejected' | 'undone' | 'failed'

export type Step = {
  id: string
  seq: number
  title: string
  tool: string
  args: Record<string, unknown>
  status: StepStatus
  // Only the values the step changed, by path (e.g. { "data.notes": "..." }).
  before?: Record<string, unknown> | null
  after?: Record<string, unknown> | null
  error?: string | null
}

export interface AiApi {
  addMessage(role: 'user' | 'assistant', content: Record<string, unknown>): Promise<unknown>
  addStep(step: { title: string; tool: string; args: Record<string, unknown>; status: StepStatus }): Promise<Step>
  updateStep(id: string, patch: { status: StepStatus; before?: unknown; after?: unknown; error?: string | null }): Promise<Step>
  approve(id: string): Promise<Step>
  reject(id: string): Promise<Step>
  undo(id: string): Promise<Step>
  retry(id: string): Promise<Step>
}

// The task's checkpoint, as the editor holds it. set() is how a step's change shows up in the editor.
export interface CheckpointPort {
  get(): Record<string, any>
  set(next: Record<string, any>, change: { tool: string; args: Record<string, unknown> }): Promise<void> | void
}

export type RunnerEvent =
  | { type: 'thinking'; text: string }
  | { type: 'reply'; text: string }
  | { type: 'step'; step: Step }
  | { type: 'stopped' }
  | { type: 'usage'; tokens: number; seconds: number }

export type RunnerOptions = {
  brain: Brain
  commands: Record<string, ModuleCommand>
  api: AiApi
  checkpoint: CheckpointPort
  mode: () => Mode
  // Instructions sent before the conversation (not stored as a message).
  system?: string
  onEvent?: (event: RunnerEvent) => void
  // A pause between Auto steps, so each one can be seen happening.
  stepDelayMs?: number
}

export class AssistantRunner {
  private history: ChatMessage[] = []
  private controller: AbortController | null = null

  constructor(private options: RunnerOptions) {}

  // Earlier messages of the session, so the brain sees the whole conversation.
  setHistory(messages: ChatMessage[]): void {
    this.history = [...messages]
  }

  get busy(): boolean {
    return this.controller !== null
  }

  // Stop the brain (and any Auto steps not started yet).
  abort(): void {
    this.controller?.abort()
  }

  // Send a user message; returns the steps the reply created.
  async send(text: string): Promise<Step[]> {
    const controller = new AbortController()
    this.controller = controller
    const { api, brain, onEvent } = this.options

    try {
      this.history.push({ role: 'user', content: text })
      await api.addMessage('user', { text })

      let reply = ''
      const calls: ToolCall[] = []
      const system: ChatMessage[] = this.options.system ? [{ role: 'system', content: this.options.system }] : []
      for await (const chunk of brain.chat({ messages: [...system, ...this.history], tools: this.tools(), signal: controller.signal })) {
        if (chunk.type === 'text') {
          reply += chunk.text
          onEvent?.({ type: 'thinking', text: reply })
        } else if (chunk.type === 'tool_call') {
          calls.push(chunk.call)
        } else {
          onEvent?.(chunk)
        }
      }

      this.history.push({ role: 'assistant', content: reply })
      await api.addMessage('assistant', { text: reply, tool_calls: calls })
      onEvent?.({ type: 'reply', text: reply })

      const mode = this.options.mode()
      const steps: Step[] = []

      if (mode === 'auto') {
        // One at a time: each step is created and run before the next one appears.
        for (const [i, call] of calls.entries()) {
          if (controller.signal.aborted) break
          if (i > 0 && this.options.stepDelayMs) await new Promise(resolve => setTimeout(resolve, this.options.stepDelayMs))
          const step = await this.track(await api.addStep({ title: describe(call), tool: call.name, args: call.args, status: 'running' }))
          steps.push(await this.run(step))
        }
        return steps
      }

      const initial: StepStatus = mode === 'semi' ? 'awaiting_review' : 'planned'
      for (const call of calls) {
        steps.push(await this.track(await api.addStep({ title: describe(call), tool: call.name, args: call.args, status: initial })))
      }

      return steps
    } catch (error) {
      if (isAbortError(error)) {
        onEvent?.({ type: 'stopped' })
        return []
      }
      throw error
    } finally {
      this.controller = null
    }
  }

  // Approve a suggested or awaiting step: it runs now.
  async approve(step: Step): Promise<Step> {
    return this.run(await this.track(await this.options.api.approve(step.id)))
  }

  async reject(step: Step): Promise<Step> {
    return this.track(await this.options.api.reject(step.id))
  }

  // Put back what the step changed. Refused if those values changed again since.
  async undo(step: Step): Promise<Step> {
    if (step.status !== 'done' || !step.before || !step.after) throw new Error('Only a finished step can be undone.')

    const current = this.options.checkpoint.get()
    for (const [path, value] of Object.entries(step.after)) {
      if (!same(getPath(current, path), value)) {
        throw new Error('This was changed again after the step. Undo the later changes first.')
      }
    }

    let restored = current
    for (const [path, value] of Object.entries(step.before)) restored = setPath(restored, path, value)
    await this.options.checkpoint.set(restored, { tool: 'undo', args: { step: step.seq } })

    return this.track(await this.options.api.undo(step.id))
  }

  async retry(step: Step): Promise<Step> {
    return this.run(await this.track(await this.options.api.retry(step.id)))
  }

  // Run a step that is "running": check its arguments, apply the command, record before/after.
  private async run(step: Step): Promise<Step> {
    const { api, checkpoint, commands } = this.options
    const command = commands[step.tool]

    if (!command) {
      return this.track(await api.updateStep(step.id, { status: 'failed', error: `There is no "${step.tool}" command here.` }))
    }

    const problems = validateArgs(command.schema, step.args)
    if (problems.length) {
      return this.track(await api.updateStep(step.id, { status: 'failed', error: `Bad arguments for ${step.tool}: ${problems.join('; ')}.` }))
    }

    try {
      const before = checkpoint.get()
      const after = command.apply(before, step.args)
      const paths = changedPaths(before, after)
      await checkpoint.set(after, { tool: step.tool, args: step.args })

      return this.track(await api.updateStep(step.id, {
        status: 'done',
        before: Object.fromEntries(paths.map(path => [path, getPath(before, path) ?? null])),
        after: Object.fromEntries(paths.map(path => [path, getPath(after, path) ?? null])),
      }))
    } catch (error) {
      return this.track(await api.updateStep(step.id, { status: 'failed', error: error instanceof Error ? error.message : String(error) }))
    }
  }

  private tools() {
    return Object.entries(this.options.commands).map(([name, command]) => ({ name, description: command.description, parameters: command.schema }))
  }

  private track(step: Step): Step {
    this.options.onEvent?.({ type: 'step', step })
    return step
  }
}

// A short title for a step, e.g. 'Open the "details" tab' or 'Add to notes: "Scene 1: …"'.
export function describe(call: Pick<ToolCall, 'name' | 'args'>): string {
  const text = String(call.args.text ?? '')
  const short = text.length > 48 ? `${text.slice(0, 47)}…` : text
  switch (call.name) {
    case 'view.selectTab': return `Open the "${String(call.args.tab)}" tab`
    case 'notes.append': return `Add to notes: "${short}"`
    case 'notes.replace': return `Rewrite the notes: "${short}"`
    default: return call.name
  }
}

// Leaf paths ("data.notes", "view.tab") whose values differ.
export function changedPaths(before: unknown, after: unknown, prefix = ''): string[] {
  if (isPlainObject(before) && isPlainObject(after)) {
    const keys = new Set([...Object.keys(before), ...Object.keys(after)])
    return [...keys].flatMap(key => changedPaths(before[key], after[key], prefix ? `${prefix}.${key}` : key))
  }
  if (isPlainObject(after) && before === undefined) return changedPaths({}, after, prefix)
  return same(before, after) ? [] : [prefix]
}

export function getPath(value: any, path: string): unknown {
  return path.split('.').reduce((node, key) => (node == null ? undefined : node[key]), value)
}

export function setPath(value: Record<string, any>, path: string, leaf: unknown): Record<string, any> {
  const [key, ...rest] = path.split('.')
  if (!rest.length) return { ...value, [key!]: leaf }
  return { ...value, [key!]: setPath(isPlainObject(value[key!]) ? value[key!] : {}, rest.join('.'), leaf) }
}

function isPlainObject(value: unknown): value is Record<string, any> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function same(a: unknown, b: unknown): boolean {
  return JSON.stringify(a ?? null) === JSON.stringify(b ?? null)
}
