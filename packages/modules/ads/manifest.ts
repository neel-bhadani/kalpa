import type { ClientModule } from '../../core/src/modules'

export default {
  key: 'ads',
  name: 'Ads',
  icon: 'megaphone',
  // Loaded only when a ads task is opened, so each editor is its own chunk.
  editor: () => import('./Editor.vue'),
} satisfies ClientModule
