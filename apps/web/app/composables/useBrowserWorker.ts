import { collectDeviceProfile } from '@kalpa/core'
import { BrowserWorker, demoCount, type JobHandler, type WorkerApi } from '@kalpa/core/jobs'
import { clientId } from '~/stores/devices'

// This tab as a compute worker: runs one device job at a time (see packages/core/src/jobs).
let worker: BrowserWorker | null = null
let stopListening: (() => void) | null = null

// Job types this browser can run. demo.count only exists in development (APP_ENV=local).
function handlers(devTools: boolean): Record<string, JobHandler> {
  return devTools ? { 'demo.count': demoCount } : {}
}

export function useBrowserWorker() {
  async function start() {
    const auth = useAuthStore()
    if (worker || !auth.currentWorkspace || auth.currentWorkspace.role === 'viewer') return

    const api = useApi()
    await useDevicesStore().registerCurrent()

    const profile = await collectDeviceProfile()
    const capabilities = [profile.webgl2 && 'webgl2', profile.webgpu && 'webgpu'].filter(Boolean) as string[]

    const { data } = await api<{ data: { id: string } }>('/api/v1/workers/browser', {
      method: 'POST',
      body: { client_id: clientId(), capabilities },
    })
    const headers = { 'X-Worker-Id': data.id }

    const workerApi: WorkerApi = {
      claim: async caps => (await api<{ data: any }>('/api/v1/workers/claim', { method: 'POST', headers, body: { capabilities: caps } })).data,
      heartbeat: async (id, body) => (await api<{ data: { status: string } }>(`/api/v1/jobs/${id}/heartbeat`, { method: 'POST', headers, body })).data,
      complete: async (id, output) => { await api(`/api/v1/jobs/${id}/complete`, { method: 'POST', headers, body: { output } }) },
      fail: async (id, error) => { await api(`/api/v1/jobs/${id}/fail`, { method: 'POST', headers, body: { error } }) },
    }

    worker = new BrowserWorker({
      api: workerApi,
      handlers: handlers(auth.devTools),
      capabilities,
      onError: error => console.warn('[kalpa worker]', error),
    })
    worker.start()

    // One of my device jobs was just queued (from any tab or the API): this tab may take it now.
    stopListening = useRealtime().listen(`user.${auth.user!.id}`, 'JobUpdated', (update: { status: string; target: string }) => {
      if (update.status === 'queued' && update.target === 'device') worker?.poke()
    })
  }

  function stop() {
    stopListening?.()
    stopListening = null
    worker?.stop()
    worker = null
  }

  return { start, stop }
}
