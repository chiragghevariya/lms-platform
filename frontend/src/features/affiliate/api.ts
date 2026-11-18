import { useQuery } from '@tanstack/react-query'
import { getData, http } from '@/lib/api-client'
import type {
  AffiliateAccount,
  AffiliateAssets,
  AffiliateCommission,
  AffiliatePayoutRow,
  AffiliateStats,
  InviteOverview,
} from '@/types/affiliate'

export const affiliateApi = {
  me: () => getData<AffiliateAccount | null>('/me/affiliate'),
  apply: (body: { website?: string; promotion_plan: string; accept_terms: boolean }) =>
    http.post('/me/affiliate', body),
  stats: (days: number) => getData<AffiliateStats>('/me/affiliate/stats', { days }),
  commissions: (page: number) =>
    http
      .get<{ data: AffiliateCommission[]; meta: { total: number; last_page: number } }>(
        '/me/affiliate/commissions',
        { params: { page } },
      )
      .then((r) => r.data),
  payouts: () => getData<AffiliatePayoutRow[]>('/me/affiliate/payouts'),
  assets: () => getData<AffiliateAssets>('/me/affiliate/assets'),
  updatePayout: (body: { payout_method: string; payout_details: Record<string, string> }) =>
    http
      .put<{ data: { method: string; summary: string } }>('/me/affiliate/payout', body)
      .then((r) => r.data.data),
  invite: () => getData<InviteOverview>('/me/invite'),
}

export const useAffiliate = () => useQuery({ queryKey: ['affiliate', 'me'], queryFn: affiliateApi.me })

/** Share intents (no SDKs, no tracking pixels). */
export const shareLinks = (url: string, text: string) => ({
  whatsapp: `https://wa.me/?text=${encodeURIComponent(`${text} ${url}`)}`,
  linkedin: `https://www.linkedin.com/sharing/share-offsite/?url=${encodeURIComponent(url)}`,
  x: `https://x.com/intent/tweet?text=${encodeURIComponent(text)}&url=${encodeURIComponent(url)}`,
})
