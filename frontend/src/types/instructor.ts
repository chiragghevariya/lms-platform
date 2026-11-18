export interface InstructorKpis {
  students: number
  new_enrollments: number
  completion_rate: number | null
  avg_quiz_score: number | null
  watch_minutes: number
  rating_avg: number | null
  rating_count: number
  live_attendance_rate: number | null
  open_questions: number
  pending_submissions: number
}

export interface InstructorAnalytics {
  range_days: number
  kpis: InstructorKpis
  series: {
    daily: { date: string; enrollments: number; watch_minutes: number }[]
    earnings: { period: string; currency: string; amount: number; estimate: boolean }[]
  }
  courses: {
    id: string
    title: string
    status: string
    students: number
    completion_rate: number | null
    watch_minutes: number
    avg_quiz_score: number | null
    rating_avg: number | null
    rating_count: number
  }[]
}

export interface ActivityItem {
  type: 'enrollment' | 'review' | 'question' | 'submission'
  at: string
  text: string
  link: string | null
}

export interface CourseStudent {
  id: string
  name: string
  email: string
  avatar_url: string | null
  enrolled_at: string | null
  source: string
  progress_percent: number
  completed_lessons: number
  last_activity_at: string | null
  completed_at: string | null
  avg_quiz_score: number | null
}

export interface QaAnswer {
  id: string
  body: string
  author: { name: string }
  is_instructor: boolean
  is_mine: boolean
  created_at: string
}

export interface QaQuestion {
  id: string
  title: string
  body: string
  author: { name: string }
  is_mine: boolean
  lesson: { id: string; title: string } | null
  course: { id: string; slug: string; title: string }
  answers_count: number
  instructor_answered: boolean
  created_at: string
  answers: QaAnswer[] | null
}

export interface Announcement {
  id: string
  title: string
  body: string
  recipients_count: number
  author: { name: string }
  created_at: string
}

export interface StatementLine {
  type: 'subscription_pool' | 'course_sale'
  description: string
  course?: string | null
  watch_seconds: number
  gross: number
  amount: number
}

export interface Statement {
  id?: string
  period?: string
  currency: string
  status?: 'draft' | 'finalized' | 'paid'
  pool_percent: number
  revenue_share_percent: number
  subscription_revenue: number
  pool_amount: number
  watch_seconds: number
  total_watch_seconds: number
  subscription_earnings: number
  sales_earnings: number
  total: number
  finalized_at?: string | null
  paid_at?: string | null
  paid_reference?: string | null
  lines: StatementLine[] | null
}

export interface EarningsOverview {
  revenue_share_percent: number
  pool_percent: number
  current: { period: string; estimate: Statement[] }
  statements: Statement[]
}

export interface InstructorProfileData {
  headline: string | null
  bio: string | null
  expertise: string[]
  website: string | null
  social_links: Record<string, string>
  revenue_share_percent: number
  pool_percent: number
  payout: {
    method: 'bank' | 'upi' | 'paypal' | null
    summary: string | null
    tax_id: string | null
    complete: boolean
  }
}

export interface InstructorApplication {
  id: string
  headline: string
  expertise: string[]
  bio: string
  motivation: string
  links: string[]
  status: 'pending' | 'approved' | 'rejected'
  review_notes: string | null
  reviewed_at: string | null
  created_at: string
  applicant?: { id: string; name: string; email: string }
}

export interface AdminStatement extends Statement {
  instructor: { id: string; name: string; email: string }
  payout: {
    method: string | null
    summary?: string | null
    details?: Record<string, string> | null
    tax_id?: string | null
  }
}
