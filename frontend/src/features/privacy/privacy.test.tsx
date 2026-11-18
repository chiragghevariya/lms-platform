import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen } from '@testing-library/react'
import { cmsApi } from '@/features/cms/api'
import { captcha } from '@/lib/captcha'
import { useConsentStore } from '@/stores/consent-store'
import { CookieConsent } from './CookieConsent'

afterEach(() => {
  vi.restoreAllMocks()
  useConsentStore.setState({ consent: null, open: false })
  localStorage.clear()
})

const site = (tracking: { ga4_measurement_id: string | null; meta_pixel_id: string | null }) =>
  ({
    brand: { name: 'Brand', tagline: '', logo_url: null, primary_color: null, support_email: null },
    seo: {
      default_title: null,
      default_description: null,
      og_image_url: null,
      twitter_handle: null,
      allow_indexing: true,
    },
    captcha: { provider: null, site_key: null },
    tracking,
    banner: null,
  }) as Awaited<ReturnType<typeof cmsApi.site>>

const wrap = (node: React.ReactNode) =>
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      {node}
    </QueryClientProvider>,
  )

describe('captcha token hand-off', () => {
  it('attaches a token once, and only to protected endpoints', () => {
    const reset = vi.fn()
    captcha.set('tok-1', reset)
    expect(captcha.take('/courses')).toBeNull()
    expect(captcha.take('/auth/login')).toBe('tok-1')
    expect(captcha.take('/auth/login')).toBeNull() // single use
    captcha.clear()
  })
})

describe('cookie consent', () => {
  it('stays hidden when the site uses no analytics or marketing cookies', async () => {
    vi.spyOn(cmsApi, 'site').mockResolvedValue(site({ ga4_measurement_id: null, meta_pixel_id: null }))
    wrap(<CookieConsent />)
    await new Promise((r) => setTimeout(r, 20))
    expect(screen.queryByRole('dialog', { name: /cookies/i })).toBeNull()
  })

  it('asks before loading GA4, and "Reject all" is remembered', async () => {
    vi.spyOn(cmsApi, 'site').mockResolvedValue(site({ ga4_measurement_id: 'G-TEST123', meta_pixel_id: null }))
    wrap(<CookieConsent />)
    expect(await screen.findByRole('dialog', { name: /cookies/i })).toHaveTextContent(/Google Analytics/)
    fireEvent.click(screen.getByRole('button', { name: 'Reject all' }))
    expect(useConsentStore.getState().consent).toMatchObject({ analytics: false, marketing: false })
    expect(JSON.parse(localStorage.getItem('lms_consent_v1') ?? '{}')).toMatchObject({ analytics: false })
    expect(screen.queryByRole('dialog', { name: /cookies/i })).toBeNull()
  })

  it('lets visitors choose categories individually', async () => {
    vi.spyOn(cmsApi, 'site').mockResolvedValue(
      site({ ga4_measurement_id: 'G-TEST123', meta_pixel_id: '1234567890' }),
    )
    wrap(<CookieConsent />)
    fireEvent.click(await screen.findByRole('button', { name: 'Customise' }))
    fireEvent.click(screen.getByRole('checkbox', { name: /Analytics/ }))
    fireEvent.click(screen.getByRole('button', { name: 'Save choices' }))
    expect(useConsentStore.getState().consent).toMatchObject({ analytics: true, marketing: false })
  })
})
