import type { ClientModule } from '../../core/src/modules'
import { placeholderCommands } from '../../ui/src/placeholder-commands'

export default {
  key: 'assets3d',
  name: '3D Assets',
  icon: 'box',
  // What the editor (and its assistant) can do. The placeholder editor's commands for now.
  commands: placeholderCommands,
  // Loaded only when a assets3d task is opened, so each editor is its own chunk.
  // Asset kinds this module owns (same as the server manifest).
  assetKinds: ['object3d', 'environment', 'material'],
  editor: () => import('./Editor.vue'),
} satisfies ClientModule
