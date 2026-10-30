// A module's own assistant, for work that takes many steps (e.g. the Story assistant writing a
// script stage by stage). The assistant panel hosts it in place of the plain AssistantRunner when
// the task's module has one. It records messages and steps through the same AiApi, and changes
// the task only through the module's commands.

import type { EditorApi } from '../../core/src/modules'
import type { Brain } from './brain'
import type { AiApi, CheckpointPort, Mode, Step } from './runner'

export type AssistantStage = { id: string; label: string }

// What the assistant is doing, for the panel.
export type StageState = 'waiting' | 'running' | 'review' | 'done' | 'failed'

export type ModuleAssistantEvent =
  | { type: 'thinking'; text: string }
  | { type: 'reply'; text: string }
  | { type: 'progress'; text: string | null }
  | { type: 'stage'; id: string | null; state: StageState }
  | { type: 'step'; step: Step }
  | { type: 'question'; text: string | null }
  | { type: 'plan'; plan: Record<string, unknown> }
  | { type: 'usage'; tokens: number; seconds: number }
  | { type: 'stopped' }

export type ModuleAssistantContext = {
  brain: Brain
  ai: AiApi
  api: EditorApi
  checkpoint: CheckpointPort
  task: { id: string; projectId: string; title: string; outputs: { role: string; asset: { id: string; kind: string; current_version?: { number: number } } }[] }
  mode: () => Mode
  // The session's saved plan (to continue after a stop, a reload or a crash).
  plan: Record<string, unknown> | null
  onEvent: (event: ModuleAssistantEvent) => void
}

export interface ModuleAssistant {
  readonly stages: AssistantStage[]
  readonly busy: boolean
  // Whether a plan can be continued (stopped, paused for review, or cut off by a reload).
  readonly canContinue: boolean
  // Start a job from the editor's request (e.g. { brief, minutes }).
  start(request: Record<string, unknown>): Promise<void>
  // A chat message ("make scene 3 funnier").
  send(text: string): Promise<void>
  continue(): Promise<void>
  answer(text: string): Promise<void>
  // Approve every step still waiting in the current stage, then go on.
  approveStage(): Promise<void>
  approve(step: Step): Promise<Step>
  reject(step: Step): Promise<Step>
  undo(step: Step): Promise<Step>
  retry(step: Step): Promise<Step>
  abort(): void
}

export type ModuleAssistantFactory = {
  createAssistant(context: ModuleAssistantContext): ModuleAssistant
  // A scripted brain for development and tests (stands in for FakeBrain).
  fakeBrain?: () => Brain
}

export type { EditorApi }
