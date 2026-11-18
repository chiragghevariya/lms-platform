import type { MembershipTier } from './api'

export type LiveType = 'class' | 'webinar' | 'workshop' | 'mentorship_1on1'
export type LiveStatus = 'scheduled' | 'live' | 'ended' | 'cancelled'
export type LiveProvider = 'zoom' | 'agora' | '100ms' | 'external_link'

export interface LiveSession {
  id: string
  title: string
  description: string | null
  type: LiveType
  type_label: string
  status: LiveStatus
  starts_at: string
  ends_at: string
  duration: number
  timezone: string
  required_tier: MembershipTier | null
  provider: LiveProvider
  capacity: number | null
  registered_count: number
  seats_left: number | null
  is_series: boolean
  instructor: { id: string; name: string; avatar_url: string | null }
  course: { id: string; slug: string; title: string } | null
  cancel_reason: string | null
  has_replay: boolean
  replay_lesson_id: string | null
  viewer: {
    eligible: boolean
    registered: boolean
    can_register: boolean
    joinable: boolean
    join_opens_at: string
    attended: boolean | null
    can_manage: boolean
  } | null
  calendar: { google: string; ics: string }
}

export interface LiveReplay extends LiveSession {
  replay_duration_seconds: number | null
  locked: boolean
}

export type JoinPayload =
  | { mode: 'external'; url: string }
  | {
      mode: 'zoom_sdk'
      sdk_key: string
      signature: string
      meeting_number: string
      password: string
      user_name: string
      user_email: string
      customer_key: string
      fallback_url: string
    }

export interface InstructorLiveSession extends LiveSession {
  attended_count: number | null
  attendance_synced_at: string | null
  recording_status: string | null
}

export interface LiveOptions {
  providers: LiveProvider[]
  types: { value: LiveType; label: string }[]
  courses: { id: string; title: string }[]
  sdk_embedded: boolean
}

export interface LiveRegistrant {
  id: string
  name: string
  email: string
  registered_at: string
  attended: boolean
  joined_at: string | null
  left_at: string | null
  attended_minutes: number
}

export interface ScheduleInput {
  title: string
  description: string | null
  type: LiveType
  required_tier: number | null
  course_id: string | null
  starts_at: string // local wall-clock "YYYY-MM-DDTHH:mm" in `timezone`
  timezone: string
  duration: number
  capacity: number | null
  provider: LiveProvider
  join_url: string | null
  repeat: { weekdays: number[]; occurrences: number } | null
}
