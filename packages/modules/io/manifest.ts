import type { ClientModule } from '../../core/src/modules'
import { placeholderCommands } from '../../ui/src/placeholder-commands'

export default {
  key: 'io',
  name: 'Import/Export',
  icon: 'arrow-left-right',
  // What the editor (and its assistant) can do. The placeholder editor's commands for now.
  commands: placeholderCommands,
  // Loaded only when a io task is opened, so each editor is its own chunk.
  // Asset kinds this module owns (same as the server manifest).
  assetKinds: [],
  editor: () => import('./Editor.vue'),
} satisfies ClientModule
