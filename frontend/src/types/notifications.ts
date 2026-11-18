export type ChannelKey = 'mail' | 'database' | 'whatsapp' | 'push' | 'sms'

export interface AppNotification {
  id: string
  event: string | null
  title: string
  body: string
  url: string | null
  read_at: string | null
  created_at: string
}

export interface PreferenceMatrix {
  categories: {
    key: string
    label: string
    events: {
      event: string
      label: string
      channels: Partial<Record<ChannelKey, { enabled: boolean; locked: boolean }>>
    }[]
  }[]
  channels: Record<ChannelKey, boolean>
  whatsapp: { opted_in: boolean; opted_in_at: string | null; phone: string | null; phone_verified: boolean }
  push_devices: number
}

export interface TemplateRow {
  event: string
  label: string
  category: string
  vars: string[]
  channels: {
    channel: ChannelKey
    customised: boolean
    subject: string | null
    body: string | null
    action_text: string | null
    whatsapp_template: string | null
    whatsapp_language: string | null
    whatsapp_params: string[]
  }[]
}

export interface Segment {
  tiers?: string[]
  status?: string[]
  course_id?: string | null
  inactive_days?: number | null
  countries?: string[]
  roles?: string[]
}

export interface CampaignRow {
  id: string
  name: string
  channels: ChannelKey[]
  segment: Segment
  status: 'draft' | 'scheduled' | 'sending' | 'sent' | 'cancelled'
  subject: string | null
  body: string | null
  action_text: string | null
  action_url: string | null
  whatsapp_template: string | null
  whatsapp_language: string | null
  whatsapp_params: string[]
  scheduled_at: string | null
  started_at: string | null
  finished_at: string | null
  audience_count: number
  created_by: string
  created_at: string
  stats: Partial<Record<ChannelKey, Record<string, number>>>
}

export interface LogRow {
  id: string
  event: string
  channel: ChannelKey
  status: 'skipped' | 'sending' | 'sent' | 'delivered' | 'read' | 'failed'
  provider: string | null
  recipient: string | null
  user: { name: string; email: string } | null
  error: string | null
  attempts: number
  retryable: boolean
  created_at: string
  sent_at: string | null
  delivered_at: string | null
  read_at: string | null
}

export const CHANNEL_LABEL: Record<ChannelKey, string> = {
  mail: 'Email',
  database: 'In-app',
  whatsapp: 'WhatsApp',
  push: 'Push',
  sms: 'SMS',
}
