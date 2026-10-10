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

type Profile = { user: User; workspaces: Workspace[]; features?: { dev_tools?: boolean } }

// The last profile from /me, so the app still opens offline.
const PROFILE_KEY = 'kalpa.profile'

export const useAuthStore = defineStore('auth', () => {
  const api = useApi()

  const user = ref<User | null>(null)
  const workspaces = ref<Workspace[]>([])
  // Development helpers (demo buttons, the fake brain): only when the API runs with APP_ENV=local.
  const devTools = ref(false)
  // True once we've asked the API who is signed in.
  const checked = ref(false)

  // The workspace sent with workspace-scoped API calls. The personal one for now.
  const currentWorkspace = computed(() => workspaces.value[0] ?? null)

  function setProfile(profile: Profile | null) {
    user.value = profile?.user ?? null
    workspaces.value = profile?.workspaces ?? []
    devTools.value = profile?.features?.dev_tools ?? false

    // Signed in (app start, login or register): check this device in, in the background.
    if (user.value) {
      void useDevicesStore().registerCurrent()
    }
  }

  async function fetchMe() {
    try {
      const { data } = await api<{ data: Profile }>('/api/v1/me')
      localStorage.setItem(PROFILE_KEY, JSON.stringify(data))
      setProfile(data)
    } catch (error) {
      // Offline (no answer at all): keep working as the last signed-in user. A 401 means signed out.
      const cached = localStorage.getItem(PROFILE_KEY)
      setProfile(isNetworkError(error) && cached ? JSON.parse(cached) : null)
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
    localStorage.removeItem(PROFILE_KEY)
    setProfile(null)
    useDevicesStore().reset()
    useModulesStore().reset()
    useSyncStore().stop()
    useBrowserWorker().stop()
    useRealtime().disconnect()
  }

  return { user, workspaces, currentWorkspace, devTools, checked, fetchMe, register, login, logout }
})
