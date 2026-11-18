import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { HelmetProvider } from 'react-helmet-async'
import { MemoryRouter } from 'react-router-dom'
import { useAuthStore } from '@/stores/auth-store'
import { makeUser } from '@/test/fixtures'
import type { AdminPayment, AdminUser, AdminUserDetail } from '@/types/admin'
import { adminApi } from './api'
import { toMinor } from './components/helpers'
import { RefundDialog } from './components/RefundDialog'
import { UserDrawer } from './components/UserDrawer'
import AdminUsersPage from './pages/AdminUsersPage'

afterEach(() => {
  vi.restoreAllMocks()
  useAuthStore.setState({ user: null, status: 'guest' })
})

const wrap = (node: React.ReactNode) =>
  render(
    <HelmetProvider>
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <MemoryRouter>{node}</MemoryRouter>
      </QueryClientProvider>
    </HelmetProvider>,
  )

const admin = makeUser({
  id: 'admin-1',
  roles: ['admin'],
  is_staff: true,
  permissions: ['users.view', 'users.manage', 'users.impersonate', 'payments.refund', 'subscriptions.manage'],
})

const payment: AdminPayment = {
  id: 'pay-1',
  invoice_number: 'INV-1',
  status: 'succeeded',
  gateway: 'razorpay',
  description: 'Pro membership',
  amount: { amount: 99900, currency: 'INR', formatted: '₹999.00' },
  subtotal: { amount: 84661, currency: 'INR', formatted: '₹846.61' },
  discount: { amount: 0, currency: 'INR', formatted: '₹0.00' },
  tax: { name: 'GST', rate: 18, amount: { amount: 15239, currency: 'INR', formatted: '₹152.39' } },
  period_start: null,
  period_end: null,
  paid_at: '2026-11-10T10:00:00Z',
  failure_reason: null,
  has_pdf: true,
  created_at: '2026-11-10T10:00:00Z',
  type: 'membership',
  currency: 'INR',
  amount_minor: 99900,
  refunded_minor: 20000,
  refundable_minor: 79900,
  refunded_at: null,
  disputed_at: null,
  gateway_payment_id: 'pay_X',
  billing_country: 'IN',
  user: { id: 'u1', name: 'Priya', email: 'priya@example.com' },
  subscription_id: null,
}

describe('refund dialog', () => {
  it('converts major units to minor', () => {
    expect(toMinor('499.50')).toBe(49950)
    expect(toMinor('1,000')).toBe(100000)
    expect(toMinor('0')).toBeNull()
    expect(toMinor('12.345')).toBeNull()
  })

  it('blocks amounts above the refundable balance and sends minor units', async () => {
    useAuthStore.setState({ user: admin, status: 'authenticated' })
    const refund = vi.spyOn(adminApi, 'refund').mockResolvedValue({ ...payment, refunded_minor: 69950 })
    wrap(<RefundDialog payment={payment} open onOpenChange={() => {}} />)

    fireEvent.click(screen.getByLabelText('Partial amount'))
    fireEvent.change(screen.getByLabelText('Amount in INR'), { target: { value: '1000' } })
    expect(screen.getByRole('alert')).toHaveTextContent('at most ₹799')
    fireEvent.change(screen.getByLabelText('Reason (kept in the audit log)'), {
      target: { value: 'Goodwill' },
    })
    expect(screen.getByRole('button', { name: /^Refund/ })).toBeDisabled()

    fireEvent.change(screen.getByLabelText('Amount in INR'), { target: { value: '499.50' } })
    const submit = screen.getByRole('button', { name: 'Refund ₹499.50' })
    expect(submit).toBeEnabled()
    fireEvent.click(submit)
    await waitFor(() => expect(refund).toHaveBeenCalledWith('pay-1', { amount: 49950, reason: 'Goodwill' }))
  })

  it('sends no amount for a full refund', async () => {
    const refund = vi.spyOn(adminApi, 'refund').mockResolvedValue({ ...payment, refunded_minor: 99900 })
    wrap(<RefundDialog payment={payment} open onOpenChange={() => {}} />)
    fireEvent.change(screen.getByLabelText('Reason (kept in the audit log)'), {
      target: { value: 'Duplicate' },
    })
    fireEvent.click(screen.getByRole('button', { name: 'Refund ₹799' }))
    await waitFor(() =>
      expect(refund).toHaveBeenCalledWith('pay-1', { amount: undefined, reason: 'Duplicate' }),
    )
  })
})

const row = (o: Partial<AdminUser> = {}): AdminUser => ({
  id: 'u1',
  name: 'Priya Learner',
  email: 'priya@example.com',
  phone: null,
  country: 'IN',
  avatar_url: null,
  roles: ['student'],
  status: 'active',
  status_reason: null,
  email_verified: true,
  membership: null,
  last_login_at: null,
  created_at: '2026-11-01T00:00:00Z',
  ...o,
})

describe('admin users page', () => {
  it('shows the bulk action bar when rows are selected', async () => {
    useAuthStore.setState({ user: admin, status: 'authenticated' })
    vi.spyOn(adminApi, 'users').mockResolvedValue({
      data: [row(), row({ id: 'u2', name: 'Rahul', email: 'rahul@example.com' })],
      links: { first: null, last: null, prev: null, next: null },
      meta: { current_page: 1, last_page: 1, per_page: 25, total: 2, from: 1, to: 2 },
    })
    wrap(<AdminUsersPage />)

    expect(await screen.findByText('Priya Learner')).toBeInTheDocument()
    expect(screen.queryByRole('region', { name: 'Bulk actions' })).not.toBeInTheDocument()
    fireEvent.click(screen.getAllByLabelText('Select row')[0]!)
    const bar = screen.getByRole('region', { name: 'Bulk actions' })
    expect(bar).toHaveTextContent('1 selected')
    expect(screen.getByRole('button', { name: 'Suspend' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Export selected' })).toBeInTheDocument()
  })
})

const detail = (o: Partial<AdminUserDetail> = {}): AdminUserDetail => ({
  ...row(),
  bio: null,
  timezone: 'Asia/Kolkata',
  phone_verified: false,
  two_factor: false,
  status_changed_at: null,
  is_staff: false,
  subscriptions: [],
  payments: [],
  stats: {
    enrollments: 2,
    completed_courses: 1,
    certificates: 1,
    watch_seconds: 7200,
    lifetime_value: { INR: 99900 },
  },
  logins: [],
  activity: [],
  ...o,
})

describe('user drawer', () => {
  it('offers suspend and view-as for a learner', async () => {
    useAuthStore.setState({ user: admin, status: 'authenticated' })
    vi.spyOn(adminApi, 'user').mockResolvedValue(detail())
    vi.spyOn(adminApi, 'plans').mockResolvedValue([])
    wrap(<UserDrawer userId="u1" onClose={() => {}} />)

    expect(await screen.findByRole('button', { name: 'Suspend' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'View as user' })).toBeInTheDocument()
    expect(screen.getByText('₹999')).toBeInTheDocument()
    expect(screen.getByText('2.0 h')).toBeInTheDocument()
  })

  it('hides destructive actions for staff accounts unless you are a super admin', async () => {
    useAuthStore.setState({ user: admin, status: 'authenticated' })
    vi.spyOn(adminApi, 'user').mockResolvedValue(detail({ is_staff: true, roles: ['admin'] }))
    vi.spyOn(adminApi, 'plans').mockResolvedValue([])
    wrap(<UserDrawer userId="u1" onClose={() => {}} />)

    expect(await screen.findByText('Memberships')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Suspend' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'View as user' })).not.toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: 'Roles' })).not.toBeInTheDocument()
  })
})
