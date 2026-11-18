import type { Money } from './api'

export type CourseLevel = 'beginner' | 'intermediate' | 'advanced' | 'all_levels'
export type CourseStatus = 'draft' | 'review' | 'published'
export type LessonType = 'video' | 'text' | 'pdf' | 'quiz' | 'assignment' | 'live'
export type LockReason = 'login_required' | 'membership_required' | 'drip_locked' | 'unpublished'

export interface Category {
  id: string
  name: string
  slug: string
  description: string | null
  courses_count?: number
}

export interface CourseCard {
  id: string
  slug: string
  title: string
  subtitle: string | null
  thumbnail_url: string | null
  /** WebP responsive variants ("url 480w, …"). */
  thumbnail_srcset?: string | null
  level: CourseLevel
  language: string
  required_tier: 1 | 2 | 3 | null
  tier: 'starter' | 'pro' | 'elite' | null
  instructor: { id: string; name: string; avatar_url: string | null }
  category: { slug: string; name: string } | null
  duration_minutes: number
  lessons_count: number
  rating_avg: number
  rating_count: number
  is_featured: boolean
  price: Money | null
  status: CourseStatus
  published_at: string | null
  has_access?: boolean
}

export interface OutlineLesson {
  id: string
  title: string
  type: LessonType
  duration_seconds: number
  is_preview: boolean
  drip_days: number
  unlocked: boolean
  lock_reason: LockReason | null
  available_at: string | null
  completed: boolean
}

export interface CourseDetail extends CourseCard {
  description: string | null
  tags: string[]
  has_promo: boolean
  instructors: {
    id: string
    name: string
    avatar_url: string | null
    headline: string | null
    bio: string | null
  }[]
  curriculum: { id: string; title: string; lessons: OutlineLesson[] }[]
  access: {
    has_access: boolean
    reason: 'manage' | 'purchase' | 'tier' | null
    required_tier: number | null
    purchased: boolean
    can_purchase: boolean
    enrolled: boolean
    progress_percent: number
    next_lesson_id: string | null
    completed_at: string | null
    certificate_id: string | null
    bookmarked: boolean
  }
  my_review: { rating: number; comment: string | null } | null
}

export interface Review {
  id: string
  rating: number
  comment: string | null
  user: { name: string; avatar_url: string | null }
  created_at: string
}

export interface Playback {
  url: string
  poster: string | null
  expires_at: string
  ttl_seconds?: number
  /** Viewer identity drawn over the video (deters screen recording / account sharing). */
  watermark?: { text: string; id: string } | null
}

export interface LearnLesson {
  id: string
  title: string
  type: LessonType
  content: string | null
  duration_seconds: number
  is_preview: boolean
  section: string | null
  video: { status: string } | null
  has_attachment: boolean
  resources: { id: string; title: string; size: number; mime: string | null }[]
  progress: {
    position_seconds: number
    watched_seconds: number
    completed: boolean
    heartbeat_seconds: number
    can_mark_complete: boolean
  }
  bookmarked: boolean
  notes: { id: string; body: string; position_seconds: number | null; created_at: string }[]
  prev_id: string | null
  next_id: string | null
  course: { id: string; slug: string; title: string }
}

export interface VideoAsset {
  id: string
  title: string
  status: 'uploading' | 'processing' | 'ready' | 'failed'
  duration_seconds: number | null
  error: string | null
}

export interface StudioLesson {
  id: string
  title: string
  type: LessonType
  content: string | null
  duration_seconds: number
  is_preview: boolean
  drip_days: number
  has_attachment: boolean
  video: VideoAsset | null
  resources: { id: string; title: string; size: number; mime: string | null }[]
}

export interface StudioCourse extends CourseCard {
  description: string | null
  category_id: string | null
  tags: string[]
  currency: string | null
  price_amount: number | null
  promo_video: VideoAsset | null
  review_notes: string | null
  submitted_at: string | null
  readiness: string[]
  is_owner: boolean
  sections: { id: string; title: string; lessons: StudioLesson[] }[]
}

export interface UploadTicket {
  protocol: 'tus' | 'http'
  endpoint: string
  headers: Record<string, string>
  expires_at: number
}
