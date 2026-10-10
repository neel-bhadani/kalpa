import type { ClientModule } from '../../core/src/modules'

export default {
  key: 'character',
  name: 'Character',
  icon: 'user-round',
  // Loaded only when a character task is opened, so each editor is its own chunk.
  // Asset kinds this module owns (same as the server manifest).
  assetKinds: ['character', 'outfit', 'motion'],
  editor: () => import('./Editor.vue'),
} satisfies ClientModule
