import { http } from '@/lib/api-client'
import type {
  AdminAffiliate,
  AffiliatePayoutRow,
  AffiliateReviewItem,
  AffiliateStatus,
  Paged,
  TermsInput,
} from '@/types/admin-catalog'

const data = <T>(p: Promise<{ data: { data: T } }>) => p.then((r) => r.data.data)

export const affiliatesAdminApi = {
  list: (status: AffiliateStatus | '', page: number) =>
    http
      .get<Paged<AdminAffiliate>>('/admin/affiliates', { params: { status: status || undefined, page } })
      .then((r) => r.data),
  decide: (id: string, action: 'approve' | 'reject' | 'suspend', notes?: string) =>
    data<{ id: string; status: AffiliateStatus }>(
      http.post(`/admin/affiliates/${id}/${action}`, { notes: notes || undefined }),
    ),
  updateTerms: (id: string, body: TermsInput) =>
    data<{ tier: string }>(http.patch(`/admin/affiliates/${id}/terms`, body)),
  reviews: () => data<AffiliateReviewItem[]>(http.get('/admin/affiliate-reviews')),
  review: (id: string, approve: boolean) =>
    data<{ status: string }>(http.post(`/admin/affiliate-reviews/${id}`, { approve })),
  payouts: (period: string) =>
    data<AffiliatePayoutRow[]>(http.get('/admin/affiliate-payouts', { params: { period } })),
  batch: (period: string) =>
    data<{ created: number }>(http.post('/admin/affiliate-payouts/batch', { period })),
  markPaid: (id: string, reference: string) =>
    data<{ status: string }>(http.post(`/admin/affiliate-payouts/${id}/paid`, { reference })),
  markFailed: (id: string) => data<{ status: string }>(http.post(`/admin/affiliate-payouts/${id}/failed`)),
}

/** `{INR: 1200}` or `[]` → list of [currency, minor]. */
export const amounts = (v: Record<string, number> | []) => (Array.isArray(v) ? [] : Object.entries(v))
