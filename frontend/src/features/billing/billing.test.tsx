import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { MemoryRouter } from 'react-router-dom'
import { ComparisonTable } from '@/features/membership/components/ComparisonTable'
import { CourseCard } from '@/features/courses/components/CourseCard'
import type { CourseCard as CourseCardType } from '@/types/courses'
import { useAuthStore } from '@/stores/auth-store'
import { makeUser, member } from '@/test/fixtures'
import type { Plan, PlanPrice } from '@/types/api'
import type * as BillingModule from './api'
import { billingApi } from './api'
import { CheckoutDialog } from './components/CheckoutDialog'

vi.mock('./api', async (orig) => {
  const actual = await orig<typeof BillingModule>()
  return { ...actual, billingApi: { ...actual.billingApi, validateCoupon: vi.fn(), checkout: vi.fn() } }
})

const wrap = (ui: ReactNode) =>
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { mutations: { retry: false } } })}>
      <MemoryRouter>{ui}</MemoryRouter>
    </QueryClientProvider>,
  )

const money = (amount: number, currency = 'INR') => ({ amount, currency, formatted: `₹${amount / 100}.00` })
const price: PlanPrice = {
  id: 'price-1',
  interval: 'month',
  price: money(99900),
  currency: 'INR',
  trial_days: 0,
  is_active: true,
  gateway: 'razorpay',
}
const plan = (tier_level: 1 | 2 | 3, name: string, features: Plan['features']): Plan => ({
  id: name,
  name,
  slug: name.toLowerCase(),
  tier_level,
  tier: ({ 1: 'starter', 2: 'pro', 3: 'elite' } as const)[tier_level],
  description: `${name} plan`,
  features,
  is_active: true,
  sort: tier_level,
  prices: [price],
})

describe('ComparisonTable', () => {
  it('renders included / not included / text cells per tier', () => {
    wrap(
      <ComparisonTable
        plans={[
          plan(1, 'Starter', [{ key: 'live', label: 'Live classes', value: false }]),
          plan(2, 'Pro', [{ key: 'live', label: 'Live classes', value: 'Monthly' }]),
          plan(3, 'Elite', [{ key: 'live', label: 'Live classes', value: true }]),
        ]}
      />,
    )
    const row = screen.getByRole('row', { name: /live classes/i })
    expect(within(row).getByText('Not included')).toBeInTheDocument()
    expect(within(row).getByText('Monthly')).toBeInTheDocument()
    expect(within(row).getByText('Included')).toBeInTheDocument()
  })
})

describe('locked content', () => {
  const course: CourseCardType = {
    id: 'c1',
    slug: 'meta-ads',
    title: 'Meta Ads',
    subtitle: null,
    thumbnail_url: null,
    level: 'beginner',
    language: 'en',
    required_tier: 2,
    tier: 'pro',
    instructor: { id: 'i1', name: 'Meera', avatar_url: null },
    category: { slug: 'marketing', name: 'Marketing' },
    duration_minutes: 90,
    lessons_count: 10,
    rating_avg: 4.5,
    rating_count: 3,
    is_featured: false,
    price: null,
    status: 'published',
    published_at: null,
  }

  it('shows a lock and an "Upgrade to Pro" CTA below the tier', () => {
    useAuthStore.setState({ user: member('starter'), status: 'authenticated' })
    wrap(<CourseCard course={course} />)
    expect(screen.getByText(/locked — requires/i)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Upgrade to Pro' })).toHaveAttribute(
      'href',
      '/pricing?highlight=pro',
    )
  })

  it('unlocks for higher tiers and staff', () => {
    useAuthStore.setState({ user: member('elite'), status: 'authenticated' })
    const { unmount } = wrap(<CourseCard course={course} />)
    expect(screen.queryByText(/locked/i)).not.toBeInTheDocument()
    unmount()
    useAuthStore.setState({ user: makeUser({ roles: ['admin'], is_staff: true }), status: 'authenticated' })
    wrap(<CourseCard course={course} />)
    expect(screen.queryByText(/locked/i)).not.toBeInTheDocument()
  })
})

describe('CheckoutDialog', () => {
  it('applies a coupon, shows the new total and redirects to Stripe', async () => {
    const assign = vi.fn()
    vi.stubGlobal('location', { ...window.location, assign })
    vi.mocked(billingApi.validateCoupon).mockResolvedValueOnce({
      code: 'SAVE20',
      discount: money(19980),
      final_price: money(79920),
      applies_to: 'first_payment',
    })
    vi.mocked(billingApi.checkout).mockResolvedValueOnce({
      gateway: 'stripe',
      action: 'redirect',
      redirect_url: 'https://checkout.stripe.com/x',
      razorpay: null,
    })
    const user = userEvent.setup()
    wrap(<CheckoutDialog plan={plan(2, 'Pro', [])} price={price} onOpenChange={() => {}} />)

    await user.type(screen.getByLabelText('Coupon code'), 'save20')
    await user.click(screen.getByRole('button', { name: 'Apply' }))
    expect(await screen.findByText('Coupon SAVE20')).toBeInTheDocument()
    expect(screen.getByText('₹799.2')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Continue to payment' }))
    await waitFor(() => expect(assign).toHaveBeenCalledWith('https://checkout.stripe.com/x'))
    expect(billingApi.checkout).toHaveBeenCalledWith('price-1', 'SAVE20')
    vi.unstubAllGlobals()
  })
})
