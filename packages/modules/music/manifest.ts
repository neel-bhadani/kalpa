import type { ClientModule } from '../../core/src/modules'

export default {
  key: 'music',
  name: 'Music',
  icon: 'music',
  // Loaded only when a music task is opened, so each editor is its own chunk.
  // Asset kinds this module owns (same as the server manifest).
  assetKinds: ['composition'],
  editor: () => import('./Editor.vue'),
} satisfies ClientModule
