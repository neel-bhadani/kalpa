import { defineStore } from 'pinia'

export type User = {
  id: string
  name: string
  email: string
  locale: string
  settings: Record<string, unknown>
}

export type Workspace = {
  id: string
  name: string
  plan: string
  role: 'owner' | 'editor' | 'viewer'
}

type Profile = { user: User; workspaces: Workspace[] }

export const useAuthStore = defineStore('auth', () => {
  const api = useApi()

  const user = ref<User | null>(null)
  const workspaces = ref<Workspace[]>([])
  // True once we've asked the API who is signed in.
  const checked = ref(false)

  function setProfile(profile: Profile | null) {
    user.value = profile?.user ?? null
    workspaces.value = profile?.workspaces ?? []

    // Signed in (app start, login or register): check this device in, in the background.
    if (user.value) {
      void useDevicesStore().registerCurrent()
    }
  }

  async function fetchMe() {
    try {
      setProfile(await api<Profile>('/api/v1/me'))
    } catch {
      setProfile(null)
    }
    checked.value = true
  }

  async function register(name: string, email: string, password: string) {
    await fetchCsrfCookie()
    setProfile(await api<Profile>('/api/v1/auth/register', {
      method: 'POST',
      body: { name, email, password },
    }))
  }

  async function login(email: string, password: string) {
    await fetchCsrfCookie()
    setProfile(await api<Profile>('/api/v1/auth/login', {
      method: 'POST',
      body: { email, password },
    }))
  }

  async function logout() {
    await api('/api/v1/auth/logout', { method: 'POST' })
    setProfile(null)
    useDevicesStore().reset()
  }

  return { user, workspaces, checked, fetchMe, register, login, logout }
})
