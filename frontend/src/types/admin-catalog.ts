import type { PageMeta } from '@/components/data-table/DataTable'

export interface AdminCategory {
  id: string
  name: string
  slug: string
  description: string | null
  sort: number
  courses_count?: number
}

export interface AdminReview {
  id: string
  rating: number
  comment: string | null
  hidden: boolean
  hidden_reason: string | null
  hidden_at: string | null
  user: { id: string; name: string; email: string }
  course: { id: string; title: string; slug: string }
  created_at: string
}

export interface Paged<T> {
  data: T[]
  meta: PageMeta
}

export type AffiliateStatus = 'pending' | 'approved' | 'rejected' | 'suspended'

export interface CommissionTerms {
  type: 'percent' | 'fixed'
  rate: number | null
  fixed: Record<string, number> | null
}

/** PHP arrays keyed by currency serialise as `[]` when empty. */
export type CurrencyAmounts = Record<string, number> | []

export interface AdminAffiliate {
  id: string
  code: string
  status: AffiliateStatus
  tier: string
  terms: CommissionTerms
  website: string | null
  promotion_plan: string | null
  user: { id: string; name: string; email: string }
  referrals: number
  conversions: number
  balances: CurrencyAmounts
  payout_method: string | null
  created_at: string | null
}

export interface AffiliateReviewItem {
  id: string
  flags: string[]
  affiliate: { code: string; name: string; email: string }
  customer: { name: string; email: string }
  held: CurrencyAmounts
  created_at: string | null
}

export interface AffiliatePayoutRow {
  id: string
  period: string
  currency: string
  amount: number
  status: 'pending' | 'processing' | 'paid' | 'failed' | string
  commissions_count: number
  method: string | null
  gateway: string | null
  reference: string | null
  affiliate: { code: string; name: string; email: string }
}

export interface TermsInput {
  tier?: string
  commission_type?: 'percent' | 'fixed' | null
  commission_rate?: number | null
  commission_fixed?: Record<string, number> | null
}
