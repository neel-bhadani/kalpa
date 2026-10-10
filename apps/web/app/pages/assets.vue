<script setup lang="ts">
import { coreAssetKinds } from '@kalpa/core'
import { FileUpload, type UploadState, type UploadedFile } from '@kalpa/core/upload'
import type { Asset, CursorPage, JobUpdate, Project } from '~/types/api'

const api = useApi()
const uploadApi = useUploadApi()
const auth = useAuthStore()
const modules = useModulesStore()
const route = useRoute()
const errors = useFormErrors()

const assets = ref<Asset[]>([])
const projects = ref<Project[]>([])
const nextCursor = ref<string | null>(null)
const loading = ref(true)
const openId = ref<string | null>((route.query.open as string) || null)

const filters = reactive({ kind: '', project: '', q: '' })

// Kind chips: the core kinds, then the kinds of enabled modules.
const kinds = computed(() => [
  ...coreAssetKinds.map(key => ({ key, label: key[0]!.toUpperCase() + key.slice(1) })),
  ...modules.server.filter(module => module.enabled).flatMap(module => module.asset_kinds),
])

async function load(cursor: string | null = null) {
  loading.value = true
  try {
    const page = await api<CursorPage<Asset>>('/api/v1/assets', {
      query: {
        kind: filters.kind || undefined,
        project: filters.project || undefined,
        q: filters.q || undefined,
        cursor: cursor ?? undefined,
      },
    })
    assets.value = cursor ? [...assets.value, ...page.data] : page.data
    nextCursor.value = page.meta.next_cursor
  } catch (error) {
    errors.show(error)
  } finally {
    loading.value = false
  }
}

// Search as you type, a moment after the last key.
let searchTimer: ReturnType<typeof setTimeout> | undefined
watch(() => filters.q, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => load(), 300)
})
watch(() => [filters.kind, filters.project], () => load())

// Uploads: each finished file becomes an image, audio, video or file asset (origin "imported").
type UploadItem = { key: number; name: string; state: UploadState; note?: string }
const uploads = ref<UploadItem[]>([])
let nextKey = 0

function pick(event: Event) {
  const input = event.target as HTMLInputElement
  for (const file of input.files ?? []) {
    const key = nextKey++
    const upload = new FileUpload(file, uploadApi, {
      workspaceId: auth.currentWorkspace!.id,
      onChange: (state) => {
        const item = uploads.value.find(entry => entry.key === key)
        if (!item) return
        item.state = state
        if (state.phase === 'done' && !item.note) finishUpload(item, file.name, state)
      },
    })
    uploads.value.unshift({ key, name: file.name, state: { ...upload.state } })
    upload.start()
  }
  input.value = ''
}

async function finishUpload(item: UploadItem, fileName: string, state: UploadState) {
  if (state.pendingVerification || !state.file) {
    item.note = 'Uploaded; the asset appears once the file is verified.'
    return
  }
  item.note = 'Creating asset…'
  try {
    await createAsset(fileName, state.file)
    item.note = state.deduplicated ? 'Asset created (file already stored)' : 'Asset created'
    await load()
  } catch (error) {
    errors.show(error)
    item.note = 'Could not create the asset.'
  }
}

function createAsset(fileName: string, file: UploadedFile) {
  const { kind, role } = assetKindForMime(file.mime)
  return api('/api/v1/assets', {
    method: 'POST',
    body: {
      kind,
      name: fileName,
      origin: 'imported',
      spec: { mime: file.mime, size_bytes: file.size_bytes, file_name: fileName },
      files: [{ file_id: file.id, role }],
    },
  })
}

function percent(state: UploadState): number {
  return state.phase === 'hashing' ? state.hashedPercent : state.uploadedPercent
}

const canWrite = computed(() => auth.currentWorkspace?.role !== 'viewer')

// Thumbnails are made on the server after upload (file.thumbnail); show them as soon as they're ready.
let thumbTimer: ReturnType<typeof setTimeout> | undefined
useLiveEvent(() => (auth.user ? `user.${auth.user.id}` : null), 'JobUpdated', (update: JobUpdate) => {
  if (update.type === 'file.thumbnail' && update.status === 'succeeded') {
    clearTimeout(thumbTimer)
    thumbTimer = setTimeout(() => load(), 300)
  }
})

await Promise.all([
  load(),
  api<CursorPage<Project>>('/api/v1/projects', { query: { per_page: 100 } }).then(page => (projects.value = page.data)),
])
</script>

