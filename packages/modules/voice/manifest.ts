import type { ClientModule } from '../../core/src/modules'

export default {
  key: 'voice',
  name: 'Voice',
  icon: 'mic',
  // Loaded only when a voice task is opened, so each editor is its own chunk.
  editor: () => import('./Editor.vue'),
} satisfies ClientModule
