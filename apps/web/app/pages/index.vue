<script setup lang="ts">
const auth = useAuthStore()

// For now, the first workspace is the personal one made at sign-up.
const workspace = computed(() => auth.workspaces[0])

async function logout() {
  await auth.logout()
  await navigateTo('/login')
}
</script>

<template>
  <main class="min-h-screen flex items-center justify-center p-6">
    <section class="w-full max-w-sm rounded-xl bg-panel p-6">
      <h1 class="text-2xl font-semibold">Welcome, {{ auth.user?.name }}</h1>
      <p v-if="workspace" class="mt-1 text-sm text-muted">{{ workspace.name }}</p>

      <button
        class="mt-6 w-full rounded-lg border border-muted/30 px-4 py-2 text-sm hover:bg-bg"
        @click="logout"
      >
        Log out
      </button>

      <div class="mt-4 flex justify-center gap-4 text-xs text-muted">
        <NuxtLink to="/projects" class="hover:text-text">Projects</NuxtLink>
        <NuxtLink to="/settings/devices" class="hover:text-text">Devices</NuxtLink>
        <NuxtLink to="/status" class="hover:text-text">Service status</NuxtLink>
      </div>
    </section>
  </main>
</template>
