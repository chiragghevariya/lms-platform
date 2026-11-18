import axios, { type AxiosError, type AxiosInstance } from 'axios'
import { captcha } from '@/lib/captcha'
import { env } from '@/lib/env'
import type { ApiErrorBody } from '@/types/api'

/**
 * Typed API error with Laravel validation errors flattened per field.
 */
export class ApiError extends Error {
  readonly status: number
  readonly errors: Record<string, string[]>
  readonly code?: string
  readonly body?: ApiErrorBody

  constructor(status: number, body?: ApiErrorBody) {
    super(body?.message || 'Something went wrong. Please try again.')
    this.name = 'ApiError'
    this.status = status
    this.errors = body?.errors ?? {}
    this.code = body?.code
    this.body = body
  }

  get isValidation() {
    return this.status === 422
  }
  get isUnauthenticated() {
    return this.status === 401
  }
  get isMembershipRequired() {
    return this.status === 402
  }
}

type UnauthorizedHandler = (code?: string) => void
let onUnauthorized: UnauthorizedHandler | null = null
export const setUnauthorizedHandler = (fn: UnauthorizedHandler | null) => {
  onUnauthorized = fn
}

export const http: AxiosInstance = axios.create({
  baseURL: `${env.apiUrl}/api/v1`,
  withCredentials: true, // Sanctum SPA cookies
  withXSRFToken: true,
  xsrfCookieName: 'XSRF-TOKEN',
  xsrfHeaderName: 'X-XSRF-TOKEN',
  headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
})

/** Random per-browser id (localStorage). Only its keyed hash is stored server-side, for referral fraud checks. */
export function deviceId(): string | null {
  try {
    let id = localStorage.getItem('lms_device')
    if (!id) {
      id = crypto.randomUUID()
      localStorage.setItem('lms_device', id)
    }
    return id
  } catch {
    return null
  }
}

http.interceptors.request.use((config) => {
  const id = deviceId()
  if (id) config.headers.set('X-Device-Id', id)
  const human = captcha.take(config.url)
  if (human) config.headers.set('X-Captcha-Token', human)
  return config
})

http.interceptors.response.use(
  (res) => res,
  (error: AxiosError<ApiErrorBody>) => {
    const status = error.response?.status ?? 0
    // 419 = CSRF token mismatch (session expired) — treat like logged out.
    if ((status === 401 || status === 419) && onUnauthorized) onUnauthorized(error.response?.data?.code)
    return Promise.reject(new ApiError(status, error.response?.data))
  },
)

let csrfPromise: Promise<unknown> | null = null
/** Primes the XSRF-TOKEN cookie before state-changing auth calls. */
export function ensureCsrfCookie() {
  csrfPromise ??= axios.get(`${env.apiUrl}/sanctum/csrf-cookie`, { withCredentials: true }).finally(() => {
    csrfPromise = null
  })
  return csrfPromise
}

/** Unwraps `{ data }` envelopes. */
export async function getData<T>(url: string, params?: Record<string, unknown>): Promise<T> {
  const res = await http.get<{ data: T }>(url, { params })
  return res.data.data
}