<template>
  <main class="mx-auto max-w-6xl p-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h1 class="text-2xl font-semibold">Assets</h1>
      <label v-if="canWrite" class="cursor-pointer rounded-lg bg-accent px-3 py-1.5 text-sm font-medium text-bg hover:opacity-90">
        Upload
        <input type="file" multiple class="sr-only" data-test="file-input" @change="pick">
      </label>
    </div>

    <ul v-if="uploads.length" class="mt-4 space-y-2">
      <li v-for="item in uploads" :key="item.key" class="rounded-lg bg-panel px-4 py-2 text-sm" data-test="upload-item">
        <div class="flex items-center gap-3">
          <span class="min-w-0 flex-1 truncate">{{ item.name }}</span>
          <span class="font-mono text-xs text-muted">{{ percent(item.state).toFixed(0) }}%</span>
          <span class="text-xs" :class="item.state.phase === 'error' ? 'text-red-400' : 'text-muted'">{{ item.note ?? item.state.error ?? item.state.phase }}</span>
        </div>
        <div class="mt-1.5 h-1 overflow-hidden rounded-full bg-bg">
          <div class="h-full rounded-full" :class="item.state.phase === 'hashing' ? 'bg-muted' : 'bg-media'" :style="{ width: `${percent(item.state)}%` }" />
        </div>
      </li>
    </ul>

    <div class="mt-6 flex flex-wrap items-center gap-3">
      <input v-model="filters.q" type="search" placeholder="Search names and tags" class="field !mt-0 max-w-xs" aria-label="Search">
      <select v-model="filters.project" class="rounded-lg border border-muted/30 bg-bg px-2 py-2 text-sm" aria-label="Project">
        <option value="">All projects</option>
        <option v-for="project in projects" :key="project.id" :value="project.id">{{ project.name }}</option>
      </select>
    </div>

    <div class="mt-3 flex flex-wrap gap-2" aria-label="Kind filter">
      <button class="chip" :class="{ 'chip-on': filters.kind === '' }" @click="filters.kind = ''">All kinds</button>
      <button
        v-for="kind in kinds"
        :key="kind.key"
        class="chip"
        :class="{ 'chip-on': filters.kind === kind.key }"
        @click="filters.kind = kind.key"
      >
        <AppIcon :name="assetKindIcon(kind.key)" :size="14" /> {{ kind.label }}
      </button>
    </div>

    <p v-if="errors.message.value" class="mt-4 text-sm text-red-400">{{ errors.message.value }}</p>
    <p v-for="(message, field) in errors.fields.value" :key="field" class="mt-4 text-sm text-red-400">{{ message }}</p>

    <div class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
      <button
        v-for="asset in assets"
        :key="asset.id"
        class="overflow-hidden rounded-xl bg-panel text-left hover:ring-1 hover:ring-accent/40"
        data-test="asset-card"
        @click="openId = asset.id"
      >
        <div class="flex aspect-video items-center justify-center bg-bg/60 text-muted">
          <img v-if="asset.thumbnail_url" :src="asset.thumbnail_url" :alt="asset.name" class="h-full w-full object-cover" loading="lazy">
          <AppIcon v-else :name="assetKindIcon(asset.kind)" :size="32" />
        </div>
        <div class="p-3">
          <p class="flex items-center gap-1.5 truncate text-sm">
            <AppIcon :name="assetKindIcon(asset.kind)" :size="14" class="shrink-0 text-muted" />
            <span class="truncate">{{ asset.name }}</span>
          </p>
          <p class="mt-1 flex justify-between text-xs text-muted">
            <span>{{ asset.kind_label }}</span>
            <span class="font-mono">v{{ asset.current_version?.number }}</span>
          </p>
        </div>
      </button>
    </div>

    <p v-if="!loading && assets.length === 0" class="mt-6 rounded-xl bg-panel p-8 text-center text-sm text-muted">
      No assets here yet. Upload images, audio, video or other files to start.
    </p>

    <button
      v-if="nextCursor"
      :disabled="loading"
      class="mt-4 w-full rounded-lg border border-muted/30 px-4 py-2 text-sm hover:bg-panel"
      @click="load(nextCursor)"
    >
      Load more
    </button>

    <AssetDrawer v-if="openId" :asset-id="openId" @close="openId = null" @changed="load()" />
  </main>
</template>
