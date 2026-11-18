import { Cookie } from 'lucide-react'
import { useState } from 'react'
import { Button } from '@/components/ui/button'
import { useSite } from '@/features/cms/api'
import { useConsentStore } from '@/stores/consent-store'

/**
 * Asks before loading analytics/marketing scripts. Shown only when the site actually uses
 * them (GA4 / Meta Pixel configured); "Reject" is as easy as "Accept".
 */
export function CookieConsent() {
  const { data: site } = useSite()
  const { consent, open, decide } = useConsentStore()
  const [custom, setCustom] = useState(false)
  const [analytics, setAnalytics] = useState(consent?.analytics ?? false)
  const [marketing, setMarketing] = useState(consent?.marketing ?? false)

  const usesAnalytics = !!site?.tracking.ga4_measurement_id
  const usesMarketing = !!site?.tracking.meta_pixel_id
  if (!usesAnalytics && !usesMarketing) return null
  if (consent && !open) return null

  return (
    <section
      role="dialog"
      aria-modal="false"
      aria-labelledby="cookie-title"
      className="bg-card text-card-foreground fixed inset-x-3 bottom-3 z-[70] mx-auto grid max-w-2xl gap-3 rounded-xl border p-4 shadow-xl sm:p-5"
    >
      <h2 id="cookie-title" className="flex items-center gap-2 font-semibold">
        <Cookie className="size-4" aria-hidden /> Cookies
      </h2>
      <p className="text-muted-foreground text-sm">
        We use essential cookies to keep you signed in. With your permission we'd also use{' '}
        {usesAnalytics && 'analytics cookies (Google Analytics) to improve the site'}
        {usesAnalytics && usesMarketing && ' and '}
        {usesMarketing && 'marketing cookies (Meta) to measure our ads'}. See our{' '}
        <a href="/privacy" className="text-primary underline">
          privacy policy
        </a>
        .
      </p>
      {custom && (
        <fieldset className="grid gap-2 text-sm">
          <legend className="sr-only">Choose cookies</legend>
          <label className="flex items-center gap-2">
            <input type="checkbox" checked disabled className="size-4" /> Essential (always on)
          </label>
          {usesAnalytics && (
            <label className="flex items-center gap-2">
              <input
                type="checkbox"
                className="size-4"
                checked={analytics}
                onChange={(e) => setAnalytics(e.target.checked)}
              />{' '}
              Analytics
            </label>
          )}
          {usesMarketing && (
            <label className="flex items-center gap-2">
              <input
                type="checkbox"
                className="size-4"
                checked={marketing}
                onChange={(e) => setMarketing(e.target.checked)}
              />{' '}
              Marketing
            </label>
          )}
        </fieldset>
      )}
      <div className="flex flex-wrap justify-end gap-2">
        {custom ? (
          <Button size="sm" onClick={() => decide({ analytics, marketing })}>
            Save choices
          </Button>
        ) : (
          <Button size="sm" variant="ghost" onClick={() => setCustom(true)}>
            Customise
          </Button>
        )}
        <Button size="sm" variant="outline" onClick={() => decide({ analytics: false, marketing: false })}>
          Reject all
        </Button>
        <Button size="sm" onClick={() => decide({ analytics: true, marketing: true })}>
          Accept all
        </Button>
      </div>
    </section>
  )
}
