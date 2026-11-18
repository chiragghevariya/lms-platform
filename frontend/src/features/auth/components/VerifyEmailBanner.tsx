import { useMutation } from '@tanstack/react-query'
import { MailWarning } from 'lucide-react'
import { Alert } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { useAuthStore } from '@/stores/auth-store'
import { authApi } from '../api'

export function VerifyEmailBanner() {
  const user = useAuthStore((s) => s.user)
  const resend = useMutation({ mutationFn: authApi.resendVerification })
  if (!user || user.email_verified) return null

  return (
    <Alert variant="info" className="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center">
      <MailWarning className="text-primary size-5 shrink-0" aria-hidden />
      <p className="flex-1">
        Please verify <strong>{user.email}</strong> to secure your account.
      </p>
      {resend.isSuccess ? (
        <span className="text-sm font-medium" role="status">
          Link sent — check your inbox.
        </span>
      ) : (
        <Button size="sm" variant="outline" loading={resend.isPending} onClick={() => resend.mutate()}>
          Resend link
        </Button>
      )}
    </Alert>
  )
}
