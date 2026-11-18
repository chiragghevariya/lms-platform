import type { CourseCard } from './courses'

export interface Streak {
  current: number
  longest: number
  active_today: boolean
}

export interface EarnedBadge {
  key: string
  name: string
  description: string
  icon: string
  awarded_at: string
}

export interface Dashboard {
  continue_learning: {
    course: CourseCard
    percent: number
    completed_lessons: number
    total_lessons: number
    next_lesson_id: string | null
    last_activity_at: string | null
  }[]
  pending_assignments: {
    lesson_id: string
    title: string
    course: { slug: string; title: string }
    status: 'not_started' | 'returned'
    due_at: string | null
  }[]
  upcoming_live_classes: { id: string; title: string; starts_at: string; joinable: boolean }[]
  stats: { points: number; streak: Streak; courses_completed: number; certificates: number }
  recent_badges: EarnedBadge[]
  recommended: CourseCard[]
}

export interface Achievements {
  points: number
  streak: Streak
  badges: (Omit<EarnedBadge, 'awarded_at'> & { earned: boolean; awarded_at: string | null })[]
  recent_points: { points: number; reason: string; created_at: string }[]
}

export interface MyCourse {
  course: CourseCard
  progress_percent: number
  completed_lessons: number
  total_lessons: number
  last_lesson_id: string | null
  last_accessed_at: string | null
  completed_at: string | null
}

export interface Certificate {
  id?: string
  verification_code: string
  holder_name: string
  course_title: string
  course_slug: string
  instructor_name: string | null
  issuer: string
  issued_at: string
  verify_url: string
  linkedin_url?: string
}

export type CertificateVerification = { valid: boolean } & Partial<Certificate>

export interface BookmarkItem {
  id: string
  course: { id: string; slug: string; title: string; thumbnail_url: string | null }
  lesson: { id: string; title: string; type: string } | null
  position_seconds: number | null
  label: string | null
  created_at: string
}

export interface NoteItem {
  id: string
  body: string
  position_seconds: number | null
  created_at: string
  lesson: { id: string; title: string }
  course: { id: string; slug: string; title: string }
}

export interface LearningProfile {
  skills: string[]
  learning_goals: string | null
  is_public: boolean
  public_handle: string | null
}

export interface PublicLearner {
  name: string
  avatar_url: string | null
  headline: string | null
  bio: string | null
  skills: string[]
  learning_goals: string | null
  points: number
  badges: EarnedBadge[]
  certificates: Certificate[]
}

// ---- Quizzes ---------------------------------------------------------------

export type QuestionType = 'mcq_single' | 'mcq_multi' | 'true_false' | 'short_text'

export interface QuizSummary {
  id: string
  question_count: number
  pass_mark: number
  time_limit_seconds: number | null
  attempts_allowed: number | null
  attempts_used: number
  best_percent: number | null
  passed: boolean
  open_attempt_id: string | null
  attempts: {
    id: string
    percent: number | null
    passed: boolean | null
    status: string
    submitted_at: string | null
  }[]
}

export interface AttemptQuestion {
  id: string
  type: QuestionType
  prompt: string
  points: number
  options: { id: string; text: string; is_correct?: boolean }[]
  answer: { option_ids: string[]; text: string | null }
  is_correct: boolean | null
  points_awarded: number | null
  explanation: string | null
  accepted_answers: string[] | null
}

export interface QuizAttempt {
  id: string
  status: 'in_progress' | 'submitted' | 'expired'
  started_at: string
  expires_at: string | null
  seconds_left: number | null
  score: number | null
  max_score: number
  percent: number | null
  passed: boolean | null
  pass_mark: number
  questions: AttemptQuestion[]
}

export type AnswerMap = Record<string, { option_ids?: string[]; text?: string }>

export interface StudioQuiz {
  id: string
  pass_mark: number
  time_limit_seconds: number | null
  attempts_allowed: number | null
  shuffle: boolean
  show_answers: boolean
  questions: StudioQuestion[]
}

export interface StudioQuestion {
  id?: string
  has_answers?: boolean
  type: QuestionType
  prompt: string
  points: number
  explanation: string | null
  accepted_answers: string[] | null
  options: { text: string; is_correct: boolean }[]
}

// ---- Assignments -------------------------------------------------------------

export interface Submission {
  id: string
  status: 'submitted' | 'graded' | 'returned'
  text: string | null
  files: { index: number; name: string; size: number }[]
  score: number | null
  feedback: string | null
  graded_at: string | null
  submitted_at: string
  revision: number
}

export interface AssignmentDetail {
  id: string
  instructions: string | null
  due_at: string | null
  max_score: number
  pass_score: number | null
  file_types: string[]
  max_file_mb: number
  allow_text: boolean
  submission: Submission | null
}

export interface AssignmentSettings {
  instructions: string | null
  due_days: number | null
  max_score: number
  pass_score: number | null
  file_types: string[]
  max_file_mb: number
  allow_text: boolean
}

export interface GradingItem extends Submission {
  learner: { name: string; email: string }
  lesson: { id: string; title: string }
  course: { id: string; title: string }
  max_score: number
}
