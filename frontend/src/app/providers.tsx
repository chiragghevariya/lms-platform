import { QueryClientProvider } from '@tanstack/react-query'
import { type ReactNode, useEffect } from 'react'
import { HelmetProvider } from 'react-helmet-async'
import { toast, Toaster } from 'sonner'
import { meQueryKey, useSession } from '@/features/auth/hooks'
import { CookieConsent } from '@/features/privacy/CookieConsent'
import { setUnauthorizedHandler } from '@/lib/api-client'
import { useAuthStore } from '@/stores/auth-store'
import { applyTheme, useThemeStore } from '@/stores/theme-store'
import { queryClient } from './query-client'

function SessionBootstrap({ children }: { children: ReactNode }) {
  useSession()

  useEffect(() => {
    // Any 401/419 mid-session drops the user to guest; route guards redirect to /login.
    setUnauthorizedHandler((code) => {
      if (code === 'device_signed_out') {
        toast.error('You were signed out because your account was used on another device.', {
          id: 'device-signed-out',
          description: 'Each membership can be signed in on 2 devices at a time.',
          duration: 10_000,
        })
      }
      if (useAuthStore.getState().user) {
        queryClient.setQueryData(meQueryKey, null)
        useAuthStore.getState().setUser(null)
      }
    })
    return () => setUnauthorizedHandler(null)
  }, [])

  return <>{children}</>
}

function ThemeSync() {
  const theme = useThemeStore((s) => s.theme)
  useEffect(() => {
    applyTheme(theme)
    if (theme !== 'system') return
    const mq = window.matchMedia('(prefers-color-scheme: dark)')
    const onChange = () => applyTheme('system')
    mq.addEventListener('change', onChange)
    return () => mq.removeEventListener('change', onChange)
  }, [theme])
  return null
}

export function AppProviders({ children }: { children: ReactNode }) {
  return (
    <HelmetProvider>
      <QueryClientProvider client={queryClient}>
        <ThemeSync />
        <SessionBootstrap>{children}</SessionBootstrap>
        <Toaster richColors closeButton position="top-right" />
        <CookieConsent />
      </QueryClientProvider>
    </HelmetProvider>
  )
}
