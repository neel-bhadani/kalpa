import Echo from 'laravel-echo'
import Pusher from 'pusher-js'

// Live updates over Reverb (websockets through Caddy, same origin). One connection per tab;
// channels are private and authorized by the API (/api/broadcasting/auth).

type Handler = (payload: any) => void

let echo: Echo<'reverb'> | null = null
// How many listeners each channel has, so a channel is left when its last listener goes.
const listeners = new Map<string, number>()

function connect(): Echo<'reverb'> {
  if (echo) return echo

  const api = useApi()
  const secure = location.protocol === 'https:'
  const port = Number(location.port || (secure ? 443 : 80))

  echo = new Echo({
    broadcaster: 'reverb',
    key: useRuntimeConfig().public.reverbKey as string,
    wsHost: location.hostname,
    wsPort: port,
    wssPort: port,
    forceTLS: secure,
    enabledTransports: ['ws', 'wss'],
    Pusher,
    // Authorize with the API client, so the session cookie and CSRF header are sent.
    authorizer: (channel: { name: string }) => ({
      authorize: (socketId: string, callback: (error: Error | null, data: any) => void) => {
        api('/api/broadcasting/auth', { method: 'POST', body: { socket_id: socketId, channel_name: channel.name } })
          .then(data => callback(null, data))
          .catch(error => callback(error, null))
      },
    }),
  })

  return echo
}

export function useRealtime() {
  // Listen to an event (e.g. "TaskUpdated") on a private channel (e.g. "task.<id>"). Returns a stop function.
  function listen(channel: string, event: string, handler: Handler): () => void {
    const connection = connect()
    connection.private(channel).listen(`.${event}`, handler)
    listeners.set(channel, (listeners.get(channel) ?? 0) + 1)

    return () => {
      connection.private(channel).stopListening(`.${event}`, handler)
      const left = (listeners.get(channel) ?? 1) - 1
      if (left <= 0) {
        listeners.delete(channel)
        connection.leave(channel)
      } else {
        listeners.set(channel, left)
      }
    }
  }

  function disconnect() {
    echo?.disconnect()
    echo = null
    listeners.clear()
  }

  return { listen, disconnect }
}

// Listen while the calling component is mounted.
export function useLiveEvent(channel: () => string | null, event: string, handler: Handler) {
  const { listen } = useRealtime()
  let stop: (() => void) | null = null

  watch(channel, (name) => {
    stop?.()
    stop = name ? listen(name, event, handler) : null
  }, { immediate: true })

  onBeforeUnmount(() => stop?.())
}

// Listen on several channels at once (e.g. one per visible project) while mounted.
export function useLiveEvents(channels: () => string[], event: string, handler: Handler) {
  const { listen } = useRealtime()
  const stops = new Map<string, () => void>()

  watch(channels, (names) => {
    for (const [name, stop] of stops) {
      if (!names.includes(name)) {
        stop()
        stops.delete(name)
      }
    }
    for (const name of names) {
      if (!stops.has(name)) stops.set(name, listen(name, event, handler))
    }
  }, { immediate: true })

  onBeforeUnmount(() => {
    for (const stop of stops.values()) stop()
    stops.clear()
  })
}
