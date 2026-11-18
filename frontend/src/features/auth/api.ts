import { ensureCsrfCookie, getData, http } from '@/lib/api-client'
import { env } from '@/lib/env'
import type { AuthPayload, User } from '@/types/api'
import type { LoginInput, RegisterInput } from './schemas'

const browserTimezone = () => Intl.DateTimeFormat().resolvedOptions().timeZone

export const authApi = {
  me: () => getData<User>('/me'),
  /** Boot probe: the user, or null for guests (200 either way — no 401 noise). */
  session: () => getData<User | null>('/auth/session'),

  async login(input: LoginInput) {
    await ensureCsrfCookie()
    const res = await http.post<{ data: AuthPayload }>('/auth/login', input)
    return res.data.data
  },

  async register(input: RegisterInput) {
    await ensureCsrfCookie()
    const res = await http.post<{ data: AuthPayload }>('/auth/register', {
      ...input,
      phone: input.phone || null,
      timezone: browserTimezone(),
    })
    return res.data.data
  },

  logout: () => http.post('/auth/logout'),

  async forgotPassword(email: string) {
    await ensureCsrfCookie()
    return (await http.post<{ message: string }>('/auth/forgot-password', { email })).data
  },

  async resetPassword(input: {
    token: string
    email: string
    password: string
    password_confirmation: string
  }) {
    await ensureCsrfCookie()
    return (await http.post<{ message: string }>('/auth/reset-password', input)).data
  },

  async twoFactorChallenge(input: { challenge_token: string; code?: string; recovery_code?: string }) {
    await ensureCsrfCookie()
    const res = await http.post<{ data: AuthPayload }>('/auth/two-factor-challenge', input)
    return res.data.data
  },

  sendPhoneOtp: async () =>
    (await http.post<{ message: string; data?: { expires_at: string } }>('/me/phone/otp')).data,
  verifyPhoneOtp: async (code: string) =>
    (await http.post<{ data: User }>('/me/phone/verify', { code })).data.data,

  /** Full-page navigation target for "Continue with Google" (Laravel web route). */
  googleRedirectUrl: () => `${env.apiUrl}/auth/google/redirect`,

  resendVerification: () => http.post<{ message: string }>('/auth/email/verification-notification'),

  /** `path` is the signed relative API path from the email link, e.g. /api/v1/auth/email/verify/... */
  verifyEmail: async (path: string) => {
    const res = await http.get<{ data: User }>(path.replace(/^\/api\/v1/, ''))
    return res.data.data
  },
}
