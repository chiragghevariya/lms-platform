/**
 * Cloudflare Turnstile token hand-off: the widget stores its (single-use) token here and the
 * API client attaches it as X-Captcha-Token to the protected endpoints, then asks for a new one.
 */
const PROTECTED = ['/auth/login', '/auth/register', '/auth/forgot-password', '/contact']

let token: string | null = null
let reset: (() => void) | null = null

export const captcha = {
  set(value: string | null, resetFn?: () => void) {
    token = value
    if (resetFn) reset = resetFn
  },
  /** Called by the API client for each request; returns the token once. */
  take(url: string | undefined): string | null {
    if (!url || !PROTECTED.some((p) => url.endsWith(p)) || !token) return null
    const t = token
    token = null
    queueMicrotask(() => reset?.())
    return t
  },
  clear() {
    token = null
    reset = null
  },
}
