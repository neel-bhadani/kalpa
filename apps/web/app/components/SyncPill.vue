<script setup lang="ts">
// Header pill: Synced / Syncing / Offline (N changes waiting).
const sync = useSyncStore()

const label = computed(() => {
  const { state, pending } = sync.status
  if (state === 'offline') return pending ? `Offline (${pending} waiting)` : 'Offline'
  if (state === 'syncing') return pending ? `Syncing ${pending}…` : 'Syncing…'
  return 'Synced'
})

const dot = computed(() => ({
  synced: 'bg-green-500',
  syncing: 'bg-media animate-pulse',
  offline: 'bg-amber-400',
})[sync.status.state])
</script>

<template>
  <span
    class="flex items-center gap-1.5 rounded-full border border-muted/25 px-2.5 py-1 text-xs text-muted"
    :title="sync.status.lastError ?? undefined"
    data-test="sync-pill"
  >
    <span class="size-2 rounded-full" :class="dot" />
    {{ label }}
  </span>
</template>
