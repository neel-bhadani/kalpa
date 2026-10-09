// Client-side module registry. Each module lives in packages/modules/<key>/manifest.ts;
// this is the one place that lists them, in sidebar order. Plain TypeScript, no Vue.

import story from '../../modules/story/manifest'
import character from '../../modules/character/manifest'
import assets3d from '../../modules/assets3d/manifest'
import voice from '../../modules/voice/manifest'
import music from '../../modules/music/manifest'
import video from '../../modules/video/manifest'
import ads from '../../modules/ads/manifest'
import player from '../../modules/player/manifest'
import io from '../../modules/io/manifest'

// The task fields an editor receives.
export type EditorTask = {
  id: string
  module: string
  title: string
  status: string
  mode: string
  checkpoint: unknown
}

export type ClientModule = {
  // Same key as the server manifest (app/Modules/<Name>/Manifest.php).
  key: string
  name: string
  // A lucide icon name.
  icon: string
  // Lazy-loads the editor component (a Vue component; typed loosely to keep core free of Vue).
  editor: () => Promise<{ default: unknown }>
}

export const modules: ClientModule[] = [story, character, assets3d, voice, music, video, ads, player, io]

export function findModule(key: string): ClientModule | undefined {
  return modules.find(module => module.key === key)
}
