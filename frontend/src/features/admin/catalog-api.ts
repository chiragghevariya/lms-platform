import { http } from '@/lib/api-client'
import type { AdminCategory, AdminReview, Paged } from '@/types/admin-catalog'
import type { InstructorLiveSession, LiveRegistrant } from '@/types/live'

const data = <T>(p: Promise<{ data: { data: T } }>) => p.then((r) => r.data.data)

export interface ReviewFilters {
  search?: string
  rating?: number | ''
  max_rating?: number | ''
  state?: 'visible' | 'hidden' | ''
  course?: string
  page?: number
}

export type CategoryInput = Pick<AdminCategory, 'name'> &
  Partial<Pick<AdminCategory, 'slug' | 'description' | 'sort'>>

const clean = (o: object) =>
  Object.fromEntries(Object.entries(o).filter(([, v]) => v !== '' && v !== undefined && v !== null))

export const catalogAdminApi = {
  categories: () => data<AdminCategory[]>(http.get('/admin/categories')),
  createCategory: (body: CategoryInput) => data<AdminCategory>(http.post('/admin/categories', body)),
  updateCategory: (id: string, body: Partial<CategoryInput>) =>
    data<AdminCategory>(http.patch(`/admin/categories/${id}`, body)),
  deleteCategory: (id: string) => http.delete(`/admin/categories/${id}`),

  reviews: (f: ReviewFilters) =>
    http.get<Paged<AdminReview>>('/admin/reviews', { params: clean(f) }).then((r) => r.data),
  hideReview: (id: string, reason: string) =>
    data<AdminReview>(http.post(`/admin/reviews/${id}/hide`, { reason })),
  unhideReview: (id: string) => data<AdminReview>(http.post(`/admin/reviews/${id}/unhide`)),
  deleteReview: (id: string) => http.delete(`/admin/reviews/${id}`),

  /** Admins reuse the instructor endpoints with `all=1` (live.manage-any). */
  liveSessions: (scope: 'upcoming' | 'past', page: number) =>
    http
      .get<Paged<InstructorLiveSession>>('/instructor/live-sessions', { params: { scope, all: 1, page } })
      .then((r) => r.data),
  cancelLive: (id: string, body: { reason: string; following: boolean }) =>
    data<{ cancelled: number }>(http.post(`/instructor/live-sessions/${id}/cancel`, body)),
  liveRegistrations: (id: string) =>
    http
      .get<{ data: LiveRegistrant[]; meta: { attendance_synced_at: string | null } }>(
        `/instructor/live-sessions/${id}/registrations`,
      )
      .then((r) => r.data),
  syncAttendance: (id: string) => http.post(`/instructor/live-sessions/${id}/attendance-sync`),
}
