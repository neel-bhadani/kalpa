import type { ClientModule } from '../../core/src/modules'

export default {
  key: 'assets3d',
  name: '3D Assets',
  icon: 'box',
  // Loaded only when a assets3d task is opened, so each editor is its own chunk.
  editor: () => import('./Editor.vue'),
} satisfies ClientModule
