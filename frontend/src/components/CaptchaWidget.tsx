import { useEffect, useRef } from 'react'
import { useSite } from '@/features/cms/api'
import { captcha } from '@/lib/captcha'

interface Turnstile {
  render: (el: HTMLElement, opts: Record<string, unknown>) => string
  reset: (id?: string) => void
  remove: (id?: string) => void
}
declare global {
  interface Window {
    turnstile?: Turnstile
  }
}

let scriptPromise: Promise<void> | null = null
function loadScript() {
  scriptPromise ??= new Promise((resolve, reject) => {
    const s = document.createElement('script')
    s.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit'
    s.async = true
    s.onload = () => resolve()
    s.onerror = () => reject(new Error('Turnstile failed to load'))
    document.head.appendChild(s)
  })
  return scriptPromise
}

/** Bot check for public forms. Renders nothing unless the site has Turnstile enabled. */
export function CaptchaWidget() {
  const { data: site } = useSite()
  const siteKey = site?.captcha?.site_key ?? null
  const ref = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (!siteKey || !ref.current) return
    let id: string | undefined
    let cancelled = false
    loadScript()
      .then(() => {
        if (cancelled || !ref.current || !window.turnstile) return
        id = window.turnstile.render(ref.current, {
          sitekey: siteKey,
          theme: 'auto',
          size: 'flexible',
          callback: (t: string) => captcha.set(t, () => window.turnstile?.reset(id)),
          'expired-callback': () => captcha.set(null),
          'error-callback': () => captcha.set(null),
        })
      })
      .catch(() => captcha.set(null))
    return () => {
      cancelled = true
      captcha.clear()
      if (id) window.turnstile?.remove(id)
    }
  }, [siteKey])

  if (!siteKey) return null
  return <div ref={ref} className="min-h-[65px]" aria-label="Human verification" />
}
