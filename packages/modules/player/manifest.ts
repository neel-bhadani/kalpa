import type { ClientModule } from '../../core/src/modules'

export default {
  key: 'player',
  name: 'Player',
  icon: 'circle-play',
  // Loaded only when a player task is opened, so each editor is its own chunk.
  // Asset kinds this module owns (same as the server manifest).
  assetKinds: [],
  editor: () => import('./Editor.vue'),
} satisfies ClientModule
