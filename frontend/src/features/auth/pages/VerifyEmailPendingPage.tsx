import { useMutation } from '@tanstack/react-query'
import { MailCheck } from 'lucide-react'
import { Link } from 'react-router-dom'
import { Seo } from '@/components/Seo'
import { Alert } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { useAuthStore } from '@/stores/auth-store'
import { authApi } from '../api'
import { AuthCard } from '../components/AuthCard'

/** Shown right after registration. */
export default function VerifyEmailPendingPage() {
  const user = useAuthStore((s) => s.user)
  const resend = useMutation({ mutationFn: authApi.resendVerification })

  return (
    <AuthCard
      title="Check your inbox"
      description={user ? `We sent a verification link to ${user.email}.` : undefined}
    >
      <Seo title="Verify your email" noindex />
      <MailCheck className="text-primary mx-auto size-12" aria-hidden />
      <p className="text-muted-foreground text-center text-sm">
        Click the link in the email to verify your account. You can keep exploring in the meantime.
      </p>
      {resend.isSuccess && <Alert variant="success">A new link is on its way.</Alert>}
      <div className="grid gap-2">
        <Button asChild>
          <Link to="/app">Go to my dashboard</Link>
        </Button>
        <Button variant="outline" loading={resend.isPending} onClick={() => resend.mutate()}>
          Resend email
        </Button>
      </div>
    </AuthCard>
  )
}
