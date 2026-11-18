import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { HelmetProvider } from 'react-helmet-async'
import { MemoryRouter } from 'react-router-dom'
import { ApiError } from '@/lib/api-client'
import type { AdminAffiliate, AdminReview } from '@/types/admin-catalog'
import { affiliatesAdminApi } from './affiliates-api'
import { catalogAdminApi } from './catalog-api'
import AdminAffiliatesPage from './pages/AdminAffiliatesPage'
import AdminCategoriesPage from './pages/AdminCategoriesPage'
import AdminReviewsPage from './pages/AdminReviewsPage'

afterEach(() => vi.restoreAllMocks())

const wrap = (node: React.ReactNode) =>
  render(
    <HelmetProvider>
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <MemoryRouter>{node}</MemoryRouter>
      </QueryClientProvider>
    </HelmetProvider>,
  )

const meta = { current_page: 1, last_page: 1, total: 1, per_page: 25 }

const review = (o: Partial<AdminReview> = {}): AdminReview => ({
  id: 'r1',
  rating: 1,
  comment: 'Spam spam buy my stuff',
  hidden: false,
  hidden_reason: null,
  hidden_at: null,
  user: { id: 'u1', name: 'Spammy', email: 'spam@example.com' },
  course: { id: 'c1', title: 'Video Editing 101', slug: 'video-editing-101' },
  created_at: '2026-11-10T10:00:00Z',
  ...o,
})

describe('review moderation', () => {
  it('requires a reason before hiding a review', async () => {
    vi.spyOn(catalogAdminApi, 'reviews').mockResolvedValue({ data: [review()], meta })
    const hide = vi.spyOn(catalogAdminApi, 'hideReview').mockResolvedValue(review({ hidden: true }))
    wrap(<AdminReviewsPage />)

    expect(await screen.findByText('Spam spam buy my stuff')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /Video Editing 101/ })).toHaveAttribute(
      'href',
      '/courses/video-editing-101',
    )

    fireEvent.click(screen.getByRole('button', { name: 'Hide' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Hide review' }))
    expect(await within(dialog).findByRole('alert')).toHaveTextContent(/Reason is required/)
    expect(hide).not.toHaveBeenCalled()

    fireEvent.change(within(dialog).getByLabelText('Reason'), { target: { value: 'Spam' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Hide review' }))
    await waitFor(() => expect(hide).toHaveBeenCalledWith('r1', 'Spam'))
  })

  it('marks hidden reviews and offers to restore them', async () => {
    vi.spyOn(catalogAdminApi, 'reviews').mockResolvedValue({
      data: [review({ hidden: true, hidden_reason: 'Abusive' })],
      meta,
    })
    wrap(<AdminReviewsPage />)
    expect(await screen.findByText('Hidden: Abusive')).toBeInTheDocument()
    expect(within(screen.getByRole('table')).getByText('Hidden')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Restore' })).toBeInTheDocument()
  })
})

describe('categories', () => {
  it('shows the API error when a category in use cannot be deleted', async () => {
    vi.spyOn(catalogAdminApi, 'categories').mockResolvedValue([
      { id: 'cat1', name: 'Design', slug: 'design', description: null, sort: 0, courses_count: 3 },
    ])
    vi.spyOn(catalogAdminApi, 'deleteCategory').mockRejectedValue(
      new ApiError(422, {
        message: "Move this category's courses to another category first.",
        errors: { category: ["Move this category's courses to another category first."] },
      }),
    )
    const { container } = wrap(<AdminCategoriesPage />)
    fireEvent.click(await screen.findByRole('button', { name: 'Delete Design' }))
    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText(/3 course\(s\) use this category/)).toBeInTheDocument()
    fireEvent.click(within(dialog).getByRole('button', { name: 'Delete' }))
    await waitFor(() => expect(catalogAdminApi.deleteCategory).toHaveBeenCalledWith('cat1'))
    expect(container).toBeTruthy()
  })

  it('creates a category from the inline form', async () => {
    vi.spyOn(catalogAdminApi, 'categories').mockResolvedValue([])
    const create = vi
      .spyOn(catalogAdminApi, 'createCategory')
      .mockResolvedValue({
        id: 'n',
        name: 'Music',
        slug: 'music',
        description: null,
        sort: 0,
        courses_count: 0,
      })
    wrap(<AdminCategoriesPage />)
    fireEvent.change(await screen.findByLabelText('New category name'), { target: { value: 'Music' } })
    fireEvent.click(screen.getByRole('button', { name: 'Add' }))
    await waitFor(() => expect(create).toHaveBeenCalledWith({ name: 'Music' }))
  })
})

describe('affiliates', () => {
  const affiliate: AdminAffiliate = {
    id: 'a1',
    code: 'ASHA20',
    status: 'pending',
    tier: 'standard',
    terms: { type: 'percent', rate: 20, fixed: null },
    website: null,
    promotion_plan: 'YouTube channel',
    user: { id: 'u9', name: 'Asha Rao', email: 'asha@example.com' },
    referrals: 4,
    conversions: 1,
    balances: [],
    payout_method: null,
    created_at: '2026-11-01T00:00:00Z',
  }

  it('approves a pending affiliate through the approve endpoint', async () => {
    vi.spyOn(affiliatesAdminApi, 'list').mockResolvedValue({ data: [affiliate], meta })
    const decide = vi.spyOn(affiliatesAdminApi, 'decide').mockResolvedValue({ id: 'a1', status: 'approved' })
    wrap(<AdminAffiliatesPage />)

    expect(await screen.findByText('Asha Rao')).toBeInTheDocument()
    expect(screen.getByText('20%')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Approve' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Approve' }))
    await waitFor(() => expect(decide).toHaveBeenCalledWith('a1', 'approve', ''))
  })

  it('requires a reason to reject', async () => {
    vi.spyOn(affiliatesAdminApi, 'list').mockResolvedValue({ data: [affiliate], meta })
    const decide = vi.spyOn(affiliatesAdminApi, 'decide')
    wrap(<AdminAffiliatesPage />)
    fireEvent.click(await screen.findByRole('button', { name: 'Reject' }))
    const dialog = await screen.findByRole('dialog')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Reject' }))
    expect(await within(dialog).findByRole('alert')).toBeInTheDocument()
    expect(decide).not.toHaveBeenCalled()
  })

  it('switches to the fraud review tab with arrow keys', async () => {
    vi.spyOn(affiliatesAdminApi, 'list').mockResolvedValue({ data: [], meta: { ...meta, total: 0 } })
    vi.spyOn(affiliatesAdminApi, 'reviews').mockResolvedValue([])
    wrap(<AdminAffiliatesPage />)
    const tab = screen.getByRole('tab', { name: 'Affiliates' })
    fireEvent.keyDown(tab, { key: 'ArrowRight' })
    expect(screen.getByRole('tab', { name: 'Fraud review' })).toHaveAttribute('aria-selected', 'true')
    expect(await screen.findByText(/No flagged referrals/)).toBeInTheDocument()
  })
})
