import type { ClientModule } from '../../core/src/modules'

export default {
  key: 'voice',
  name: 'Voice',
  icon: 'mic',
  // Loaded only when a voice task is opened, so each editor is its own chunk.
  // Asset kinds this module owns (same as the server manifest).
  assetKinds: ['voice_profile'],
  editor: () => import('./Editor.vue'),
} satisfies ClientModule
