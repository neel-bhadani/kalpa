import type { ClientModule } from '../../core/src/modules'

export default {
  key: 'character',
  name: 'Character',
  icon: 'user-round',
  // Loaded only when a character task is opened, so each editor is its own chunk.
  editor: () => import('./Editor.vue'),
} satisfies ClientModule
