// The writer's store in the app: drafts live in the task checkpoint (where the editor shows them),
// versions and the story bible go through the API.

import type { ModuleAssistantContext } from '../../../ai-runtime/src/module-assistant'
import { commitBody, committed, newDraft, type Draft } from '../draft'
import type { SpecKind } from '../spec'
import type { WriterStore } from './writer'

type Version = { number: number; spec?: unknown }

export function checkpointStore(context: ModuleAssistantContext): WriterStore {
  const { api, checkpoint, task } = context
  // Drafts loaded from the server before the editor saved one to the checkpoint.
  const loaded: Partial<Record<SpecKind, Draft<any>>> = {}

  return {
    async draft(kind) {
      const fromCheckpoint = checkpoint.get()[kind] as Draft<any> | undefined
      if (fromCheckpoint) return fromCheckpoint
      if (loaded[kind]) return loaded[kind]!
      const asset = task.outputs.find(output => output.asset.kind === kind)?.asset
      if (!asset?.current_version) return null
      const { data } = await api<{ data: Version }>(`/api/v1/assets/${asset.id}/versions/${asset.current_version.number}`)
      loaded[kind] = newDraft(asset.id, kind, data as { number: number; spec: any })
      return loaded[kind]!
    },

    save(kind, draft) {
      loaded[kind] = draft
      const current = checkpoint.get()
      const rev = Math.max(draft.rev, (current[kind] as Draft | undefined)?.rev ?? 0) + 1
      // No writer stamp: the editor shows it as a change from elsewhere.
      const { writer: _writer, ...rest } = draft
      void checkpoint.set({ ...current, [kind]: { ...rest, rev } }, { tool: `story.ai.${kind}`, args: {} })
    },

    async commit(kind, draft, message, stepId) {
      const body = { ...commitBody(draft), message: message.slice(0, 500), ...(stepId ? { ai_step_id: stepId } : {}) }
      try {
        const { data } = await api<{ data: Version }>(`/api/v1/assets/${draft.assetId}/versions`, { method: 'POST', body })
        return committed(draft, data.number, { ops: draft.ops.length, changes: draft.changes.length })
      } catch (error) {
        const { status, data } = (error ?? {}) as { status?: number; data?: { message?: string } }
        if (status === 409) throw new Error(`The ${kind} changed on another device. Reload it and try again.`)
        if (status === 422) throw new Error(`The change was refused: ${data?.message ?? 'not allowed'}`)
        throw new Error('Could not save the version (offline?). Retry when connected.')
      }
    },

    async memory() {
      const { data } = await api<{ data: { data: Record<string, any>; row_version: number } }>(`/api/v1/projects/${task.projectId}/memory`)
      return { data: data.data ?? {}, version: data.row_version }
    },

    async patchMemory(ops, baseVersion, stepId) {
      await api(`/api/v1/projects/${task.projectId}/memory`, { method: 'PATCH', body: { base_version: baseVersion, patch: ops, ...(stepId ? { ai_step_id: stepId } : {}) } })
    },
  }
}
