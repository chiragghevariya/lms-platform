import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, fireEvent, render, screen } from '@testing-library/react'
import { HelmetProvider } from 'react-helmet-async'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import type { LiveSession } from '@/types/live'
import { liveApi } from './api'
import { MonthCalendar } from './components/MonthCalendar'
import { SessionActions } from './components/SessionActions'
import LiveRoomPage from './pages/LiveRoomPage'

vi.mock('./components/ZoomEmbed', () => ({
  default: ({ join }: { join: { meeting_number: string; customer_key: string } }) => (
    <div data-testid="zoom-embed">
      meeting {join.meeting_number} key {join.customer_key}
    </div>
  ),
}))

afterEach(() => {
  vi.restoreAllMocks()
  vi.useRealTimers()
})

const NOW = new Date('2026-11-20T10:00:00Z')

const session = (
  o: Partial<LiveSession> = {},
  viewer: Partial<NonNullable<LiveSession['viewer']>> = {},
): LiveSession => ({
  id: 'ls1',
  title: 'Meta Ads clinic',
  description: 'Bring questions',
  type: 'class',
  type_label: 'Live class',
  status: 'scheduled',
  starts_at: '2026-11-20T10:15:00Z',
  ends_at: '2026-11-20T11:15:00Z',
  duration: 60,
  timezone: 'Asia/Kolkata',
  required_tier: 'pro',
  provider: 'zoom',
  capacity: null,
  registered_count: 3,
  seats_left: null,
  is_series: false,
  instructor: { id: 'i', name: 'Meera', avatar_url: null },
  course: null,
  cancel_reason: null,
  has_replay: false,
  replay_lesson_id: null,
  viewer: {
    eligible: true,
    registered: true,
    can_register: false,
    joinable: false,
    join_opens_at: '2026-11-20T10:05:00Z',
    attended: null,
    can_manage: false,
    ...viewer,
  },
  calendar: { google: 'https://calendar.google.com/x', ics: '/api/v1/live-sessions/ls1/calendar.ics' },
  ...o,
})

const wrap = (node: React.ReactNode, path = '/') =>
  render(
    <HelmetProvider>
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <MemoryRouter initialEntries={[path]}>{node}</MemoryRouter>
      </QueryClientProvider>
    </HelmetProvider>,
  )

describe('session actions', () => {
  it('enables "Join now" by itself 10 minutes before the start', () => {
    vi.useFakeTimers({ toFake: ['Date', 'setInterval', 'clearInterval'] })
    vi.setSystemTime(NOW)
    wrap(<SessionActions session={session()} compact />)

    expect(screen.getByText('Join opens in 5:00')).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: /join now/i })).not.toBeInTheDocument()

    act(() => vi.advanceTimersByTime(5 * 60 * 1000))
    expect(screen.getByRole('link', { name: /join now/i })).toHaveAttribute('href', '/live/ls1')
  })

  it('registers eligible members and shows upgrade for others', async () => {
    const register = vi.spyOn(liveApi, 'register').mockResolvedValue(session())
    const { unmount } = wrap(
      <SessionActions session={session({}, { registered: false, can_register: true })} />,
    )
    fireEvent.click(screen.getByRole('button', { name: 'Register' }))
    await vi.waitFor(() => expect(register).toHaveBeenCalledWith('ls1'))
    unmount()

    wrap(<SessionActions session={session({}, { registered: false, eligible: false })} />)
    expect(screen.getByRole('link', { name: 'Upgrade to Pro' })).toHaveAttribute(
      'href',
      '/pricing?highlight=pro',
    )
  })

  it('offers the replay after the session', () => {
    wrap(<SessionActions session={session({ status: 'ended', has_replay: true })} />)
    expect(screen.getByRole('link', { name: 'Watch replay' })).toHaveAttribute('href', '/app/replays/ls1')
  })
})

describe('live room', () => {
  const route = (
    <Routes>
      <Route path="/live/:id" element={<LiveRoomPage />} />
    </Routes>
  )

  it('joins in the browser with the Zoom SDK, passing the attendance key', async () => {
    vi.spyOn(liveApi, 'show').mockResolvedValue(
      session({ status: 'live' }, { joinable: true, join_opens_at: '2020-01-01T00:00:00Z' }),
    )
    const join = vi.spyOn(liveApi, 'join').mockResolvedValue({
      mode: 'zoom_sdk',
      sdk_key: 'k',
      signature: 'sig',
      meeting_number: '8800000001',
      password: 'p',
      user_name: 'Asha',
      user_email: 'a@example.com',
      customer_key: 'abc123',
      fallback_url: 'https://zoom.us/j/8800000001',
    })
    wrap(route, '/live/ls1')

    fireEvent.click(await screen.findByRole('button', { name: /join now/i }))
    expect(await screen.findByTestId('zoom-embed')).toHaveTextContent('meeting 8800000001 key abc123')
    expect(join).toHaveBeenCalledWith('ls1')
    expect(screen.getByRole('link', { name: /open in zoom/i })).toHaveAttribute(
      'href',
      'https://zoom.us/j/8800000001',
    )
  })

  it('opens external links in a new tab', async () => {
    vi.spyOn(liveApi, 'show').mockResolvedValue(
      session({ provider: 'external_link' }, { joinable: true, join_opens_at: '2020-01-01T00:00:00Z' }),
    )
    vi.spyOn(liveApi, 'join').mockResolvedValue({ mode: 'external', url: 'https://meet.google.com/abc' })
    const open = vi.spyOn(window, 'open').mockReturnValue(null)
    wrap(route, '/live/ls1')

    fireEvent.click(await screen.findByRole('button', { name: /join now/i }))
    expect(await screen.findByText('The class opened in a new tab.')).toBeInTheDocument()
    expect(open).toHaveBeenCalledWith('https://meet.google.com/abc', '_blank', 'noopener,noreferrer')
  })

  it('shows a countdown before the room opens', async () => {
    vi.spyOn(liveApi, 'show').mockResolvedValue(
      session(
        { starts_at: '2099-01-01T10:00:00Z', ends_at: '2099-01-01T11:00:00Z' },
        { join_opens_at: '2099-01-01T09:50:00Z' },
      ),
    )
    wrap(route, '/live/ls1')
    expect(await screen.findByRole('timer')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /join now/i })).not.toBeInTheDocument()
  })
})

describe('month calendar', () => {
  it('places sessions on their local day and marks registrations', () => {
    wrap(
      <MonthCalendar
        month={new Date(2026, 10, 1)}
        onMonth={() => {}}
        sessions={[session({ starts_at: new Date(2026, 10, 18, 19, 30).toISOString() })]}
      />,
    )
    const cell = screen.getByRole('gridcell', { name: /18 november, 1 session/i })
    expect(cell).toHaveTextContent('Meta Ads clinic')
    expect(screen.getByRole('link', { name: /meta ads clinic/i })).toHaveClass('bg-primary')
  })
})
