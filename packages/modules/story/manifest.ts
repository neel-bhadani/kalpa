import type { ClientModule, ModuleCommand } from '../../core/src/modules'
import { specCommands } from './commands'
import { runCommand, type Draft } from './draft'

// Each command changes the task's story or script draft in the checkpoint (see draft.ts): the
// editor's own edits and the assistant's tool calls go through the same code.
const commands: Record<string, ModuleCommand> = Object.fromEntries(Object.entries(specCommands).map(([name, command]) => [name, {
  description: command.description,
  schema: command.schema,
  apply: (checkpoint, args) => {
    const draft = checkpoint[command.kind] as Draft | undefined
    if (!draft) throw new Error(`This task has no ${command.kind} open.`)
    return { ...checkpoint, [command.kind]: runCommand(draft, name, args).draft }
  },
}]))

export default {
  key: 'story',
  name: 'Story & Script',
  icon: 'book-open',
  commands,
  // Asset kinds this module owns (same as the server manifest).
  assetKinds: ['story', 'script'],
  // Loaded only when a story task is opened, so the editor (and ProseMirror) is its own chunk.
  editor: () => import('./Editor.vue'),
} satisfies ClientModule
