import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen } from '@testing-library/react'
import { HelmetProvider } from 'react-helmet-async'
import { MemoryRouter } from 'react-router-dom'
import { ReferralCapture } from '@/components/ReferralCapture'
import { http } from '@/lib/api-client'
import type { AffiliateAccount } from '@/types/affiliate'
import { affiliateApi, shareLinks } from './api'
import AffiliatePage from './pages/AffiliatePage'
import InvitePage from './pages/InvitePage'

afterEach(() => {
  vi.restoreAllMocks()
  sessionStorage.clear()
})

const wrap = (node: React.ReactNode, path = '/') =>
  render(
    <HelmetProvider>
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <MemoryRouter initialEntries={[path]}>{node}</MemoryRouter>
      </QueryClientProvider>
    </HelmetProvider>,
  )

const account = (o: Partial<AffiliateAccount> = {}): AffiliateAccount => ({
  id: 'a1',
  code: 'RAVI123',
  status: 'approved',
  review_notes: null,
  link: 'https://brand.example/?ref=RAVI123',
  terms: {
    type: 'percent',
    rate: 20,
    fixed: null,
    recurring_months: 12,
    hold_days: 14,
    cookie_days: 60,
    attribution: 'last_touch',
  },
  payout: { method: null, summary: null, minimum: { INR: 100000, USD: 2500 } },
  balances: { INR: { pending: 16932, approved: 33864, paid: 0, in_payout: 0 } },
  ...o,
})

describe('referral capture', () => {
  it('records ?ref with UTM once per session', async () => {
    const post = vi.spyOn(http, 'post').mockResolvedValue({ data: null } as never)
    const { unmount } = wrap(
      <ReferralCapture />,
      '/pricing?ref=RAVI123&utm_source=youtube&utm_campaign=launch',
    )
    await vi.waitFor(() => expect(post).toHaveBeenCalledTimes(1))
    expect(post).toHaveBeenCalledWith(
      '/referrals/track',
      expect.objectContaining({
        ref: 'RAVI123',
        landing_url: '/pricing?ref=RAVI123&utm_source=youtube&utm_campaign=launch',
        utm: { utm_source: 'youtube', utm_campaign: 'launch' },
      }),
    )
    unmount()
    wrap(<ReferralCapture />, '/pricing?ref=RAVI123')
    expect(post).toHaveBeenCalledTimes(1)
  })

  it('ignores pages without a code', () => {
    const post = vi.spyOn(http, 'post')
    wrap(<ReferralCapture />, '/courses')
    expect(post).not.toHaveBeenCalled()
  })
})

describe('affiliate page', () => {
  it('shows the application form to non-affiliates', async () => {
    vi.spyOn(affiliateApi, 'me').mockResolvedValue(null)
    wrap(<AffiliatePage />)
    expect(await screen.findByRole('heading', { name: 'Join the affiliate programme' })).toBeInTheDocument()
    expect(screen.getByLabelText('How will you promote us?')).toBeInTheDocument()
  })

  it('shows the link, terms, balances and funnel to approved affiliates', async () => {
    vi.spyOn(affiliateApi, 'me').mockResolvedValue(account())
    vi.spyOn(affiliateApi, 'stats').mockResolvedValue({
      range_days: 30,
      funnel: {
        clicks: 200,
        unique_visitors: 150,
        signups: 20,
        conversions: 5,
        signup_rate: 10,
        conversion_rate: 25,
      },
      daily: [{ date: '2026-11-12', clicks: 10, signups: 1, conversions: 0 }],
      campaigns: [{ campaign: 'launch', clicks: 120 }],
    })
    wrap(<AffiliatePage />)

    expect(await screen.findByDisplayValue('https://brand.example/?ref=RAVI123')).toBeInTheDocument()
    expect(screen.getByText(/You earn 20% of each payment/)).toBeInTheDocument()
    expect(screen.getByText('₹338.64')).toBeInTheDocument() // payable
    expect(await screen.findByText('10% of clicks')).toBeInTheDocument()
    expect(screen.getByText('25% of sign-ups')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'WhatsApp' })).toHaveAttribute(
      'href',
      shareLinks(
        'https://brand.example/?ref=RAVI123',
        'I learn practical, income-earning skills here — join with my link:',
      ).whatsapp,
    )
  })

  it('builds UTM-tagged links that keep the ref code', async () => {
    vi.spyOn(affiliateApi, 'me').mockResolvedValue(account())
    vi.spyOn(affiliateApi, 'stats').mockResolvedValue({
      range_days: 30,
      funnel: {
        clicks: 0,
        unique_visitors: 0,
        signups: 0,
        conversions: 0,
        signup_rate: null,
        conversion_rate: null,
      },
      daily: [],
      campaigns: [],
    })
    wrap(<AffiliatePage />)
    fireEvent.click(await screen.findByRole('tab', { name: 'Link builder' }))
    fireEvent.change(screen.getByLabelText('Landing page'), { target: { value: '/pricing' } })
    fireEvent.change(screen.getByLabelText('Source'), { target: { value: 'instagram' } })
    fireEvent.change(screen.getByLabelText('Campaign'), { target: { value: 'diwali sale' } })
    expect(screen.getByLabelText('Tagged link')).toHaveValue(
      'https://brand.example/pricing?ref=RAVI123&utm_source=instagram&utm_campaign=diwali+sale',
    )
  })

  it('explains a pending application and a suspension', async () => {
    vi.spyOn(affiliateApi, 'me').mockResolvedValue(account({ status: 'pending', balances: null }))
    const { unmount } = wrap(<AffiliatePage />)
    expect(await screen.findByText('Application under review')).toBeInTheDocument()
    unmount()
    vi.spyOn(affiliateApi, 'me').mockResolvedValue(
      account({ status: 'suspended', review_notes: 'Spam detected.' }),
    )
    vi.spyOn(affiliateApi, 'stats').mockResolvedValue({
      range_days: 30,
      funnel: {
        clicks: 0,
        unique_visitors: 0,
        signups: 0,
        conversions: 0,
        signup_rate: null,
        conversion_rate: null,
      },
      daily: [],
      campaigns: [],
    })
    wrap(<AffiliatePage />)
    expect(await screen.findByText(/suspended — your link no longer tracks/)).toHaveTextContent(
      'Spam detected.',
    )
  })
})

describe('invite page', () => {
  it('shows the invite link, reward and statuses', async () => {
    vi.spyOn(affiliateApi, 'invite').mockResolvedValue({
      enabled: true,
      code: 'FABC1234',
      link: 'https://brand.example/register?invite=FABC1234',
      reward: { type: 'days', days: 30, credit: { INR: 50000 }, hold_days: 14 },
      stats: { invited: 2, subscribed: 1, days_earned: 30, days_banked: 0 },
      rewards: [
        {
          id: 'r1',
          friend: 'Priya',
          status: 'granted',
          type: 'days',
          days: 30,
          credit_amount: null,
          currency: null,
          available_at: null,
          created_at: '2026-11-01T00:00:00Z',
        },
      ],
    })
    wrap(<InvitePage />)
    expect(await screen.findByText(/Get 30 free days for every friend/)).toBeInTheDocument()
    expect(screen.getByDisplayValue('https://brand.example/register?invite=FABC1234')).toBeInTheDocument()
    expect(screen.getByText('Reward added')).toBeInTheDocument()
  })
})
