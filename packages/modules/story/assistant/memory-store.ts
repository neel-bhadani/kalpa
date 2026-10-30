// The writer's store and the session API kept in memory: for tests and the eval (no server).
// Versions and steps follow the same rules as the server (a step moves only as AiStepStatus allows).

import type { AiApi, SessionStatus, Step, StepStatus } from '../../../ai-runtime/src/runner'
import { commitBody, committed, newDraft, type Draft } from '../draft'
import { applyPatch, type Operation } from '../patch'
import { newId, type ScriptSpec, type SpecKind, type StorySpec } from '../spec'
import type { WriterStore } from './writer'

export type StoredVersion = { number: number; message: string; stepId: string | null; spec: unknown }

export function memoryStore(story: StorySpec, script: ScriptSpec | null) {
  const versions: Record<SpecKind, StoredVersion[]> = {
    story: [{ number: 1, message: 'Created', stepId: null, spec: story }],
    script: script ? [{ number: 1, message: 'Created', stepId: null, spec: script }] : [],
  }
  const drafts: Partial<Record<SpecKind, Draft<any>>> = {
    story: newDraft('story-asset', 'story', { number: 1, spec: story }),
    ...(script ? { script: newDraft('script-asset', 'script', { number: 1, spec: script }) } : {}),
  }
  const memory = { data: {} as Record<string, any>, version: 1, patches: [] as { ops: Operation[]; stepId: string | null }[] }

  const store: WriterStore = {
    draft: async kind => drafts[kind] ?? null,
    save: (kind, draft) => { drafts[kind] = draft },
    commit: async (kind, draft, message, stepId) => {
      const list = versions[kind]
      if (list.at(-1)!.number !== draft.baseVersion) throw new Error('The asset changed since.')
      const body = commitBody(draft)
      const spec = body.spec ?? applyPatch(list.at(-1)!.spec, body.patch!).doc
      list.push({ number: draft.baseVersion + 1, message, stepId, spec })
      return committed(draft, draft.baseVersion + 1, { ops: draft.ops.length, changes: draft.changes.length })
    },
    memory: async () => ({ data: memory.data, version: memory.version }),
    patchMemory: async (ops, base, stepId) => {
      if (base !== memory.version) throw new Error('The memory changed since.')
      memory.data = applyPatch(memory.data, ops).doc
      memory.version++
      memory.patches.push({ ops, stepId })
    },
  }
  return { store, versions, drafts, memory }
}

const MOVES: Record<StepStatus, StepStatus[]> = {
  planned: ['awaiting_review', 'running', 'rejected'],
  awaiting_review: ['running', 'rejected'],
  running: ['done', 'failed'],
  done: ['undone'],
  failed: ['running'],
  rejected: [],
  undone: [],
}

export function memoryAi() {
  const steps = new Map<string, Step>()
  const messages: { role: string; content: Record<string, unknown> }[] = []
  const session = { status: 'active' as SessionStatus, plan: null as Record<string, unknown> | null }

  const move = (id: string, status: StepStatus, patch: Partial<Step> = {}): Step => {
    const step = steps.get(id)!
    if (!MOVES[step.status].includes(status)) throw new Error(`A step that is ${step.status} can't become ${status}.`)
    // A copy through JSON, as the server would send it.
    const next = JSON.parse(JSON.stringify({ ...step, ...patch, status })) as Step
    steps.set(id, next)
    return next
  }

  const ai: AiApi = {
    addMessage: async (role, content) => messages.push({ role, content }),
    addStep: async (step) => {
      const created = JSON.parse(JSON.stringify({ id: newId(), seq: steps.size + 1, error: null, before: null, after: null, ...step })) as Step
      steps.set(created.id, created)
      return created
    },
    updateStep: async (id, { status, ...patch }) => move(id, status, patch as Partial<Step>),
    approve: async id => move(id, 'running'),
    reject: async id => move(id, 'rejected'),
    undo: async id => move(id, 'undone'),
    retry: async id => move(id, 'running', { error: null }),
    updateSession: async (patch) => {
      if (patch.status) session.status = patch.status
      if (patch.plan) session.plan = JSON.parse(JSON.stringify(patch.plan))
    },
  }
  return { ai, steps, messages, session, list: () => [...steps.values()].sort((a, b) => a.seq - b.seq) }
}
