<script setup lang="ts">
import type { DeviceTier } from '@kalpa/core'

const store = useDevicesStore()
const error = ref('')

const tiers: DeviceTier[] = ['low', 'mid', 'high']

async function run(action: () => Promise<void>) {
  error.value = ''
  try {
    await action()
  } catch {
    error.value = 'Could not save the change. Please try again.'
  }
}

function onTierChange(id: string, event: Event) {
  const value = (event.target as HTMLSelectElement).value
  run(() => store.setTierOverride(id, value === '' ? null : (value as DeviceTier)))
}

function lastSeen(value: string | null): string {
  return value ? new Date(value).toLocaleString() : 'never'
}

await run(() => store.fetchAll())
</script>

<template>
  <main class="mx-auto max-w-4xl p-6">
    <NuxtLink to="/" class="text-sm text-muted hover:text-text">← Back</NuxtLink>
    <h1 class="mt-2 text-2xl font-semibold">Devices</h1>
    <p class="mt-1 text-sm text-muted">Where you use Kalpa Studio. The tier decides how much heavy work runs on each device.</p>

    <p v-if="error" class="mt-4 text-sm text-red-400">{{ error }}</p>

    <div class="mt-6 overflow-x-auto rounded-xl bg-panel">
      <table class="w-full text-left text-sm">
        <thead class="text-muted">
          <tr>
            <th class="px-4 py-3 font-normal">Name</th>
            <th class="px-4 py-3 font-normal">Platform</th>
            <th class="px-4 py-3 font-normal">GPU</th>
            <th class="px-4 py-3 font-normal">Tier</th>
            <th class="px-4 py-3 font-normal">Last seen</th>
            <th class="px-4 py-3" />
          </tr>
        </thead>
        <tbody>
          <tr v-for="device in store.devices" :key="device.id" class="border-t border-muted/15">
            <td class="px-4 py-3">
              {{ device.name }}
              <span
                v-if="device.client_id === store.currentClientId"
                class="ml-2 whitespace-nowrap rounded-full bg-accent/15 px-2 py-0.5 text-xs text-accent"
              >This device</span>
            </td>
            <td class="px-4 py-3 text-muted">{{ device.platform }}</td>
            <td class="px-4 py-3 text-muted">{{ device.capabilities.gpu ?? 'unknown' }}</td>
            <td class="px-4 py-3">
              <select
                :value="device.tier_override ?? ''"
                class="rounded-lg border border-muted/30 bg-bg px-2 py-1 text-sm outline-none focus:border-accent"
                @change="onTierChange(device.id, $event)"
              >
                <option value="">Auto ({{ device.tier }})</option>
                <option v-for="tier in tiers" :key="tier" :value="tier">{{ tier }}</option>
              </select>
            </td>
            <td class="px-4 py-3 font-mono text-xs text-muted">{{ lastSeen(device.last_seen_at) }}</td>
            <td class="px-4 py-3 text-right">
              <button class="text-sm text-red-400 hover:underline" @click="run(() => store.remove(device.id))">
                Remove
              </button>
            </td>
          </tr>
          <tr v-if="store.devices.length === 0">
            <td colspan="6" class="px-4 py-6 text-center text-muted">No devices yet.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </main>
</template>
