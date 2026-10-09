import type { ClientModule } from '../../core/src/modules'

export default {
  key: 'music',
  name: 'Music',
  icon: 'music',
  // Loaded only when a music task is opened, so each editor is its own chunk.
  editor: () => import('./Editor.vue'),
} satisfies ClientModule
