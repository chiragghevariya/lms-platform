import { QueryClientContext } from '@tanstack/react-query'
import { useContext, useEffect } from 'react'
import { Helmet } from 'react-helmet-async'
import { useSite } from '@/features/cms/api'
import { ensureTracking, pageView } from '@/features/cms/tracking'
import { useConsentStore } from '@/stores/consent-store'
import { env } from '@/lib/env'

interface SeoProps {
  title?: string
  description?: string
  path?: string
  noindex?: boolean
  image?: string
}

const DEFAULT_DESCRIPTION =
  'Learn in-demand skills with courses, live classes and mentors — and turn them into income. Membership plans from Starter to Elite.'

interface Defaults {
  siteName: string
  homeTitle: string
  description: string
  image: string
  twitter: string | null
  indexable: boolean
}

const STATIC_DEFAULTS: Defaults = {
  siteName: env.appName,
  homeTitle: `${env.appName} — ${env.tagline}`,
  description: DEFAULT_DESCRIPTION,
  image: '/og-image.svg',
  twitter: null,
  indexable: true,
}

/**
 * Per-page <head> tags: title, description, canonical, Open Graph and Twitter cards.
 * Site-wide defaults (brand, default title/description/image, indexing) come from the admin
 * Settings via GET /site when a QueryClient is available; otherwise the build-time defaults.
 */
export function Seo(props: SeoProps) {
  const client = useContext(QueryClientContext)
  return client ? <SeoWithSite {...props} /> : <SeoTags {...props} defaults={STATIC_DEFAULTS} />
}

function SeoWithSite(props: SeoProps) {
  const { data: site } = useSite()

  const consent = useConsentStore((s) => s.consent)
  useEffect(() => {
    if (!site || !consent) return
    // Only what the visitor agreed to; nothing loads before a choice is made.
    ensureTracking({
      ga4_measurement_id: consent.analytics ? site.tracking.ga4_measurement_id : null,
      meta_pixel_id: consent.marketing ? site.tracking.meta_pixel_id : null,
    })
    pageView(window.location.pathname + window.location.search)
  }, [site, consent, props.title, props.path])

  const name = site?.brand.name || env.appName
  const tagline = site?.brand.tagline || env.tagline
  const defaults: Defaults = {
    siteName: name,
    homeTitle: site?.seo.default_title || `${name} — ${tagline}`,
    description: site?.seo.default_description || DEFAULT_DESCRIPTION,
    image: site?.seo.og_image_url || STATIC_DEFAULTS.image,
    twitter: site?.seo.twitter_handle ?? null,
    indexable: site?.seo.allow_indexing ?? true,
  }
  return <SeoTags {...props} defaults={defaults} />
}

function SeoTags({ title, description, path, noindex, image, defaults }: SeoProps & { defaults: Defaults }) {
  const fullTitle = title ? `${title} · ${defaults.siteName}` : defaults.homeTitle
  const desc = description ?? defaults.description
  const img = image ?? defaults.image
  const origin = typeof window !== 'undefined' ? window.location.origin : ''
  const url = path !== undefined ? `${origin}${path}` : undefined
  const imageUrl = img.startsWith('http') ? img : `${origin}${img}`
  const robotsOff = noindex || !defaults.indexable

  return (
    <Helmet prioritizeSeoTags>
      <title>{fullTitle}</title>
      <meta name="description" content={desc} />
      {url && <link rel="canonical" href={url} />}
      {robotsOff && <meta name="robots" content="noindex, nofollow" />}
      <meta property="og:site_name" content={defaults.siteName} />
      <meta property="og:type" content="website" />
      <meta property="og:title" content={fullTitle} />
      <meta property="og:description" content={desc} />
      {url && <meta property="og:url" content={url} />}
      <meta property="og:image" content={imageUrl} />
      <meta name="twitter:card" content="summary_large_image" />
      {defaults.twitter && <meta name="twitter:site" content={defaults.twitter} />}
      <meta name="twitter:title" content={fullTitle} />
      <meta name="twitter:description" content={desc} />
    </Helmet>
  )
}
