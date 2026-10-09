<script setup lang="ts">
import { FileUpload, type UploadState } from '@kalpa/core/upload'

// Temporary test page for resumable uploads (the asset library replaces it in step 6b).

type Item = { key: number; name: string; size: number; state: UploadState; url?: string }

const api = useApi()
const uploadApi = useUploadApi()
const auth = useAuthStore()

const items = ref<Item[]>([])
// The uploaders, outside Vue's reactivity; their state is copied into items on every change.
const uploads = new Map<number, FileUpload>()
const dragging = ref(false)
let nextKey = 0

function add(files: FileList | null) {
  for (const file of files ?? []) {
    const key = nextKey++
    const upload = new FileUpload(file, uploadApi, {
      workspaceId: auth.currentWorkspace!.id,
      onChange: (state) => {
        const item = items.value.find(entry => entry.key === key)
        if (item) item.state = state
      },
    })
    uploads.set(key, upload)
    items.value.unshift({ key, name: file.name, size: file.size, state: { ...upload.state } })
    upload.start()
  }
}

function onDrop(event: DragEvent) {
  dragging.value = false
  add(event.dataTransfer?.files ?? null)
}

function onPick(event: Event) {
  const input = event.target as HTMLInputElement
  add(input.files)
  input.value = ''
}

async function getLink(item: Item) {
  const { data } = await api<{ data: { url: string } }>(`/api/v1/files/${item.state.file!.id}/url`)
  item.url = data.url
}

function megabytes(bytes: number): string {
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`
}

const phaseLabels: Record<UploadState['phase'], string> = {
  hashing: 'Hashing',
  uploading: 'Uploading',
  paused: 'Paused',
  completing: 'Finishing',
  done: 'Done',
  cancelled: 'Cancelled',
  error: 'Failed',
}

// Hashing fills the bar first, then uploading.
function percent(state: UploadState): number {
  return state.phase === 'hashing' ? state.hashedPercent : state.uploadedPercent
}
</script>

<template>
  <main class="mx-auto max-w-3xl p-6">
    <h1 class="text-2xl font-semibold">Uploads <span class="text-sm font-normal text-muted">(test page)</span></h1>
    <p class="mt-1 text-sm text-muted">Files go straight to storage in 8 MB parts. Reload mid-upload and pick the same file again to resume.</p>

    <label
      class="mt-6 flex cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed p-10 text-center"
      :class="dragging ? 'border-media bg-media/5' : 'border-muted/30 bg-panel'"
      @dragover.prevent="dragging = true"
      @dragleave="dragging = false"
      @drop.prevent="onDrop"
    >
      <span>Drop files here or <span class="text-accent">choose files</span></span>
      <span class="mt-1 text-xs text-muted">Images, audio, video, 3D (glb, gltf, fbx, obj, bvh), text or PDF, up to 2 GB</span>
      <input type="file" multiple class="sr-only" data-test="file-input" @change="onPick">
    </label>

    <ul class="mt-6 space-y-3">
      <li v-for="item in items" :key="item.key" class="rounded-xl bg-panel p-4" data-test="upload-item">
        <div class="flex items-center justify-between gap-3">
          <p class="min-w-0 truncate">{{ item.name }}</p>
          <span class="shrink-0 font-mono text-xs text-muted">{{ megabytes(item.size) }}</span>
        </div>

        <div class="mt-3 h-2 overflow-hidden rounded-full bg-bg">
          <div
            class="h-full rounded-full transition-[width]"
            :class="item.state.phase === 'hashing' ? 'bg-muted' : item.state.phase === 'error' ? 'bg-red-500' : 'bg-media'"
            :style="{ width: `${percent(item.state)}%` }"
          />
        </div>

        <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
          <span data-test="phase">{{ phaseLabels[item.state.phase] }}</span>
          <span class="font-mono text-xs text-muted" data-test="percent">{{ percent(item.state).toFixed(1) }}%</span>
          <span v-if="item.state.resumedParts > 0" class="text-xs text-media" data-test="resumed">
            Resumed: {{ item.state.resumedParts }} of {{ item.state.partCount }} parts were already uploaded
          </span>
          <span v-if="item.state.deduplicated" class="text-xs text-green-400" data-test="deduplicated">
            Deduplicated: this workspace already has this file, nothing was uploaded
          </span>
          <span v-if="item.state.error" class="text-xs text-red-400">{{ item.state.error }}</span>

          <span class="ml-auto flex gap-2">
            <button v-if="item.state.phase === 'uploading'" class="text-xs text-muted hover:text-text" @click="uploads.get(item.key)?.pause()">Pause</button>
            <button v-if="item.state.phase === 'paused' || (item.state.phase === 'error' && item.state.partCount > 0)" class="text-xs text-accent hover:underline" @click="uploads.get(item.key)?.resume()">Resume</button>
            <button
              v-if="['hashing', 'uploading', 'paused', 'error'].includes(item.state.phase)"
              class="text-xs text-red-400 hover:underline"
              @click="uploads.get(item.key)?.cancel()"
            >
              Cancel
            </button>
            <template v-if="item.state.phase === 'done' && item.state.file">
              <a v-if="item.url" :href="item.url" target="_blank" rel="noopener" class="text-xs text-accent hover:underline">Open file</a>
              <button v-else class="text-xs text-accent hover:underline" @click="getLink(item)">Get link</button>
            </template>
          </span>
        </div>
      </li>
    </ul>
  </main>
</template>
