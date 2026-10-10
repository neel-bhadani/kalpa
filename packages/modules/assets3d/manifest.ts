import type { ClientModule } from '../../core/src/modules'

export default {
  key: 'assets3d',
  name: '3D Assets',
  icon: 'box',
  // Loaded only when a assets3d task is opened, so each editor is its own chunk.
  // Asset kinds this module owns (same as the server manifest).
  assetKinds: ['object3d', 'environment', 'material'],
  editor: () => import('./Editor.vue'),
} satisfies ClientModule
