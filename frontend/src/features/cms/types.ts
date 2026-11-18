export type SectionKey =
  'hero' | 'why' | 'featured' | 'pricing' | 'instructors' | 'testimonials' | 'faq' | 'cta'

export interface Cta {
  label: string
  url: string
}

export interface SectionContent {
  badge?: string | null
  eyebrow?: string | null
  title?: string | null
  highlight?: string | null
  subtitle?: string | null
  intro?: string | null
  primary_cta?: Cta | null
  secondary_cta?: Cta | null
  items?: { title: string; body?: string | null }[] | null
}

export interface CmsSection {
  key: SectionKey
  content: SectionContent
  is_visible: boolean
  sort: number
  customised: boolean
}

export type FaqCategory = 'general' | 'billing' | 'courses' | 'live' | 'affiliates'

export interface Faq {
  id: string
  question: string
  answer: string
  category: FaqCategory
  is_published?: boolean
  sort?: number
}

export interface Testimonial {
  id: string
  name: string
  role: string | null
  quote: string
  rating: number | null
  avatar_url: string | null
  is_published?: boolean
  sort?: number
}

export type BannerVariant = 'info' | 'promo' | 'warning'
export type BannerAudience = 'all' | 'guests' | 'members' | 'free'

export interface Banner {
  id: string
  message: string
  link_text: string | null
  link_url: string | null
  variant: BannerVariant
  audience?: BannerAudience
  starts_at?: string | null
  ends_at?: string | null
  is_active?: boolean
  is_live?: boolean
  updated_at?: string | null
}

export interface HomeContent {
  sections: CmsSection[]
  faqs: Faq[]
  testimonials: Testimonial[]
}

export interface CmsPageContent {
  slug: string
  title: string
  body: string
  updated_at: string
}

export interface AdminPage {
  slug: string
  title: string
  body: string | null
  is_published: boolean
  customised: boolean
  updated_at: string | null
}

export interface Post {
  id: string
  slug: string
  title: string
  excerpt: string | null
  cover_url: string | null
  status: 'draft' | 'published'
  published_at: string | null
  author: { name: string } | null
  seo_title: string | null
  seo_description: string | null
  reading_minutes: number
  updated_at: string
  body?: string
}

export interface PageMeta {
  total: number
  current_page: number
  last_page: number
  per_page: number
}

export interface SiteInfo {
  brand: {
    name: string
    tagline: string | null
    logo_url: string | null
    primary_color: string | null
    support_email: string | null
  }
  seo: {
    default_title: string | null
    default_description: string | null
    og_image_url: string | null
    twitter_handle: string | null
    allow_indexing: boolean
  }
  captcha?: { provider: 'turnstile' | null; site_key: string | null }
  tracking: { ga4_measurement_id: string | null; meta_pixel_id: string | null }
  banner: Banner | null
}

export interface SecretStatus {
  set: boolean
  last4: string | null
  source: 'admin' | 'env' | null
}

export interface TaxRate {
  country: string
  name: string
  rate: number
}

export interface SettingsValues {
  branding: {
    name: string | null
    tagline: string | null
    logo_url: string | null
    primary_color: string | null
    support_email: string | null
  }
  seo: {
    default_title: string | null
    default_description: string | null
    og_image_url: string | null
    twitter_handle: string | null
    allow_indexing: boolean | null
  }
  tax: {
    rates: TaxRate[]
    seller_name: string | null
    seller_address: string | null
    seller_tax_id: string | null
  }
  payments?: {
    stripe_key: string | null
    stripe_secret: SecretStatus
    stripe_webhook_secret: SecretStatus
    razorpay_key_id: string | null
    razorpay_key_secret: SecretStatus
    razorpay_webhook_secret: SecretStatus
  }
  tracking: {
    ga4_measurement_id: string | null
    ga4_api_secret: SecretStatus
    meta_pixel_id: string | null
    meta_capi_token: SecretStatus
    meta_test_event_code: string | null
  }
}

export type SettingsGroup = keyof SettingsValues

export interface SettingsResponse {
  values: SettingsValues
  can_manage_secrets: boolean
  webhooks: { stripe: string; razorpay: string }
}
