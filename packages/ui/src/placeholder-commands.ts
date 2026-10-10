// What the placeholder editor can do to its checkpoint. Its own buttons and the assistant both
// call these, so a person and an assistant change the task in exactly the same way.

import type { ModuleCommand } from '../../core/src/modules'

export const PLACEHOLDER_TABS = ['notes', 'details'] as const

function notesOf(checkpoint: Record<string, any>): string {
  return String(checkpoint.data?.notes ?? '')
}

function withNotes(checkpoint: Record<string, any>, notes: string): Record<string, any> {
  return { ...checkpoint, data: { ...checkpoint.data, notes } }
}

export const placeholderCommands: Record<string, ModuleCommand> = {
  'notes.append': {
    description: 'Add a line at the end of the task notes.',
    schema: {
      type: 'object',
      properties: { text: { type: 'string', minLength: 1, maxLength: 2000, description: 'The line to add.' } },
      required: ['text'],
      additionalProperties: false,
    },
    apply: (checkpoint, { text }) => {
      const notes = notesOf(checkpoint)
      return withNotes(checkpoint, notes ? `${notes}\n${text}` : text)
    },
  },
  'notes.replace': {
    description: 'Replace all task notes.',
    schema: {
      type: 'object',
      properties: { text: { type: 'string', maxLength: 20000 } },
      required: ['text'],
      additionalProperties: false,
    },
    apply: (checkpoint, { text }) => withNotes(checkpoint, text),
  },
  'view.selectTab': {
    description: 'Open one of the editor tabs.',
    schema: {
      type: 'object',
      properties: { tab: { type: 'string', enum: [...PLACEHOLDER_TABS] } },
      required: ['tab'],
      additionalProperties: false,
    },
    apply: (checkpoint, { tab }) => ({ ...checkpoint, view: { ...checkpoint.view, tab } }),
  },
}
