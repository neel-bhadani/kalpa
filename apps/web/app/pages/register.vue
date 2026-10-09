<script setup lang="ts">
definePageMeta({ layout: false })

const auth = useAuthStore()
const errors = useFormErrors()

const name = ref('')
const email = ref('')
const password = ref('')
const busy = ref(false)

async function submit() {
  busy.value = true
  errors.clear()
  try {
    await auth.register(name.value, email.value, password.value)
    await navigateTo('/')
  } catch (error) {
    errors.show(error)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <main class="min-h-screen flex items-center justify-center p-6">
    <form class="w-full max-w-sm rounded-xl bg-panel p-6 space-y-4" @submit.prevent="submit">
      <div>
        <h1 class="text-2xl font-semibold">Create an account</h1>
        <p class="mt-1 text-sm text-muted">for Kalpa Studio</p>
      </div>

      <p v-if="errors.message.value" class="text-sm text-red-400">{{ errors.message.value }}</p>

      <label class="block">
        <span class="text-sm text-muted">Name</span>
        <input v-model="name" type="text" autocomplete="name" required class="field">
        <span v-if="errors.fields.value.name" class="mt-1 block text-sm text-red-400">{{ errors.fields.value.name }}</span>
      </label>

      <label class="block">
        <span class="text-sm text-muted">Email</span>
        <input v-model="email" type="email" autocomplete="email" required class="field">
        <span v-if="errors.fields.value.email" class="mt-1 block text-sm text-red-400">{{ errors.fields.value.email }}</span>
      </label>

      <label class="block">
        <span class="text-sm text-muted">Password</span>
        <input v-model="password" type="password" autocomplete="new-password" required minlength="8" class="field">
        <span class="mt-1 block text-xs text-muted">At least 8 characters.</span>
        <span v-if="errors.fields.value.password" class="mt-1 block text-sm text-red-400">{{ errors.fields.value.password }}</span>
      </label>

      <button :disabled="busy" class="w-full rounded-lg bg-accent px-4 py-2 text-sm font-medium text-bg hover:opacity-90 disabled:opacity-50">
        {{ busy ? 'Creating account…' : 'Create account' }}
      </button>

      <p class="text-center text-sm text-muted">
        Have an account? <NuxtLink to="/login" class="text-accent hover:underline">Log in</NuxtLink>
      </p>
    </form>
  </main>
</template>
