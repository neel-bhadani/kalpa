import type { ClientModule } from '../../core/src/modules'

export default {
  key: 'video',
  name: 'Video',
  icon: 'clapperboard',
  // Loaded only when a video task is opened, so each editor is its own chunk.
  editor: () => import('./Editor.vue'),
} satisfies ClientModule
