import { useQuery } from '@tanstack/react-query'
import { getData, http } from '@/lib/api-client'
import type {
  InstructorLiveSession,
  JoinPayload,
  LiveOptions,
  LiveRegistrant,
  LiveReplay,
  LiveSession,
  ScheduleInput,
} from '@/types/live'
import type { Playback } from '@/types/courses'

export interface LiveFilters {
  from?: string
  to?: string
  tier?: string
  instructor?: string
  type?: string
  mine?: boolean
}

const data = async <T>(p: Promise<{ data: { data: T } }>) => (await p).data.data

export const liveApi = {
  list: (f: LiveFilters) =>
    http
      .get<{
        data: LiveSession[]
        meta: { instructors: { id: string; name: string }[]; join_early_minutes: number }
      }>('/live-sessions', { params: { ...f, mine: f.mine ? 1 : undefined } })
      .then((r) => r.data),
  show: (id: string) => getData<LiveSession>(`/live-sessions/${id}`),
  register: (id: string) => data<LiveSession>(http.post(`/live-sessions/${id}/registration`)),
  unregister: (id: string) => http.delete(`/live-sessions/${id}/registration`),
  join: (id: string) => data<JoinPayload>(http.post(`/live-sessions/${id}/join`)),
  replays: (q: string) => getData<LiveReplay[]>('/live-replays', q ? { q } : undefined),
  replayPlayback: (id: string) => getData<Playback>(`/live-replays/${id}/playback`),
  /** Downloads an .ics through the API (works for the per-session and "all my sessions" files). */
  downloadIcs: async (url: string, filename: string) => {
    const res = await http.get<Blob>(url.replace(/^.*\/api\/v1/, ''), { responseType: 'blob' })
    const href = URL.createObjectURL(res.data)
    const a = document.createElement('a')
    a.href = href
    a.download = filename
    a.click()
    URL.revokeObjectURL(href)
  },
}

export const instructorLiveApi = {
  options: () => getData<LiveOptions>('/instructor/live-sessions/options'),
  list: (scope: 'upcoming' | 'past') =>
    http
      .get<{ data: InstructorLiveSession[]; meta: { total: number } }>('/instructor/live-sessions', {
        params: { scope },
      })
      .then((r) => r.data),
  create: (body: ScheduleInput) => data<LiveSession[]>(http.post('/instructor/live-sessions', body)),
  update: (id: string, body: Partial<ScheduleInput>) =>
    data<LiveSession>(http.patch(`/instructor/live-sessions/${id}`, body)),
  cancel: (id: string, body: { reason: string; following: boolean }) =>
    data<{ cancelled: number }>(http.post(`/instructor/live-sessions/${id}/cancel`, body)),
  start: (id: string) => data<{ url: string }>(http.post(`/instructor/live-sessions/${id}/start`)),
  registrations: (id: string) =>
    http
      .get<{ data: LiveRegistrant[]; meta: { attendance_synced_at: string | null } }>(
        `/instructor/live-sessions/${id}/registrations`,
      )
      .then((r) => r.data),
  syncAttendance: (id: string) => http.post(`/instructor/live-sessions/${id}/attendance-sync`),
}

export const useLiveSessions = (f: LiveFilters) =>
  useQuery({ queryKey: ['live', 'list', f], queryFn: () => liveApi.list(f) })
export const useLiveSession = (id: string) =>
  useQuery({ queryKey: ['live', 'session', id], queryFn: () => liveApi.show(id), retry: false })

/** Browser timezone, used for display and as the default when scheduling. */
export const browserTimezone = () => Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC'

export const formatSessionTime = (iso: string, opts: Intl.DateTimeFormatOptions = {}) =>
  new Date(iso).toLocaleString(undefined, {
    weekday: 'short',
    day: 'numeric',
    month: 'short',
    hour: 'numeric',
    minute: '2-digit',
    ...opts,
  })
