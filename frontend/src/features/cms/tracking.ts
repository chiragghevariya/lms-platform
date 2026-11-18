/**
 * Optional marketing analytics: GA4 (gtag.js) and the Meta Pixel, loaded only when an admin
 * has set their public IDs in Settings → Tracking. Purchases are reported server-side
 * (Measurement Protocol / Conversions API), so the browser never sends a purchase event.
 */
type Gtag = (...args: unknown[]) => void
type Fbq = ((...args: unknown[]) => void) & {
  queue?: unknown[]
  loaded?: boolean
  version?: string
  callMethod?: unknown
}

declare global {
  interface Window {
    dataLayer?: unknown[]
    gtag?: Gtag
    fbq?: Fbq
    _fbq?: Fbq
  }
}

export type TrackEvent = 'sign_up' | 'begin_checkout'

const META_EVENT: Record<TrackEvent, string> = {
  sign_up: 'CompleteRegistration',
  begin_checkout: 'InitiateCheckout',
}

let ga4Id: string | null = null
let pixelId: string | null = null
let lastPath: string | null = null

function addScript(src: string) {
  const s = document.createElement('script')
  s.async = true
  s.src = src
  document.head.appendChild(s)
}

/** Idempotent: loads each provider at most once per page load. */
export function ensureTracking(ids: { ga4_measurement_id: string | null; meta_pixel_id: string | null }) {
  if (typeof window === 'undefined') return

  const ga4 = ids.ga4_measurement_id
  if (ga4 && !ga4Id && /^G-[A-Z0-9]{4,12}$/.test(ga4)) {
    ga4Id = ga4
    window.dataLayer = window.dataLayer ?? []
    window.gtag = function gtag() {
      // gtag.js reads the raw `arguments` object from dataLayer.
      // eslint-disable-next-line prefer-rest-params
      window.dataLayer!.push(arguments)
    }
    window.gtag('js', new Date())
    // SPA: page views are sent manually on route change.
    window.gtag('config', ga4, { send_page_view: false })
    addScript(`https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(ga4)}`)
  }

  const pixel = ids.meta_pixel_id
  if (pixel && !pixelId && /^\d{6,20}$/.test(pixel)) {
    pixelId = pixel
    const fbq: Fbq = function fbq(...args: unknown[]) {
      fbq.queue!.push(args)
    }
    fbq.queue = []
    fbq.loaded = true
    fbq.version = '2.0'
    window.fbq = fbq
    window._fbq = fbq
    addScript('https://connect.facebook.net/en_US/fbevents.js')
    fbq('init', pixel)
  }

  lastPath = null // page view for the current page is sent by pageView()
}

/** Sends one page view per distinct path (several <Seo> may render for one page). */
export function pageView(path: string) {
  if (path === lastPath) return
  lastPath = path
  if (ga4Id) window.gtag?.('event', 'page_view', { page_path: path, page_location: window.location.href })
  if (pixelId) window.fbq?.('track', 'PageView')
}

export function track(event: TrackEvent, params: Record<string, unknown> = {}) {
  if (typeof window === 'undefined') return
  if (ga4Id) window.gtag?.('event', event, params)
  if (pixelId) window.fbq?.('track', META_EVENT[event], params)
}

/** Test helper. */
export function resetTracking() {
  ga4Id = null
  pixelId = null
  lastPath = null
}
