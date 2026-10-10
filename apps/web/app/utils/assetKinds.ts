import { assetKindOwners, findModule } from '@kalpa/core'

const coreIcons: Record<string, string> = { image: 'image', audio: 'audio-lines', video: 'film', file: 'file' }

// Icon for an asset kind: a core kind's own icon, otherwise its owning module's icon.
export function assetKindIcon(kind: string): string {
  return coreIcons[kind] ?? findModule(assetKindOwners()[kind] ?? '')?.icon ?? 'square'
}

// What an uploaded file becomes: an image, audio or video asset, or a plain file.
export function assetKindForMime(mime: string): { kind: string; role: string } {
  const family = mime.split('/')[0]
  if (family === 'image') return { kind: 'image', role: 'image' }
  if (family === 'audio') return { kind: 'audio', role: 'audio' }
  if (family === 'video') return { kind: 'video', role: 'video' }
  return { kind: 'file', role: 'source' }
}
