// Fetch client for the Laravel API. Same origin through Caddy, so the session
// cookie is sent automatically; Laravel's CSRF check needs the X-XSRF-TOKEN header.
export function useApi() {
  return $fetch.create({
    headers: { Accept: 'application/json' },
    onRequest({ options }) {
      const token = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/)?.[1]
      if (token) {
        options.headers.set('X-XSRF-TOKEN', decodeURIComponent(token))
      }
    },
  })
}

// Sets the XSRF-TOKEN cookie. Call before register or login.
export function fetchCsrfCookie() {
  return $fetch('/sanctum/csrf-cookie')
}
