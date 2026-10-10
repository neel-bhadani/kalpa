import type { ClientModule } from '../../core/src/modules'

export default {
  key: 'story',
  name: 'Story & Script',
  icon: 'book-open',
  // Loaded only when a story task is opened, so each editor is its own chunk.
  // Asset kinds this module owns (same as the server manifest).
  assetKinds: ['story', 'script'],
  editor: () => import('./Editor.vue'),
} satisfies ClientModule
