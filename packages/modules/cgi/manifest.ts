import type { ClientModule } from '../../core/src/modules'

export default {
  key: 'cgi',
  name: 'CGI & VFX',
  icon: 'clapperboard',
  // Asset kinds this module owns (same as the server manifest).
  assetKinds: ['scene', 'vfx_shot'],
  // Loaded only when a cgi task is opened, so each editor is its own chunk.
  editor: () => import('./Editor.vue'),
} satisfies ClientModule
