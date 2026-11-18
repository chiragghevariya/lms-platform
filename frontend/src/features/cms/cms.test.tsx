import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { HelmetProvider } from 'react-helmet-async'
import { MemoryRouter } from 'react-router-dom'
import { Seo } from '@/components/Seo'
import HomePage from '@/features/marketing/pages/HomePage'
import { http } from '@/lib/api-client'
import AdminSettingsPage from './admin/AdminSettingsPage'
import { settingsPayload } from './admin/utils'
import { cmsApi } from './api'
import { SiteBanner } from './components/SiteBanner'
import type { SettingsResponse, SiteInfo } from './types'

const wrap = (node: React.ReactNode) =>
  render(
    <HelmetProvider>
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <MemoryRouter>{node}</MemoryRouter>
      </QueryClientProvider>
    </HelmetProvider>,
  )

const site = (o: Partial<SiteInfo> = {}): SiteInfo => ({
  brand: {
    name: 'SkillForge',
    tagline: 'Learn. Earn.',
    logo_url: null,
    primary_color: null,
    support_email: null,
  },
  seo: {
    default_title: null,
    default_description: null,
    og_image_url: null,
    twitter_handle: null,
    allow_indexing: true,
  },
  tracking: { ga4_measurement_id: null, meta_pixel_id: null },
  banner: null,
  ...o,
})

beforeEach(() => {
  // Anything not mocked explicitly fails fast instead of hitting the network.
  vi.spyOn(http, 'get').mockRejectedValue(new Error('offline'))
  localStorage.clear()
})
afterEach(() => vi.restoreAllMocks())

describe('Seo', () => {
  it('adds noindex everywhere when the admin turns indexing off, and uses the site name', async () => {
    vi.spyOn(cmsApi, 'site').mockResolvedValue(
      site({ seo: { ...site().seo, allow_indexing: false, twitter_handle: '@skillforge' } }),
    )
    wrap(<Seo title="Courses" />)

    await waitFor(() =>
      expect(document.querySelector('meta[name="robots"]')?.getAttribute('content')).toBe(
        'noindex, nofollow',
      ),
    )
    expect(document.title).toBe('Courses · SkillForge')
    expect(document.querySelector('meta[name="twitter:site"]')?.getAttribute('content')).toBe('@skillforge')
  })

  it('works without a QueryClient (build-time defaults)', async () => {
    render(
      <HelmetProvider>
        <Seo title="Plain" />
      </HelmetProvider>,
    )
    await waitFor(() => expect(document.title).toMatch(/^Plain · /))
  })
})

describe('settings payload', () => {
  it('never sends blank secrets and only clears untouched ones', () => {
    expect(
      settingsPayload({ stripe_key: 'pk_test_1' }, { stripe_secret: '', stripe_webhook_secret: '  ' }, []),
    ).toEqual({
      stripe_key: 'pk_test_1',
    })
    expect(
      settingsPayload({}, { stripe_secret: 'sk_new' }, ['stripe_secret', 'razorpay_key_secret']),
    ).toEqual({
      stripe_secret: 'sk_new',
      clear: ['razorpay_key_secret'],
    })
  })

  it('saves the payments form without the secret when it was left blank', async () => {
    const secret = { set: true, last4: '6789', source: 'admin' as const }
    const data: SettingsResponse = {
      can_manage_secrets: true,
      webhooks: {
        stripe: 'https://api.test/api/v1/webhooks/stripe',
        razorpay: 'https://api.test/api/v1/webhooks/razorpay',
      },
      values: {
        branding: {
          name: 'SkillForge',
          tagline: null,
          logo_url: null,
          primary_color: null,
          support_email: null,
        },
        seo: {
          default_title: null,
          default_description: null,
          og_image_url: null,
          twitter_handle: null,
          allow_indexing: true,
        },
        tax: { rates: [], seller_name: null, seller_address: null, seller_tax_id: null },
        payments: {
          stripe_key: 'pk_live_abc',
          stripe_secret: secret,
          stripe_webhook_secret: { set: false, last4: null, source: null },
          razorpay_key_id: null,
          razorpay_key_secret: { set: false, last4: null, source: null },
          razorpay_webhook_secret: { set: false, last4: null, source: null },
        },
        tracking: {
          ga4_measurement_id: null,
          ga4_api_secret: { set: false, last4: null, source: null },
          meta_pixel_id: null,
          meta_capi_token: { set: false, last4: null, source: null },
          meta_test_event_code: null,
        },
      },
    }
    vi.spyOn(cmsApi, 'settings').mockResolvedValue(data)
    const save = vi
      .spyOn(cmsApi, 'saveSettings')
      .mockResolvedValue({ data: {} as never, meta: { changed: [] } })

    wrap(<AdminSettingsPage />)
    fireEvent.click(await screen.findByRole('tab', { name: 'Payment gateways' }))
    expect(screen.getByText(/Set · ••••6789 \(from admin\)/)).toBeInTheDocument()

    fireEvent.click(screen.getAllByRole('button', { name: 'Replace' })[0]!) // reveal, leave blank
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))

    await waitFor(() => expect(save).toHaveBeenCalled())
    expect(save.mock.calls[0]![0]).toBe('payments')
    expect(save.mock.calls[0]![1]).toEqual({ stripe_key: 'pk_live_abc', razorpay_key_id: null })
  })

  it('hides gateway keys from admins without the secrets permission', async () => {
    vi.spyOn(cmsApi, 'settings').mockResolvedValue({
      can_manage_secrets: false,
      webhooks: { stripe: '', razorpay: '' },
      values: {
        branding: { name: 'X', tagline: null, logo_url: null, primary_color: null, support_email: null },
        seo: {
          default_title: null,
          default_description: null,
          og_image_url: null,
          twitter_handle: null,
          allow_indexing: true,
        },
        tax: { rates: [], seller_name: null, seller_address: null, seller_tax_id: null },
        tracking: {
          ga4_measurement_id: null,
          ga4_api_secret: { set: true, last4: 'abcd', source: 'env' },
          meta_pixel_id: null,
          meta_capi_token: { set: false, last4: null, source: null },
          meta_test_event_code: null,
        },
      },
    })
    wrap(<AdminSettingsPage />)
    expect(await screen.findByRole('tab', { name: 'Branding' })).toBeInTheDocument()
    expect(screen.queryByRole('tab', { name: 'Payment gateways' })).not.toBeInTheDocument()

    fireEvent.click(screen.getByRole('tab', { name: 'Tracking' }))
    expect(screen.getAllByText('Only a super admin can change this.')).toHaveLength(2)
    expect(screen.queryByRole('button', { name: 'Replace' })).not.toBeInTheDocument()
  })
})

