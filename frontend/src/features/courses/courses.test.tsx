import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { HelmetProvider } from 'react-helmet-async'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import LessonPlayerPage from '@/features/learn/pages/LessonPlayerPage'
import { ApiError } from '@/lib/api-client'
import { useAuthStore } from '@/stores/auth-store'
import { member } from '@/test/fixtures'
import type { CourseDetail } from '@/types/courses'
import { coursesApi } from './api'
import CourseDetailPage from './pages/CourseDetailPage'
import { learnApi } from '@/features/learn/api'

beforeEach(() => {
  // Spy on the shared objects: the hooks call them through the same reference.
  vi.spyOn(coursesApi, 'reviews').mockResolvedValue({
    data: [],
    links: { first: null, last: null, prev: null, next: null },
    meta: { current_page: 1, last_page: 1, per_page: 10, total: 0, from: null, to: null },
  })
})
afterEach(() => vi.restoreAllMocks())

const lesson = (id: string, o: Partial<CourseDetail['curriculum'][0]['lessons'][0]> = {}) => ({
  id,
  title: `Lesson ${id}`,
  type: 'video' as const,
  duration_seconds: 600,
  is_preview: false,
  drip_days: 0,
  unlocked: true,
  lock_reason: null,
  available_at: null,
  completed: false,
  ...o,
})

const course = (
  access: Partial<CourseDetail['access']>,
  lessons = [lesson('a', { is_preview: true }), lesson('b')],
): CourseDetail => ({
  id: 'c',
  slug: 'meta-ads',
  title: 'Meta Ads',
  subtitle: 'Run ads',
  thumbnail_url: null,
  level: 'beginner',
  language: 'en',
  required_tier: 2,
  tier: 'pro',
  instructor: { id: 'i', name: 'Meera', avatar_url: null },
  category: null,
  duration_minutes: 20,
  lessons_count: 2,
  rating_avg: 0,
  rating_count: 0,
  is_featured: false,
  price: null,
  status: 'published',
  published_at: null,
  description: null,
  tags: [],
  has_promo: false,
  instructors: [{ id: 'i', name: 'Meera', avatar_url: null, headline: null, bio: null }],
  curriculum: [{ id: 's', title: 'Basics', lessons }],
  access: {
    has_access: false,
    reason: null,
    required_tier: 2,
    purchased: false,
    can_purchase: false,
    enrolled: false,
    progress_percent: 0,
    next_lesson_id: 'a',
    completed_at: null,
    certificate_id: null,
    bookmarked: false,
    ...access,
  },
  my_review: null,
})

const wrap = (path: string, routes: React.ReactNode) =>
  render(
    <HelmetProvider>
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <MemoryRouter initialEntries={[path]}>
          <Routes>{routes}</Routes>
        </MemoryRouter>
      </QueryClientProvider>
    </HelmetProvider>,
  )

describe('course detail CTA', () => {
  it('shows Pro members a "Start learning" link into the player', async () => {
    useAuthStore.setState({ user: member('pro'), status: 'authenticated' })
    vi.spyOn(coursesApi, 'detail').mockResolvedValueOnce(course({ has_access: true, reason: 'tier' }))
    wrap('/courses/meta-ads', <Route path="/courses/:slug" element={<CourseDetailPage />} />)

    expect(await screen.findByRole('link', { name: 'Start learning' })).toHaveAttribute(
      'href',
      '/learn/meta-ads/a',
    )
    expect(screen.queryByText(/upgrade to pro/i)).not.toBeInTheDocument()
  })

  it('shows Starter members the lock and an "Upgrade to Pro" CTA', async () => {
    useAuthStore.setState({ user: member('starter'), status: 'authenticated' })
    vi.spyOn(coursesApi, 'detail').mockResolvedValueOnce(
      course({}, [
        lesson('a', { is_preview: true }),
        lesson('b', { unlocked: false, lock_reason: 'membership_required' }),
      ]),
    )
    wrap('/courses/meta-ads', <Route path="/courses/:slug" element={<CourseDetailPage />} />)

    expect(await screen.findByRole('link', { name: 'Upgrade to Pro' })).toHaveAttribute(
      'href',
      '/pricing?highlight=pro',
    )
    expect(screen.getByText('Locked')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /watch a free preview/i })).toBeInTheDocument()
  })
})

describe('lesson player lock screen', () => {
  it('explains drip-locked lessons with the unlock date', async () => {
    useAuthStore.setState({ user: member('pro'), status: 'authenticated' })
    vi.spyOn(coursesApi, 'detail').mockResolvedValue(course({ has_access: true, reason: 'tier' }))
    vi.spyOn(learnApi, 'lesson').mockRejectedValueOnce(
      new ApiError(403, {
        message: 'Not yet',
        code: 'drip_locked',
        available_at: '2026-11-19T10:00:00Z',
      } as never),
    )
    wrap('/learn/meta-ads/b', <Route path="/learn/:slug/:lessonId" element={<LessonPlayerPage />} />)

    expect(await screen.findByRole('heading', { name: 'Coming soon' })).toBeInTheDocument()
    expect(screen.getByText(/it unlocks on 19 nov 2026/i)).toBeInTheDocument()
  })
})
