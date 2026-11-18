import type { GatewayName, Payment, Role, Subscription, SubscriptionStatus } from '@/types/api'

export type UserStatus = 'active' | 'suspended' | 'banned'

/** Row of GET /admin/users (AdminUserResource). */
export interface AdminUser {
  id: string
  name: string
  email: string
  phone: string | null
  country: string | null
  avatar_url: string | null
  roles: Role[]
  status: UserStatus
  status_reason: string | null
  email_verified: boolean
  membership: {
    tier: number
    plan: string
    status: SubscriptionStatus
    complimentary: boolean
    ends_at: string | null
  } | null
  last_login_at: string | null
  created_at: string | null
}

export interface AdminUserDetail extends AdminUser {
  bio: string | null
  timezone: string
  phone_verified: boolean
  two_factor: boolean
  status_changed_at: string | null
  is_staff: boolean
  subscriptions: Subscription[]
  payments: Payment[]
  stats: {
    enrollments: number
    completed_courses: number
    certificates: number
    watch_seconds: number
    lifetime_value: Record<string, number>
  }
  logins: { event: string; ip: string | null; at: string }[]
  activity: {
    id: number
    event: string | null
    description: string
    causer: string | null
    created_at: string | null
  }[]
}

export interface AdminUserFilters {
  search?: string
  role?: Role | ''
  status?: UserStatus | ''
  tier?: '' | 'none' | '1' | '2' | '3'
  country?: string
  verified?: '' | '1' | '0'
  sort?: string
  page?: number
  per_page?: number
}

/** AdminPaymentResource. */
export interface AdminPayment extends Payment {
  type: 'membership' | 'course'
  currency: string
  amount_minor: number
  refunded_minor: number
  refundable_minor: number
  refunded_at: string | null
  disputed_at: string | null
  gateway_payment_id: string | null
  billing_country: string | null
  user: { id: string; name: string; email: string } | null
  subscription_id: string | null
}

export interface AdminPaymentDetail extends AdminPayment {
  history: {
    event: string | null
    description: string
    properties: Record<string, unknown>
    causer: string | null
    created_at: string | null
  }[]
}

export interface PaymentFilters {
  search?: string
  status?: '' | Payment['status']
  gateway?: '' | GatewayName
  type?: '' | 'membership' | 'course'
  currency?: string
  country?: string
  refunded?: '' | '1' | '0'
  from?: string
  to?: string
  page?: number
}

export interface PaymentTotals {
  currency: string
  gross: number
  refunded: number
  count: number
}

export type AdminSubscription = Subscription & {
  user?: { id: string; name: string; email: string }
  created_at?: string | null
  notes?: string | null
}

export interface AdminSubscriptionDetail extends AdminSubscription {
  gateway_subscription_id: string | null
  payments: AdminPayment[]
}

export interface SubscriptionFilters {
  search?: string
  status?: '' | SubscriptionStatus
  gateway?: '' | GatewayName
  tier?: '' | '1' | '2' | '3'
  renewing?: '' | '1' | '0'
  page?: number
}

export interface Coupon {
  id: string
  code: string
  type: 'percent' | 'fixed'
  value: number
  currency: string | null
  max_uses: number | null
  times_used: number
  expires_at: string | null
  is_active: boolean
  razorpay_offer_id: string | null
  plan_ids?: string[]
}

export interface CouponInput {
  code?: string
  type?: 'percent' | 'fixed'
  value?: number
  currency?: string | null
  max_uses?: number | null
  expires_at?: string | null
  is_active?: boolean
  razorpay_offer_id?: string | null
  plan_ids?: string[]
}

export interface PageMetaLite {
  current_page: number
  last_page: number
  total: number
  per_page?: number
}
