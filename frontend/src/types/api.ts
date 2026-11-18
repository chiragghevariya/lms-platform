// Mirrors Laravel API Resources under /api/v1. Keep in sync with docs/API.md.

export type Role = 'super_admin' | 'admin' | 'instructor' | 'student'
export type MembershipTier = 'starter' | 'pro' | 'elite'

export interface Money {
  amount: number
  currency: string
  formatted: string
}

export interface User {
  id: string
  name: string
  email: string
  phone: string | null
  avatar_url: string | null
  country: string | null
  timezone: string
  bio: string | null
  status: 'active' | 'suspended' | 'banned'
  email_verified: boolean
  phone_verified: boolean
  two_factor_enabled: boolean
  has_google: boolean
  profile: UserProfile
  roles: Role[]
  permissions?: string[]
  is_staff: boolean
  /** Set while an admin is viewing the app as this user. */
  impersonator?: { id: string; name: string } | null
  affiliate: { active: boolean; status: string | null; code: string | null; activated_at: string | null }
  membership: {
    tier: MembershipTier | null
    tier_rank: number
    ends_at: string | null
    is_lifetime: boolean
    status: SubscriptionStatus | null
  }
  billing?: { name: string | null; address: Partial<Record<string, string | null>>; tax_id: string | null }
  last_login_at: string | null
  created_at: string | null
}

export interface UserProfile {
  headline: string | null
  website: string | null
  city: string | null
  state: string | null
  occupation: string | null
  date_of_birth: string | null
  social_links: Partial<Record<'linkedin' | 'twitter' | 'instagram' | 'youtube' | 'github', string>>
  interests: string[]
  expertise: string[]
}

export interface Instructor {
  id: string
  name: string
  avatar_url: string | null
  headline: string | null
  bio: string | null
  expertise: string[]
  social_links: UserProfile['social_links']
}

export interface DeviceSession {
  id: string
  type: 'browser' | 'app'
  name: string
  ip_address: string | null
  user_agent: string | null
  last_active_at: string | null
  is_current: boolean
}

export interface LoginActivity {
  event: string
  channel: string | null
  ip_address: string | null
  user_agent: string | null
  created_at: string
}

export interface TwoFactorStatus {
  available: boolean
  enabled: boolean
  pending_confirmation: boolean
  recovery_codes_left: number
}

export interface TwoFactorSetup {
  qr_svg: string
  otpauth_url: string
  secret: string
  recovery_codes: string[]
}

export type PriceInterval = 'month' | 'year' | 'lifetime'
export type GatewayName = 'stripe' | 'razorpay' | 'manual'

export interface PlanFeature {
  key: string
  label: string
  value: boolean | string
}

export interface PlanPrice {
  id: string
  interval: PriceInterval
  price: Money
  currency: string
  trial_days: number
  is_active: boolean
  gateway: 'stripe' | 'razorpay'
  stripe_price_id?: string | null
  razorpay_plan_id?: string | null
}

export interface Plan {
  id: string
  name: string
  slug: string
  tier_level: 1 | 2 | 3
  tier: MembershipTier
  description: string | null
  features: PlanFeature[]
  is_active: boolean
  sort: number
  prices?: PlanPrice[]
}

export type SubscriptionStatus = 'incomplete' | 'trialing' | 'active' | 'past_due' | 'cancelled' | 'expired'

export interface Subscription {
  id: string
  status: SubscriptionStatus
  gateway: GatewayName
  is_active: boolean
  is_lifetime: boolean
  is_complimentary: boolean
  tier_level: number
  tier: MembershipTier
  plan: Plan
  price: PlanPrice | null
  current_period_start: string | null
  current_period_end: string | null
  trial_ends_at: string | null
  cancel_at_period_end: boolean
  can_resume: boolean
  cancelled_at: string | null
  ended_at: string | null
  past_due: boolean
  grace_ends_at: string | null
  scheduled_change: { price: PlanPrice | null; plan: Plan | null; effective_at: string | null } | null
}

export interface Payment {
  id: string
  invoice_number: string | null
  status: 'pending' | 'succeeded' | 'failed' | 'refunded'
  gateway: GatewayName
  description: string | null
  amount: Money
  subtotal: Money
  discount: Money
  tax: { name: string | null; rate: number; amount: Money }
  period_start: string | null
  period_end: string | null
  paid_at: string | null
  failure_reason: string | null
  has_pdf: boolean
  created_at: string | null
}

export interface RazorpayCheckoutPayload {
  key: string
  name: string
  description: string
  prefill: { name?: string; email?: string; contact?: string }
  notes: Record<string, string>
  subscription_id?: string
  order_id?: string
  amount?: number
  currency?: string
}

export interface CheckoutResult {
  gateway: 'stripe' | 'razorpay'
  action: 'redirect' | 'razorpay_modal'
  redirect_url: string | null
  razorpay: RazorpayCheckoutPayload | null
}

export interface ProrationPreview {
  direction: 'upgrade' | 'downgrade'
  effective: 'now' | 'period_end'
  effective_at: string | null
  credit: number
  charge: number
  amount_due_now: number
  currency: string
}

export interface CouponQuote {
  code: string
  discount: Money
  final_price: Money
  applies_to: 'one_time' | 'first_payment'
}

export type AuthPayload =
  | {
      two_factor_required: false
      challenge_token: null
      user: User
      token: string | null
      token_type: 'Bearer' | null
    }
  | { two_factor_required: true; challenge_token: string; user: null; token: null; token_type: null }

export interface Resource<T> {
  data: T
}

export interface Paginated<T> {
  data: T[]
  links: { first: string | null; last: string | null; prev: string | null; next: string | null }
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
    from: number | null
    to: number | null
  }
}

export interface ApiErrorBody {
  message: string
  errors?: Record<string, string[]>
  code?: string
  required_tier?: number
  required_tier_slug?: MembershipTier
  retry_after?: number
}
