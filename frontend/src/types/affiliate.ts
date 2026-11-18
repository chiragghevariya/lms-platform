export type Balances = Record<string, { pending: number; approved: number; paid: number; in_payout: number }>

export interface AffiliateAccount {
  id: string
  code: string
  status: 'pending' | 'approved' | 'rejected' | 'suspended'
  review_notes: string | null
  link: string
  terms: {
    type: 'percent' | 'fixed'
    rate: number | null
    fixed: Record<string, number> | null
    recurring_months: number
    hold_days: number
    cookie_days: number
    attribution: string
  }
  payout: {
    method: 'bank' | 'upi' | 'paypal' | null
    summary: string | null
    minimum: Record<string, number>
  }
  balances: Balances | null
}

export interface AffiliateStats {
  range_days: number
  funnel: {
    clicks: number
    unique_visitors: number
    signups: number
    conversions: number
    signup_rate: number | null
    conversion_rate: number | null
  }
  daily: { date: string; clicks: number; signups: number; conversions: number }[]
  campaigns: { campaign: string; clicks: number }[]
}

export interface AffiliateCommission {
  id: string
  kind: 'sale' | 'clawback'
  level: 1 | 2
  currency: string
  base_amount: number
  rate: string
  amount: number
  net: number
  status: 'pending' | 'approved' | 'paid' | 'reversed'
  on_hold_for_review: boolean
  available_at: string
  created_at: string
  reversal_reason: string | null
  customer: string
  description: string
}

export interface AffiliatePayoutRow {
  id: string
  period: string
  currency: string
  amount: number
  commissions_count: number
  status: 'pending' | 'paid' | 'failed'
  method: string | null
  reference: string | null
  paid_at: string | null
  created_at: string
}

export interface AffiliateAssets {
  swipe_copy: { title: string; body: string }[]
  banners: { size: string; url: string }[]
}

export interface InviteOverview {
  enabled: boolean
  code: string
  link: string
  reward: { type: 'days' | 'credit'; days: number; credit: Record<string, number>; hold_days: number }
  stats: { invited: number; subscribed: number; days_earned: number; days_banked: number }
  rewards: {
    id: string
    friend: string
    status: string
    type: string
    days: number | null
    credit_amount: number | null
    currency: string | null
    available_at: string | null
    created_at: string
  }[]
}
