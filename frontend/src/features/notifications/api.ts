import { useQuery } from '@tanstack/react-query'
import { getData, http } from '@/lib/api-client'
import type {
  AppNotification,
  CampaignRow,
  LogRow,
  PreferenceMatrix,
  Segment,
  TemplateRow,
} from '@/types/notifications'

type Page<T, M = object> = { data: T[]; meta: { total: number; current_page: number; last_page: number } & M }

export const notificationsApi = {
  list: (page: number, unread: boolean) =>
    http
      .get<Page<AppNotification, { unread: number }>>('/me/notifications', {
        params: { page, unread: unread ? 1 : undefined },
      })
      .then((r) => r.data),
  unreadCount: () => getData<{ unread: number }>('/me/notifications/unread-count'),
  markRead: (id: string) => http.post(`/me/notifications/${id}/read`),
  markAllRead: () => http.post('/me/notifications/read-all'),
  preferences: () => getData<PreferenceMatrix>('/me/notification-preferences'),
  updatePreferences: (changes: { event: string; channel: string; enabled: boolean }[]) =>
    http
      .put<{ data: PreferenceMatrix }>('/me/notification-preferences', { changes })
      .then((r) => r.data.data),
  whatsapp: (optIn: boolean) =>
    http.put('/me/whatsapp', { opt_in: optIn, consent: optIn ? true : undefined }),
}

export const notificationsAdminApi = {
  templates: () => getData<TemplateRow[]>('/admin/notification-templates'),
  saveTemplate: (event: string, channel: string, body: Record<string, unknown>) =>
    http.put(`/admin/notification-templates/${event}/${channel}`, body).then((r) => r.data.data),
  resetTemplate: (event: string, channel: string) =>
    http.delete(`/admin/notification-templates/${event}/${channel}`),
  preview: (event: string, body: { channel: string; subject?: string; body?: string }) =>
    http
      .post<{ data: { subject: string; body: string; html: boolean; unknown_vars: string[] } }>(
        `/admin/notification-templates/${event}/preview`,
        body,
      )
      .then((r) => r.data.data),
  campaigns: () => getData<CampaignRow[]>('/admin/campaigns'),
  campaign: (id: string) => getData<CampaignRow>(`/admin/campaigns/${id}`),
  audience: (segment: Segment) =>
    http
      .post<{
        data: { count: number; whatsapp_reachable: number; sample: { name: string; email: string }[] }
      }>('/admin/campaigns/audience', { segment })
      .then((r) => r.data.data),
  saveCampaign: (id: string | null, body: Record<string, unknown>) =>
    (id ? http.patch(`/admin/campaigns/${id}`, body) : http.post('/admin/campaigns', body)).then(
      (r) => r.data.data as CampaignRow,
    ),
  schedule: (id: string, scheduledAt?: string) =>
    http.post(`/admin/campaigns/${id}/schedule`, scheduledAt ? { scheduled_at: scheduledAt } : {}),
  cancel: (id: string) => http.post(`/admin/campaigns/${id}/cancel`),
  test: (id: string) =>
    http.post<{ data: { sent_to: string } }>(`/admin/campaigns/${id}/test`).then((r) => r.data.data),
  logs: (params: Record<string, string | number | undefined>) =>
    http
      .get<Page<LogRow, { summary: Record<string, Record<string, number>> }>>('/admin/notification-logs', {
        params,
      })
      .then((r) => r.data),
  retry: (id: string) => http.post(`/admin/notification-logs/${id}/retry`),
}

/** Unread count, polled every 60 s and on window focus (Reverb websockets are the upgrade path). */
export const useUnreadCount = (enabled: boolean) =>
  useQuery({
    queryKey: ['notifications', 'unread'],
    queryFn: notificationsApi.unreadCount,
    enabled,
    refetchInterval: 60_000,
    refetchOnWindowFocus: true,
  })
