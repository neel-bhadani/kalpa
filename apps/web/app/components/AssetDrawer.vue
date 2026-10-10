<script setup lang="ts">
import type { AssetDetail, AssetLink, AssetVersion, CursorPage, Project } from '~/types/api'

// Side panel with one asset: spec, versions (restore), files, links and projects.
const props = defineProps<{ assetId: string }>()
const emit = defineEmits<{ close: []; changed: [] }>()

const api = useApi()
const auth = useAuthStore()
const errors = useFormErrors()

const asset = ref<AssetDetail | null>(null)
const versions = ref<AssetVersion[]>([])
const links = ref<AssetLink[]>([])
const projects = ref<Project[]>([])
const fileUrls = ref<Record<string, string>>({})
const attachTo = ref('')

const canWrite = computed(() => auth.currentWorkspace?.role !== 'viewer')

async function load() {
  errors.clear()
  try {
    const [detail, versionPage, linkList] = await Promise.all([
      api<{ data: AssetDetail }>(`/api/v1/assets/${props.assetId}`),
      api<CursorPage<AssetVersion>>(`/api/v1/assets/${props.assetId}/versions`, { query: { per_page: 100 } }),
      api<{ data: AssetLink[] }>(`/api/v1/assets/${props.assetId}/links`),
    ])
    asset.value = detail.data
    versions.value = versionPage.data
    links.value = linkList.data
  } catch (error) {
    errors.show(error)
  }
}

async function restore(number: number) {
  errors.clear()
  try {
    await api(`/api/v1/assets/${props.assetId}/restore/${number}`, { method: 'POST' })
    await load()
    emit('changed')
  } catch (error) {
    errors.show(error)
  }
}

async function getLink(fileId: string) {
  const { data } = await api<{ data: { url: string } }>(`/api/v1/files/${fileId}/url`)
  fileUrls.value[fileId] = data.url
}

async function loadProjects() {
  projects.value = (await api<CursorPage<Project>>('/api/v1/projects', { query: { per_page: 100 } })).data
}

async function attach() {
  if (!attachTo.value) return
  errors.clear()
  try {
    await api(`/api/v1/projects/${attachTo.value}/assets`, { method: 'POST', body: { asset_id: props.assetId } })
    attachTo.value = ''
    await load()
    emit('changed')
  } catch (error) {
    errors.show(error)
  }
}

watch(() => props.assetId, load, { immediate: true })
onMounted(loadProjects)
</script>

<template>
  <div class="fixed inset-0 z-20 flex justify-end bg-bg/60" @click.self="emit('close')" @keydown.esc="emit('close')">
    <aside class="h-full w-full max-w-xl overflow-y-auto bg-panel p-6 shadow-2xl" role="dialog" aria-label="Asset details">
      <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
          <p class="flex items-center gap-2 text-sm text-muted">
            <AppIcon :name="assetKindIcon(asset?.kind ?? '')" :size="16" /> {{ asset?.kind_label }}
            <span v-if="asset?.current_version" class="font-mono text-xs">v{{ asset.current_version.number }}</span>
          </p>
          <h2 class="mt-1 truncate text-xl font-semibold">{{ asset?.name ?? 'Asset' }}</h2>
          <p v-if="asset?.tags.length" class="mt-1 text-xs text-muted">{{ asset.tags.map(tag => `#${tag}`).join(' ') }}</p>
        </div>
        <button class="text-sm text-muted hover:text-text" @click="emit('close')">Close</button>
      </div>

      <p v-if="errors.message.value" class="mt-3 text-sm text-red-400">{{ errors.message.value }}</p>
      <p v-for="(message, field) in errors.fields.value" :key="field" class="mt-3 text-sm text-red-400">{{ message }}</p>

      <template v-if="asset">
        <h3 class="mt-6 text-sm text-muted">Files</h3>
        <ul class="mt-2 space-y-1">
          <li v-for="item in asset.files" :key="`${item.role}-${item.file.id}`" class="flex items-center gap-3 text-sm">
            <span class="w-20 font-mono text-xs text-muted">{{ item.role }}</span>
            <span class="min-w-0 flex-1 truncate font-mono text-xs">{{ item.file.mime }} · {{ (item.file.size_bytes / 1024).toFixed(0) }} KB</span>
            <a v-if="fileUrls[item.file.id]" :href="fileUrls[item.file.id]" target="_blank" rel="noopener" class="text-xs text-accent hover:underline">Open</a>
            <button v-else class="text-xs text-accent hover:underline" @click="getLink(item.file.id)">Get link</button>
          </li>
          <li v-if="!asset.files.length" class="text-sm text-muted">No files.</li>
        </ul>

        <h3 class="mt-6 text-sm text-muted">Spec</h3>
        <pre class="mt-2 max-h-64 overflow-auto rounded-lg bg-bg p-3 font-mono text-xs">{{ JSON.stringify(asset.spec, null, 2) }}</pre>

        <h3 class="mt-6 text-sm text-muted">Versions</h3>
        <ol class="mt-2 space-y-2">
          <li v-for="version in versions" :key="version.id" class="flex items-center gap-3 text-sm" data-test="version">
            <span class="w-8 font-mono text-xs text-muted">v{{ version.number }}</span>
            <span class="min-w-0 flex-1">
              <span class="block truncate">{{ version.message || '—' }}</span>
              <span class="text-xs text-muted">{{ version.author ?? version.created_by_type }} · {{ timeAgo(version.created_at) }}</span>
            </span>
            <span v-if="version.number === asset.current_version?.number" class="text-xs text-media">current</span>
            <button
              v-else-if="canWrite"
              class="rounded-lg border border-muted/30 px-2 py-0.5 text-xs hover:border-accent hover:text-accent"
              @click="restore(version.number)"
            >
              Restore
            </button>
          </li>
        </ol>

        <h3 class="mt-6 text-sm text-muted">Links</h3>
        <ul class="mt-2 space-y-1 text-sm">
          <li v-for="link in links" :key="link.id">
            <span class="text-muted">{{ link.direction === 'out' ? '→' : '←' }} {{ link.relation.replace(/_/g, ' ') }}</span>
            {{ link.asset?.name }}
          </li>
          <li v-if="!links.length" class="text-muted">No links.</li>
        </ul>

        <h3 class="mt-6 text-sm text-muted">Projects</h3>
        <ul class="mt-2 space-y-1 text-sm">
          <li v-for="project in asset.projects" :key="project.id">
            <NuxtLink :to="`/projects/${project.id}`" class="hover:text-accent">{{ project.name }}</NuxtLink>
            <span class="ml-2 text-xs text-muted">{{ project.pinned_version_id ? 'pinned' : 'latest' }}</span>
          </li>
          <li v-if="!asset.projects.length" class="text-muted">Not in any project.</li>
        </ul>
        <div v-if="canWrite" class="mt-3 flex gap-2">
          <select v-model="attachTo" class="flex-1 rounded-lg border border-muted/30 bg-bg px-2 py-1.5 text-sm" aria-label="Add to project">
            <option value="">Add to project…</option>
            <option v-for="project in projects" :key="project.id" :value="project.id">{{ project.name }}</option>
          </select>
          <button :disabled="!attachTo" class="rounded-lg bg-accent px-3 py-1.5 text-sm font-medium text-bg disabled:opacity-50" @click="attach">Add</button>
        </div>
      </template>
    </aside>
  </div>
</template>
