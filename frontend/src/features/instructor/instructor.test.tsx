import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, within } from '@testing-library/react'
import { HelmetProvider } from 'react-helmet-async'
import { MemoryRouter } from 'react-router-dom'
import { TrendChart } from '@/components/charts/TrendChart'
import { LessonQuestions } from '@/features/learning/components/LessonQuestions'
import BecomeInstructorPage from '@/features/learning/pages/BecomeInstructorPage'
import type { EarningsOverview, InstructorAnalytics } from '@/types/instructor'
import { applicationApi, qaApi, teachingApi } from './api'
import EarningsPage from './pages/EarningsPage'
import InstructorDashboardPage from './pages/InstructorDashboardPage'

afterEach(() => vi.restoreAllMocks())

const wrap = (node: React.ReactNode) =>
  render(
    <HelmetProvider>
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <MemoryRouter>{node}</MemoryRouter>
      </QueryClientProvider>
    </HelmetProvider>,
  )

describe('trend chart', () => {
  it('offers a table view with every value (charts are never hover-only)', () => {
    wrap(
      <TrendChart
        title="Earnings (INR)"
        data={[
          { label: 'Oct 26', value: 1000 },
          { label: 'Nov 26', value: 250, estimate: true },
        ]}
        format={(v) => `₹${v}`}
      />,
    )
    fireEvent.click(screen.getByRole('button', { name: 'Table' }))
    const rows = screen.getAllByRole('row')
    expect(within(rows[1]!).getByText('₹1000')).toBeInTheDocument()
    expect(within(rows[2]!).getByText(/Nov 26 \(so far\)/)).toBeInTheDocument()
  })
})

describe('instructor dashboard', () => {
  it('shows KPIs, the per-course table and recent activity', async () => {
    const analytics: InstructorAnalytics = {
      range_days: 30,
      kpis: {
        students: 42,
        new_enrollments: 7,
        completion_rate: 38,
        avg_quiz_score: 81,
        watch_minutes: 1260,
        rating_avg: 4.6,
        rating_count: 12,
        live_attendance_rate: 64,
        open_questions: 3,
        pending_submissions: 2,
      },
      series: {
        daily: [{ date: '2026-11-12', enrollments: 2, watch_minutes: 90 }],
        earnings: [{ period: '2026-10', currency: 'INR', amount: 100000, estimate: false }],
      },
      courses: [
        {
          id: 'c1',
          title: 'Meta Ads from Zero',
          status: 'published',
          students: 42,
          completion_rate: 38,
          watch_minutes: 1260,
          avg_quiz_score: 81,
          rating_avg: 4.6,
          rating_count: 12,
        },
      ],
    }
    vi.spyOn(teachingApi, 'analytics').mockResolvedValue(analytics)
    vi.spyOn(teachingApi, 'activity').mockResolvedValue([
      {
        type: 'question',
        at: '2026-11-12T10:00:00Z',
        text: 'Ravi asked “Which size?”',
        link: '/instructor/questions',
      },
    ])
    wrap(<InstructorDashboardPage />)

    expect(await screen.findByText('42', { selector: 'p' })).toBeInTheDocument()
    expect(screen.getByText('21 h')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /3\s*waiting for you/i })).toHaveAttribute(
      'href',
      '/instructor/questions',
    )
    expect(screen.getByRole('link', { name: 'Meta Ads from Zero' })).toHaveAttribute(
      'href',
      '/instructor/courses/c1/edit',
    )
    expect(screen.getByRole('link', { name: 'Ravi asked “Which size?”' })).toBeInTheDocument()
    expect(screen.getByText('Earnings (INR)')).toBeInTheDocument()
  })
})

describe('earnings page', () => {
  it('explains the formula and lists statements with status', async () => {
    const overview: EarningsOverview = {
      revenue_share_percent: 60,
      pool_percent: 50,
      current: { period: '2026-11', estimate: [] },
      statements: [
        {
          id: 's1',
          period: '2026-10',
          currency: 'INR',
          status: 'paid',
          pool_percent: 50,
          revenue_share_percent: 60,
          subscription_revenue: 300000,
          pool_amount: 150000,
          watch_seconds: 3000,
          total_watch_seconds: 9000,
          subscription_earnings: 50000,
          sales_earnings: 30000,
          total: 80000,
          paid_reference: 'UTR123',
          lines: null,
        },
      ],
    }
    vi.spyOn(teachingApi, 'earnings').mockResolvedValue(overview)
    wrap(<EarningsPage />)

    expect(await screen.findByText(/you keep 60% of each/i)).toBeInTheDocument()
    const row = screen.getByText(/October 2026/).closest('tr')!
    expect(row).toHaveTextContent('₹500')
    expect(row).toHaveTextContent('₹300')
    expect(row).toHaveTextContent('₹800')
    expect(row).toHaveTextContent('Paid')
    expect(row).toHaveTextContent('Ref UTR123')
  })
})

describe('become an instructor', () => {
  it('shows review status, and lets rejected applicants apply again', async () => {
    vi.spyOn(applicationApi, 'mine').mockResolvedValue({
      is_instructor: false,
      application: {
        id: 'a',
        headline: 'Ads',
        expertise: ['Ads'],
        bio: 'b',
        motivation: 'm',
        links: [],
        status: 'rejected',
        review_notes: 'Add a sample lesson.',
        reviewed_at: null,
        created_at: '2026-11-01T00:00:00Z',
      },
    })
    wrap(<BecomeInstructorPage />)

    expect(await screen.findByText('Add a sample lesson.')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Apply again' }))
    expect(screen.getByLabelText('Headline')).toHaveValue('Ads')
  })
})

describe('lesson Q&A', () => {
  it('posts a question from the lesson tab', async () => {
    vi.spyOn(qaApi, 'list').mockResolvedValue([])
    const ask = vi.spyOn(qaApi, 'ask').mockResolvedValue({} as never)
    wrap(<LessonQuestions lessonId="l1" />)

    expect(await screen.findByText(/be the first to ask/i)).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Ask a question' }))
    fireEvent.change(screen.getByLabelText('Question'), { target: { value: 'Which template size?' } })
    fireEvent.change(screen.getByLabelText('Details'), { target: { value: 'Instagram post or story?' } })
    fireEvent.click(screen.getByRole('button', { name: 'Post question' }))
    await vi.waitFor(() =>
      expect(ask).toHaveBeenCalledWith('l1', {
        title: 'Which template size?',
        body: 'Instagram post or story?',
      }),
    )
  })
})
