import type { ClientModule } from '../../core/src/modules'
import { placeholderCommands } from '../../ui/src/placeholder-commands'

export default {
  key: 'cgi',
  name: 'CGI & VFX',
  icon: 'clapperboard',
  // Asset kinds this module owns (same as the server manifest).
  assetKinds: ['scene', 'vfx_shot'],
  // What the editor (and its assistant) can do. The placeholder editor's commands for now.
  commands: placeholderCommands,
  // Loaded only when a cgi task is opened, so each editor is its own chunk.
  editor: () => import('./Editor.vue'),
} satisfies ClientModule
