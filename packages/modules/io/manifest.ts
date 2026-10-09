import type { ClientModule } from '../../core/src/modules'

export default {
  key: 'io',
  name: 'Import/Export',
  icon: 'arrow-left-right',
  // Loaded only when a io task is opened, so each editor is its own chunk.
  editor: () => import('./Editor.vue'),
} satisfies ClientModule