describe('homepage', () => {
  it('falls back to the built-in content when the CMS is unreachable', async () => {
    vi.spyOn(cmsApi, 'home').mockRejectedValue(new Error('down'))
    wrap(<HomePage />)

    expect(await screen.findByText('Build Income.')).toBeInTheDocument()
    expect(screen.getByText('How does the membership work?')).toBeInTheDocument()
    // No placeholder testimonials.
    expect(screen.queryByText('What learners say')).not.toBeInTheDocument()
  })

  it('renders CMS sections in order, hides invisible ones, and shows published testimonials', async () => {
    vi.spyOn(cmsApi, 'home').mockResolvedValue({
      sections: [
        {
          key: 'testimonials',
          content: { eyebrow: 'Stories', title: 'Real results' },
          is_visible: true,
          sort: 0,
          customised: true,
        },
        {
          key: 'hero',
          content: { title: 'Master a skill.', highlight: 'Get paid.' },
          is_visible: true,
          sort: 1,
          customised: true,
        },
      ],
      faqs: [],
      testimonials: [
        {
          id: 't1',
          name: 'Asha',
          role: 'Designer',
          quote: 'Got my first client.',
          rating: 5,
          avatar_url: null,
        },
      ],
    })
    wrap(<HomePage />)

    expect(await screen.findByText('Get paid.')).toBeInTheDocument()
    const headings = screen.getAllByRole('heading', { level: 2 }).map((h) => h.textContent)
    expect(headings[0]).toBe('Real results')
    expect(screen.getByText('Got my first client.')).toBeInTheDocument()
    expect(screen.getByLabelText('5 out of 5 stars')).toBeInTheDocument()
    expect(screen.queryByText('Questions, answered')).not.toBeInTheDocument()
  })
})

describe('site banner', () => {
  it('remembers a dismissed banner', async () => {
    vi.spyOn(cmsApi, 'site').mockResolvedValue(
      site({
        banner: {
          id: 'b1',
          message: 'Live Q&A this Friday',
          link_text: 'See schedule',
          link_url: '/live',
          variant: 'promo',
        },
      }),
    )
    const first = wrap(<SiteBanner />)
    expect(await screen.findByText(/Live Q&A this Friday/)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'See schedule' })).toHaveAttribute('href', '/live')

    fireEvent.click(screen.getByRole('button', { name: 'Dismiss announcement' }))
    expect(screen.queryByText(/Live Q&A this Friday/)).not.toBeInTheDocument()
    expect(localStorage.getItem('banner-dismissed:b1')).toBe('1')
    first.unmount()

    wrap(<SiteBanner />)
    await waitFor(() => expect(cmsApi.site).toHaveBeenCalledTimes(2))
    expect(screen.queryByText(/Live Q&A this Friday/)).not.toBeInTheDocument()
  })
})
