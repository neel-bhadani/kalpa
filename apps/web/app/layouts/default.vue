<script setup lang="ts">
const auth = useAuthStore()
const modules = useModulesStore()

async function logout() {
  await auth.logout()
  await navigateTo('/login')
}

// Signed in: this tab starts running device jobs (and live updates connect on first use).
onMounted(() => {
  useBrowserWorker().start().catch(error => console.warn('[kalpa worker] could not start', error))
})

// Below 768px (md) the sidebar shows icons only.
const linkClasses = 'flex items-center gap-3 rounded-lg px-2.5 py-2 text-sm text-muted hover:bg-bg hover:text-text'
const activeClasses = '!bg-accent/10 !text-text'
</script>

<template>
  <div class="flex min-h-screen">
    <aside class="sticky top-0 flex h-screen w-14 shrink-0 flex-col bg-panel p-2 md:w-56 md:p-3">
      <p class="mb-4 hidden px-2.5 pt-1 text-sm font-semibold md:block">Kalpa Studio</p>

      <nav class="flex flex-1 flex-col gap-1 overflow-y-auto">
        <NuxtLink to="/" :class="linkClasses" :exact-active-class="activeClasses" title="Dashboard">
          <AppIcon name="layout-dashboard" />
          <span class="hidden md:inline">Dashboard</span>
        </NuxtLink>
        <NuxtLink
          v-for="module in modules.enabled"
          :key="module.key"
          :to="`/m/${module.key}`"
          :class="[linkClasses, $route.path.startsWith(`/m/${module.key}`) && activeClasses]"
          :title="module.name"
        >
          <AppIcon :name="module.icon" />
          <span class="hidden md:inline">{{ module.name }}</span>
        </NuxtLink>
      </nav>

      <nav class="mt-2 flex flex-col gap-1 border-t border-muted/15 pt-2">
        <NuxtLink to="/projects" :class="linkClasses" :active-class="activeClasses" title="Projects">
          <AppIcon name="folder" />
          <span class="hidden md:inline">Projects</span>
        </NuxtLink>
        <NuxtLink to="/assets" :class="linkClasses" :active-class="activeClasses" title="Assets">
          <AppIcon name="library" />
          <span class="hidden md:inline">Assets</span>
        </NuxtLink>
        <NuxtLink to="/settings/modules" :class="[linkClasses, $route.path.startsWith('/settings') && activeClasses]" title="Settings">
          <AppIcon name="settings" />
          <span class="hidden md:inline">Settings</span>
        </NuxtLink>
      </nav>
    </aside>

    <div class="min-w-0 flex-1">
      <header class="flex items-center justify-between gap-4 border-b border-muted/15 px-6 py-4">
        <div class="min-w-0">
          <p class="truncate font-semibold">Welcome, {{ auth.user?.name }}</p>
          <p class="truncate text-xs text-muted">{{ auth.currentWorkspace?.name }}</p>
        </div>
        <div class="flex items-center gap-2">
          <NotificationBell />
          <button class="rounded-lg border border-muted/30 px-3 py-1.5 text-sm hover:bg-panel" @click="logout">Log out</button>
        </div>
      </header>

      <slot />
    </div>
  </div>
</template>
