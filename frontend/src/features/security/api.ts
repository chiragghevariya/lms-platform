import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { getData, http } from '@/lib/api-client'
import type { DeviceSession, LoginActivity, Paginated, TwoFactorSetup, TwoFactorStatus } from '@/types/api'

export const securityApi = {
  sessions: () => getData<DeviceSession[]>('/me/sessions'),
  revokeSession: (id: string) => http.delete(`/me/sessions/${id}`),
  logoutOthers: async (password: string) =>
    (await http.post<{ data: { revoked: number } }>('/me/sessions/logout-others', { password })).data.data,
  activity: async () => (await http.get<Paginated<LoginActivity>>('/me/login-activity')).data,
  twoFactor: () => getData<TwoFactorStatus>('/me/two-factor'),
  enableTwoFactor: async (password: string) =>
    (await http.post<{ data: TwoFactorSetup }>('/me/two-factor', { password })).data.data,
  confirmTwoFactor: (code: string) => http.post('/me/two-factor/confirm', { code }),
  disableTwoFactor: (password: string) => http.delete('/me/two-factor', { data: { password } }),
  regenerateCodes: async (password: string) =>
    (await http.post<{ data: { recovery_codes: string[] } }>('/me/two-factor/recovery-codes', { password }))
      .data.data,
}

export const useSessions = () => useQuery({ queryKey: ['me', 'sessions'], queryFn: securityApi.sessions })
export const useLoginActivity = () =>
  useQuery({ queryKey: ['me', 'activity'], queryFn: securityApi.activity })
export const useTwoFactorStatus = () =>
  useQuery({ queryKey: ['me', 'two-factor'], queryFn: securityApi.twoFactor })

export function useSecurityMutation<TVars, TData>(fn: (v: TVars) => Promise<TData>, keys: string[][]) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: fn,
    onSuccess: () => keys.forEach((queryKey) => qc.invalidateQueries({ queryKey })),
  })
}
