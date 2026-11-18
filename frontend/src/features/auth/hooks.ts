import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect } from 'react'
import { ApiError } from '@/lib/api-client'
import { useAuthStore } from '@/stores/auth-store'
import type { User } from '@/types/api'
import { authApi } from './api'

export const meQueryKey = ['me'] as const

/** Hydrates the session once on boot and keeps the auth store in sync. */
export function useSession() {
  const setUser = useAuthStore((s) => s.setUser)
  const query = useQuery({
    queryKey: meQueryKey,
    queryFn: async () => {
      try {
        return await authApi.session()
      } catch (e) {
        if (e instanceof ApiError && e.isUnauthenticated) return null
        throw e
      }
    },
    staleTime: 5 * 60_000,
    retry: false,
  })

  useEffect(() => {
    if (query.isSuccess) setUser(query.data)
  }, [query.isSuccess, query.data, setUser])

  return query
}

function useSetSessionUser() {
  const qc = useQueryClient()
  const setUser = useAuthStore((s) => s.setUser)
  return (user: User | null) => {
    qc.setQueryData(meQueryKey, user)
    setUser(user)
  }
}

export function useLogin() {
  const setSessionUser = useSetSessionUser()
  return useMutation({
    mutationFn: authApi.login,
    onSuccess: (payload) => {
      if (!payload.two_factor_required) setSessionUser(payload.user)
    },
  })
}

export function useTwoFactorChallenge() {
  const setSessionUser = useSetSessionUser()
  return useMutation({
    mutationFn: authApi.twoFactorChallenge,
    onSuccess: (payload) => {
      if (!payload.two_factor_required) setSessionUser(payload.user)
    },
  })
}

export function useRegister() {
  const setSessionUser = useSetSessionUser()
  return useMutation({
    mutationFn: authApi.register,
    onSuccess: (payload) => {
      if (!payload.two_factor_required) setSessionUser(payload.user)
    },
  })
}

/** Re-reads the session from the server (after OAuth redirects, profile changes, ...). */
export function useRefreshSession() {
  const setSessionUser = useSetSessionUser()
  return async () => {
    try {
      const user = await authApi.me()
      setSessionUser(user)
      return user
    } catch {
      setSessionUser(null)
      return null
    }
  }
}

export { useSetSessionUser }

export function useLogout() {
  const qc = useQueryClient()
  const setUser = useAuthStore((s) => s.setUser)
  return useMutation({
    mutationFn: authApi.logout,
    onSettled: () => {
      qc.clear()
      qc.setQueryData(meQueryKey, null)
      setUser(null)
    },
  })
}

export function useVerifyEmail() {
  const setSessionUser = useSetSessionUser()
  return useMutation({ mutationFn: authApi.verifyEmail, onSuccess: (user) => setSessionUser(user) })
}
