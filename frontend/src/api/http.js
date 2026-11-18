import axios from 'axios'

export const DEMO_MODE = import.meta.env.VITE_DEMO_MODE === 'true'

const TOKEN_KEY = 'buildlead-token'

export const token = {
  get: () => {
    try { return localStorage.getItem(TOKEN_KEY) } catch { return null }
  },
  set: (value) => {
    try { localStorage.setItem(TOKEN_KEY, value) } catch { /* private mode */ }
  },
  clear: () => {
    try { localStorage.removeItem(TOKEN_KEY) } catch { /* private mode */ }
  },
}

export const http = axios.create({
  baseURL: import.meta.env.VITE_API_URL || '/api',
  headers: { Accept: 'application/json' },
  timeout: 25_000,
})

// Demo mode: same API surface, answered by src/mocks (lazy-loaded, never in the real bundle path).
if (DEMO_MODE) {
  http.defaults.adapter = async (config) => (await import('@/mocks')).mockAdapter(config)
}

http.interceptors.request.use((config) => {
  const t = token.get()
  if (t) config.headers.Authorization = `Bearer ${t}`
  return config
})

let unauthorizedHandler = null
export const onUnauthorized = (fn) => { unauthorizedHandler = fn }

/** Human message per failure type. Server errors never leak exception text into the UI. */
function messageFor(error, status, data) {
  // No response, or the dev proxy / load balancer could not reach Laravel (empty 5xx body, 502, 504).
  const unreachable = status === 502 || status === 504 || (status >= 500 && !error.response?.data)
  if (!error.response || unreachable) {
    return error.code === 'ECONNABORTED' ? 'The server took too long to respond. Please try again.' : 'Cannot reach the server. Check your connection or that the API is running.'
  }
  if (status >= 500) return status === 503 ? 'The service is briefly unavailable (maintenance). Please try again shortly.' : 'The server ran into a problem. Please try again.'
  if (status === 429) return 'Too many requests. Please wait a moment.'
  if (status === 403) return data.message && data.message !== 'This action is unauthorized.' ? data.message : "You don't have permission to do that."
  if (status === 404) return 'That record no longer exists.'
  return data.message || 'Something went wrong.'
}

/** Errors carry a human message and Laravel validation errors (field → [messages]). */
http.interceptors.response.use(
  (response) => response,
  (error) => {
    const status = error.response?.status
    const data = error.response?.data ?? {}

    if (status === 401 && !String(error.config?.url).includes('/auth/login')) {
      token.clear()
      unauthorizedHandler?.()
    }

    error.status = status
    error.errors = data.errors ?? {}
    error.message = messageFor(error, status, data)

    return Promise.reject(error)
  },
)
