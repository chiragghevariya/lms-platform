import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { http } from '@/lib/api-client'

export interface RangeParams {
  from: string
  to: string
  currency: string
}

export interface AnalyticsMeta {
  from: string
  to: string
  currency: string
  currencies: string[]
  timezone: string
}

export interface RevenuePoint {
  period: string
  membership: number
  courses: number
  refunds: number
  net: number
}

export interface Revenue {
  currency: string
  gross: number
  refunds: number
  net: number
  tax: number
  membership: number
  courses: number
  payments: number
  interval: 'day' | 'month'
  series: RevenuePoint[]
  by_gateway: { gateway: string; total: number; payments: number }[]
  by_country: { country: string | null; total: number; payments: number }[]
}

export interface TierRow {
  tier: number
  name: string
  members: number
  paying: number
  complimentary: number
}

export interface Members {
  currency: string
  mrr: number
  arr: number
  paying_members: number
  paying_at_start: number
  new_members: number
  churned_members: number
  churn_rate: number | null
  monthly_churn_rate: number | null
  arpu: number
  ltv: number | null
  trials_started: number
  trials_converted: number
  trial_conversion: number | null
  active_by_tier: TierRow[]
}

export interface FunnelStep {
  step: string
  label: string
  count: number
}

export interface TopCourse {
  id: string | null
  title: string | null
  slug: string | null
  instructor: string | null
  watch_seconds: number
  learners: number
  enrollments: number
  completion_rate: number | null
  sales: number
  rating: number | null
}

export interface Summary {
  currency: string
  mrr: number
  arr: number
  active_members: number
  paying_members: number
  churn_rate: number | null
  revenue_today: number
  revenue_range: number
  mrr_trend: { day: string; mrr: number }[]
}

export interface Overview {
  summary: Summary
  revenue: Revenue
  members: Members
  funnel: FunnelStep[]
  top_courses: TopCourse[]
}

export interface Learning {
  watch_seconds: number
  active_learners: number
  enrollments: number
  completions: number
  completion_rate: number | null
  top_courses: TopCourse[]
  live: { sessions: number; registrations: number; attended: number; attendance_rate: number | null }
}

export interface AffiliateShare {
  referred_revenue: number
  commissions: number
  share_of_revenue: number | null
  referred_share: number | null
  paid_out: number
}

export interface Cohort {
  cohort: string
  size: number
  retention: (number | null)[]
}

const get = async <T>(path: string, params?: object) =>
  (await http.get<{ data: T; meta: AnalyticsMeta }>(path, { params })).data

export const analyticsApi = {
  overview: (p: RangeParams) => get<Overview>('/admin/analytics/overview', p),
  members: (p: RangeParams) =>
    get<Members & { funnel: FunnelStep[]; mrr_trend: { day: string; mrr: number }[] }>(
      '/admin/analytics/members',
      p,
    ),
  revenue: (p: RangeParams) => get<Revenue>('/admin/analytics/revenue', p),
  learning: (p: RangeParams) => get<Learning>('/admin/analytics/learning', p),
  affiliates: (p: RangeParams) => get<AffiliateShare>('/admin/analytics/affiliates', p),
  cohorts: (months: number) => get<Cohort[]>('/admin/analytics/cohorts', { months }),
}

const opts = { placeholderData: keepPreviousData, staleTime: 60_000 } as const

export const useOverview = (p: RangeParams) =>
  useQuery({
    queryKey: ['admin', 'analytics', 'overview', p],
    queryFn: () => analyticsApi.overview(p),
    ...opts,
  })
export const useMembersReport = (p: RangeParams) =>
  useQuery({
    queryKey: ['admin', 'analytics', 'members', p],
    queryFn: () => analyticsApi.members(p),
    ...opts,
  })
export const useRevenueReport = (p: RangeParams) =>
  useQuery({
    queryKey: ['admin', 'analytics', 'revenue', p],
    queryFn: () => analyticsApi.revenue(p),
    ...opts,
  })
export const useLearningReport = (p: RangeParams) =>
  useQuery({
    queryKey: ['admin', 'analytics', 'learning', p],
    queryFn: () => analyticsApi.learning(p),
    ...opts,
  })
export const useAffiliateReport = (p: RangeParams) =>
  useQuery({
    queryKey: ['admin', 'analytics', 'affiliates', p],
    queryFn: () => analyticsApi.affiliates(p),
    ...opts,
  })
export const useCohorts = (months: number) =>
  useQuery({
    queryKey: ['admin', 'analytics', 'cohorts', months],
    queryFn: () => analyticsApi.cohorts(months),
    ...opts,
  })
