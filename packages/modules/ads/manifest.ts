import type { ClientModule } from '../../core/src/modules'

export default {
  key: 'ads',
  name: 'Ads',
  icon: 'megaphone',
  // Loaded only when a ads task is opened, so each editor is its own chunk.
  // Asset kinds this module owns (same as the server manifest).
  assetKinds: ['ad', 'brand_kit'],
  editor: () => import('./Editor.vue'),
} satisfies ClientModule
