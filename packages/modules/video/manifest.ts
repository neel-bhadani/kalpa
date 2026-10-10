import type { ClientModule } from '../../core/src/modules'

export default {
  key: 'video',
  name: 'Video',
  icon: 'film',
  // Loaded only when a video task is opened, so each editor is its own chunk.
  // Asset kinds this module owns (same as the server manifest).
  assetKinds: ['timeline'],
  editor: () => import('./Editor.vue'),
} satisfies ClientModule
