import { http } from '@/lib/api-client'
import type { User } from '@/types/api'

export const profileApi = {
  update: async (
    input: Partial<Pick<User, 'name' | 'phone' | 'timezone' | 'country' | 'bio'>> & {
      profile?: Partial<User['profile']>
    },
  ) => (await http.patch<{ data: User }>('/me', input)).data.data,
  updatePassword: async (input: {
    current_password: string
    password: string
    password_confirmation: string
  }) => (await http.put<{ message: string }>('/me/password', input)).data,
  uploadAvatar: async (file: File) => {
    const body = new FormData()
    body.append('avatar', file)
    return (await http.post<{ data: User }>('/me/avatar', body)).data.data
  },
  removeAvatar: async () => (await http.delete<{ data: User }>('/me/avatar')).data.data,
}
