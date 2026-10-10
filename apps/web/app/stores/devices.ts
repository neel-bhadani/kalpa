import { defineStore } from 'pinia'
import { collectDeviceProfile, computeTier, type DeviceTier } from '@kalpa/core'

export type Device = {
  id: string
  client_id: string
  platform: 'web' | 'desktop' | 'ios' | 'android'
  name: string
  capabilities: { gpu?: string | null } & Record<string, unknown>
  tier: DeviceTier
  tier_override: DeviceTier | null
  last_seen_at: string | null
}

const CLIENT_ID_KEY = 'kalpa.client_id'

// One id per browser, made once and kept in localStorage.
export function clientId(): string {
  let id = localStorage.getItem(CLIENT_ID_KEY)
  if (!id) {
    id = crypto.randomUUID()
    localStorage.setItem(CLIENT_ID_KEY, id)
  }
  return id
}

// A readable default name like "Chrome on Linux". The user can rename it.
function defaultName(): string {
  const ua = navigator.userAgent
  const browser = /Edg\//.test(ua) ? 'Edge'
    : /Firefox\//.test(ua) ? 'Firefox'
      : /Chrome\//.test(ua) ? 'Chrome'
        : /Safari\//.test(ua) ? 'Safari'
          : 'Browser'
  const os = /Android/.test(ua) ? 'Android'
    : /iPhone|iPad/.test(ua) ? 'iOS'
      : /Windows/.test(ua) ? 'Windows'
        : /Mac OS X/.test(ua) ? 'macOS'
          : /Linux/.test(ua) ? 'Linux'
            : 'unknown OS'
  return `${browser} on ${os}`
}

export const useDevicesStore = defineStore('devices', () => {
  const api = useApi()

  const devices = ref<Device[]>([])
  const currentClientId = ref<string | null>(null)
  // This device on the server (user_devices), once registered.
  const deviceId = ref<string | null>(localStorage.getItem(DEVICE_ID_KEY))
  const deviceName = ref<string>(defaultName())
  let registered = false

  // Tell the API about this device (or refresh last_seen). Runs once per app start.
  async function registerCurrent() {
    if (registered) {
      return
    }
    registered = true

    const profile = await collectDeviceProfile()
    currentClientId.value = clientId()

    try {
      const { data } = await api<{ data: Device }>('/api/v1/devices', {
        method: 'POST',
        body: {
          client_id: currentClientId.value,
          // Desktop and mobile builds will report their own platform later.
          platform: 'web',
          name: defaultName(),
          capabilities: profile,
          tier: computeTier(profile),
        },
      })
      localStorage.setItem(DEVICE_ID_KEY, data.id)
      deviceId.value = data.id
      deviceName.value = data.name
    } catch {
      // Not critical; try again on the next app start.
      registered = false
    }
  }

  async function fetchAll() {
    currentClientId.value = clientId()
    devices.value = (await api<{ data: Device[] }>('/api/v1/devices')).data
  }

  async function setTierOverride(id: string, tierOverride: DeviceTier | null) {
    const { data: updated } = await api<{ data: Device }>(`/api/v1/devices/${id}`, {
      method: 'PATCH',
      body: { tier_override: tierOverride },
    })
    devices.value = devices.value.map(device => (device.id === id ? updated : device))
  }

  async function remove(id: string) {
    await api(`/api/v1/devices/${id}`, { method: 'DELETE' })
    devices.value = devices.value.filter(device => device.id !== id)
  }

  function reset() {
    devices.value = []
    registered = false
  }

  return { devices, currentClientId, deviceId, deviceName, registerCurrent, fetchAll, setTierOverride, remove, reset }
})
