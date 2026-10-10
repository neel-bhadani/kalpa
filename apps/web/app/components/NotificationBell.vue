<script setup lang="ts">
import { Bell } from '@lucide/vue'

// The header bell: job finished or failed, upload rejected. New ones arrive live.
type Item = { id: string; data: { title: string; body: string }; read_at: string | null; created_at: string }

const api = useApi()
const auth = useAuthStore()

const items = ref<Item[]>([])
const unread = ref(0)
const open = ref(false)

async function load() {
  const page = await api<{ data: Item[]; meta: { unread: number } }>('/api/v1/notifications')
  items.value = page.data
  unread.value = page.meta.unread
}

async function toggle() {
  open.value = !open.value
  if (open.value && unread.value > 0) {
    unread.value = 0
    await api('/api/v1/notifications/read', { method: 'POST' })
  }
}

useLiveEvent(() => (auth.user ? `user.${auth.user.id}` : null), 'NotificationCreated', (item: Item) => {
  items.value = [item, ...items.value].slice(0, 20)
  if (!open.value) unread.value++
})

onMounted(load)
</script>

<template>
  <div class="relative">
    <button
      class="relative rounded-lg p-2 text-muted hover:bg-panel hover:text-text"
      :aria-label="unread ? `Notifications (${unread} unread)` : 'Notifications'"
      data-test="bell"
      @click="toggle"
    >
      <Bell :size="18" :stroke-width="1.75" />
      <span
        v-if="unread"
        class="absolute -right-0.5 -top-0.5 min-w-4 rounded-full bg-accent px-1 text-center text-[10px] font-semibold leading-4 text-bg"
        data-test="bell-count"
      >{{ unread }}</span>
    </button>

    <div v-if="open" class="absolute right-0 z-30 mt-2 w-80 rounded-xl bg-panel p-2 shadow-2xl ring-1 ring-muted/20" data-test="bell-list">
      <p v-if="!items.length" class="p-3 text-sm text-muted">No notifications yet.</p>
      <div v-for="item in items" :key="item.id" class="rounded-lg p-3 hover:bg-bg/50">
        <p class="text-sm font-medium">{{ item.data.title }}</p>
        <p class="mt-0.5 text-xs text-muted">{{ item.data.body }}</p>
        <p class="mt-1 font-mono text-[10px] text-muted">{{ timeAgo(item.created_at) }}</p>
      </div>
    </div>
  </div>
</template>
