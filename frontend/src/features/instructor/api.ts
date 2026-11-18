import { useQuery } from '@tanstack/react-query'
import { getData, http } from '@/lib/api-client'
import type {
  ActivityItem,
  AdminStatement,
  Announcement,
  CourseStudent,
  EarningsOverview,
  InstructorAnalytics,
  InstructorApplication,
  InstructorProfileData,
  QaQuestion,
  Statement,
} from '@/types/instructor'

const data = async <T>(p: Promise<{ data: { data: T } }>) => (await p).data.data
type Page<T> = { data: T[]; meta: { total: number; current_page: number; last_page: number } }

export const teachingApi = {
  analytics: (days: number) => getData<InstructorAnalytics>('/instructor/analytics', { days }),
  activity: () => getData<ActivityItem[]>('/instructor/activity'),
  students: (courseId: string, params: { q?: string; sort?: string; page?: number }) =>
    http
      .get<Page<CourseStudent> & { meta: { lessons_count: number } }>(
        `/instructor/courses/${courseId}/students`,
        { params },
      )
      .then((r) => r.data),
  questions: (status: string, course?: string) =>
    http.get<Page<QaQuestion>>('/instructor/questions', { params: { status, course } }).then((r) => r.data),
  announcements: (courseId: string) =>
    getData<Announcement[]>(`/instructor/courses/${courseId}/announcements`),
  announce: (courseId: string, body: { title: string; body: string }) =>
    data<Announcement>(http.post(`/instructor/courses/${courseId}/announcements`, body)),
  profile: () => getData<InstructorProfileData>('/instructor/profile'),
  updateProfile: (body: Record<string, unknown>) =>
    data<InstructorProfileData>(http.put('/instructor/profile', body)),
  earnings: () => getData<EarningsOverview>('/instructor/earnings'),
  statement: (id: string) => getData<Statement>(`/instructor/earnings/${id}`),
}

export const qaApi = {
  list: (lessonId: string, scope: 'lesson' | 'course') =>
    getData<QaQuestion[]>(`/learn/lessons/${lessonId}/questions`, { scope }),
  ask: (lessonId: string, body: { title: string; body: string }) =>
    data<QaQuestion>(http.post(`/learn/lessons/${lessonId}/questions`, body)),
  show: (id: string) => getData<QaQuestion>(`/questions/${id}`),
  answer: (id: string, body: string) => data<QaQuestion>(http.post(`/questions/${id}/answers`, { body })),
  remove: (id: string) => http.delete(`/questions/${id}`),
  removeAnswer: (id: string) => http.delete(`/answers/${id}`),
  courseAnnouncements: (slug: string) => getData<Announcement[]>(`/courses/${slug}/announcements`),
}

export const applicationApi = {
  mine: () =>
    getData<{ is_instructor: boolean; application: InstructorApplication | null }>(
      '/me/instructor-application',
    ),
  apply: (
    body: Omit<InstructorApplication, 'id' | 'status' | 'review_notes' | 'reviewed_at' | 'created_at'>,
  ) => data<InstructorApplication>(http.post('/me/instructor-application', body)),
}

export const adminInstructorApi = {
  applications: (status: string) =>
    http
      .get<Page<InstructorApplication>>('/admin/instructor-applications', { params: { status } })
      .then((r) => r.data),
  approve: (id: string, notes?: string) =>
    data<InstructorApplication>(http.post(`/admin/instructor-applications/${id}/approve`, { notes })),
  reject: (id: string, notes: string) =>
    data<InstructorApplication>(http.post(`/admin/instructor-applications/${id}/reject`, { notes })),
  payouts: (period: string) =>
    http
      .get<{
        data: AdminStatement[]
        meta: { period: string; totals: Record<string, { total: number; paid: number }> }
      }>('/admin/payouts', {
        params: { period },
      })
      .then((r) => r.data),
  payout: (id: string) => getData<AdminStatement>(`/admin/payouts/${id}`),
  generate: (period: string) => data<{ written: number }>(http.post('/admin/payouts/generate', { period })),
  finalize: (period: string) => data<{ finalized: number }>(http.post('/admin/payouts/finalize', { period })),
  markPaid: (id: string, reference: string) =>
    data<Statement>(http.post(`/admin/payouts/${id}/paid`, { reference })),
  instructors: () =>
    http
      .get<{
        data: {
          id: string
          name: string
          email: string
          revenue_share_percent: number
          custom_share: boolean
          payout_ready: boolean
        }[]
        meta: { pool_percent: number; default_share: number }
      }>('/admin/instructors')
      .then((r) => r.data),
  setShare: (id: string, pct: number | null) =>
    http.patch(`/admin/instructors/${id}/revenue-share`, { revenue_share_percent: pct }),
}

export const useAnalytics = (days: number) =>
  useQuery({ queryKey: ['instructor', 'analytics', days], queryFn: () => teachingApi.analytics(days) })
