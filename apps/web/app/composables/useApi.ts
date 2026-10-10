// Fetch client for the Laravel API. Same origin through Caddy, so the session
// cookie is sent automatically; Laravel's CSRF check needs the X-XSRF-TOKEN header,
// workspace-scoped routes need X-Workspace-Id, and X-Device-Id names this device.
export function useApi() {
  return $fetch.create({
    headers: { Accept: 'application/json' },
    onRequest({ options }) {
      const token = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/)?.[1]
      if (token) {
        options.headers.set('X-XSRF-TOKEN', decodeURIComponent(token))
      }

      // Workspace-scoped routes need to know which workspace we're in.
      const workspaceId = useAuthStore().currentWorkspace?.id
      if (workspaceId) {
        options.headers.set('X-Workspace-Id', workspaceId)
      }

      // Which of my devices is asking (recorded with each change, for "changed on <device>").
      const deviceId = localStorage.getItem(DEVICE_ID_KEY)
      if (deviceId) {
        options.headers.set('X-Device-Id', deviceId)
      }
    },
  })
}

// Sets the XSRF-TOKEN cookie. Call before register or login.
export function fetchCsrfCookie() {
  return $fetch('/sanctum/csrf-cookie')
}

// This device's id on the server (user_devices.id), saved when the device registers.
export const DEVICE_ID_KEY = 'kalpa.device_id'

// The request never got an answer: offline, or the server is unreachable.
export function isNetworkError(error: unknown): boolean {
  const { status, statusCode } = (error ?? {}) as { status?: number; statusCode?: number }
  return !status && !statusCode
}
