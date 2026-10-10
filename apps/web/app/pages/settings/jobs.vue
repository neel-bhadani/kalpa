<script setup lang="ts">
import type { CursorPage, Job, JobUpdate } from '~/types/api'

// The jobs I queued, live.
const api = useApi()
const auth = useAuthStore()
const errors = useFormErrors()

const jobs = ref<Job[]>([])
const nextCursor = ref<string | null>(null)

async function load(cursor: string | null = null) {
  const page = await api<CursorPage<Job>>('/api/v1/jobs', { query: { cursor: cursor ?? undefined } })
  jobs.value = cursor ? [...jobs.value, ...page.data] : page.data
  nextCursor.value = page.meta.next_cursor
}

async function act(job: Job, action: 'cancel' | 'retry') {
  errors.clear()
  try {
    const { data } = await api<{ data: Job }>(`/api/v1/jobs/${job.id}/${action}`, { method: 'POST' })
    jobs.value = jobs.value.map(item => (item.id === data.id ? data : item))
  } catch (error) {
    errors.show(error)
  }
}

let reloadTimer: ReturnType<typeof setTimeout> | undefined
useLiveEvent(() => (auth.user ? `user.${auth.user.id}` : null), 'JobUpdated', (update: JobUpdate) => {
  const known = jobs.value.find(job => job.id === update.id)
  if (known) {
    Object.assign(known, update)
  } else {
    // A job we haven't listed yet (e.g. just queued): reload, but not on every event.
    clearTimeout(reloadTimer)
    reloadTimer = setTimeout(() => load(), 300)
  }
})

await load()
</script>

<template>
  <main class="mx-auto max-w-4xl p-6">
    <h1 class="text-2xl font-semibold">Settings</h1>
    <SettingsTabs class="mt-4" />

    <p class="mt-6 text-sm text-muted">Jobs you queued: heavy or AI work running in this browser, on your workers, or on the server.</p>
    <p v-if="errors.message.value" class="mt-2 text-sm text-red-400">{{ errors.message.value }}</p>
    <p v-for="(message, field) in errors.fields.value" :key="field" class="mt-2 text-sm text-red-400">{{ message }}</p>

    <ul class="mt-4 divide-y divide-muted/15 rounded-xl bg-panel">
      <li v-for="job in jobs" :key="job.id" class="px-4 py-3" data-test="job-row">
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
          <span class="font-mono text-sm">{{ job.type }}</span>
          <span class="rounded-full px-2 py-0.5 text-xs" :class="jobStatusClasses[job.status]">{{ job.status }}</span>
          <span class="text-xs text-muted">{{ job.target }}<template v-if="job.worker"> · {{ job.worker.name }}</template></span>
          <span v-if="job.task_title" class="truncate text-xs text-muted">({{ job.task_title }})</span>
          <span class="ml-auto font-mono text-xs text-muted">{{ timeAgo(job.created_at) }}</span>
          <button v-if="activeJobStatuses.includes(job.status)" class="text-xs text-red-400 hover:underline" @click="act(job, 'cancel')">Cancel</button>
          <button v-if="job.status === 'failed' || job.status === 'cancelled'" class="text-xs text-accent hover:underline" @click="act(job, 'retry')">Retry</button>
        </div>
        <div class="mt-2 flex items-center gap-3">
          <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-bg">
            <div class="h-full rounded-full bg-media transition-[width]" :style="{ width: `${job.progress}%` }" />
          </div>
          <span class="w-10 text-right font-mono text-xs text-muted">{{ job.progress }}%</span>
        </div>
        <p v-if="job.error" class="mt-1 text-xs text-red-400">{{ job.error }}</p>
      </li>
      <li v-if="!jobs.length" class="px-4 py-8 text-center text-sm text-muted">No jobs yet.</li>
    </ul>

    <button v-if="nextCursor" class="mt-4 w-full rounded-lg border border-muted/30 px-4 py-2 text-sm hover:bg-panel" @click="load(nextCursor)">Load more</button>
  </main>
</template>
