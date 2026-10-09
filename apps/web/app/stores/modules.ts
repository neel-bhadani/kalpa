import { defineStore } from 'pinia'
import { modules as clientModules, type ClientModule } from '@kalpa/core'
import type { ServerModule } from '~/types/api'

// Modules for the current workspace: the client registry (editors, order) joined with
// what the server says (enabled, task kinds).
export const useModulesStore = defineStore('modules', () => {
  const api = useApi()

  const server = ref<ServerModule[]>([])
  const loaded = ref(false)

  // Client modules the server has turned on, in registry (sidebar) order.
  const enabled = computed<ClientModule[]>(() =>
    clientModules.filter(module => server.value.some(s => s.key === module.key && s.enabled)),
  )

  async function load() {
    server.value = (await api<{ data: ServerModule[] }>('/api/v1/modules')).data
    loaded.value = true
  }

  function serverModule(key: string): ServerModule | undefined {
    return server.value.find(module => module.key === key)
  }

  async function setEnabled(key: string, on: boolean) {
    const { data } = await api<{ data: ServerModule }>(`/api/v1/modules/${key}`, {
      method: 'PATCH',
      body: { enabled: on },
    })
    server.value = server.value.map(module => (module.key === key ? data : module))
  }

  function reset() {
    server.value = []
    loaded.value = false
  }

  return { server, loaded, enabled, load, serverModule, setEnabled, reset }
})
