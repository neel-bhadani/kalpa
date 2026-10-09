<script setup lang="ts">
const auth = useAuthStore()
const modules = useModulesStore()
const error = ref('')

const isOwner = computed(() => auth.currentWorkspace?.role === 'owner')

async function toggle(key: string, event: Event) {
  error.value = ''
  const checkbox = event.target as HTMLInputElement
  try {
    await modules.setEnabled(key, checkbox.checked)
  } catch {
    checkbox.checked = !checkbox.checked
    error.value = 'Could not save the change. Please try again.'
  }
}
</script>

<template>
  <main class="mx-auto max-w-3xl p-6">
    <h1 class="text-2xl font-semibold">Settings</h1>
    <SettingsTabs class="mt-4" />

    <p class="mt-6 text-sm text-muted">
      Turn modules on or off for this workspace. Turned-off modules leave the sidebar, and no new tasks can use them.
    </p>
    <p v-if="!isOwner" class="mt-2 text-sm text-amber-300">Only the workspace owner can change this.</p>
    <p v-if="error" class="mt-2 text-sm text-red-400">{{ error }}</p>

    <ul class="mt-4 divide-y divide-muted/15 rounded-xl bg-panel">
      <li v-for="module in modules.server" :key="module.key" class="flex items-center gap-3 px-4 py-3">
        <AppIcon :name="module.icon" class="text-muted" />
        <div class="min-w-0 flex-1">
          <p>{{ module.name }}</p>
          <p class="truncate text-xs text-muted">{{ module.task_kinds.map(kind => kind.label).join(' · ') }}</p>
        </div>
        <label class="flex items-center gap-2 text-sm text-muted">
          <input
            type="checkbox"
            class="size-4 accent-[#8b7cff]"
            :checked="module.enabled"
            :disabled="!isOwner"
            :aria-label="`${module.name} enabled`"
            @change="toggle(module.key, $event)"
          >
          {{ module.enabled ? 'On' : 'Off' }}
        </label>
      </li>
    </ul>
  </main>
</template>
