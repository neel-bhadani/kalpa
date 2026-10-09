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

  // The workspace sent with workspace-scoped API calls. The personal one for now.
  const currentWorkspace = computed(() => workspaces.value[0] ?? null)

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
      setProfile((await api<{ data: Profile }>('/api/v1/me')).data)
    } catch {
      setProfile(null)
    }
    checked.value = true
  }

  async function register(name: string, email: string, password: string) {
    await fetchCsrfCookie()
    const { data } = await api<{ data: Profile }>('/api/v1/auth/register', {
      method: 'POST',
      body: { name, email, password },
    })
    setProfile(data)
  }

  async function login(email: string, password: string) {
    await fetchCsrfCookie()
    const { data } = await api<{ data: Profile }>('/api/v1/auth/login', {
      method: 'POST',
      body: { email, password },
    })
    setProfile(data)
  }

  async function logout() {
    await api('/api/v1/auth/logout', { method: 'POST' })
    setProfile(null)
    useDevicesStore().reset()
    useModulesStore().reset()
  }

  return { user, workspaces, currentWorkspace, checked, fetchMe, register, login, logout }
})
