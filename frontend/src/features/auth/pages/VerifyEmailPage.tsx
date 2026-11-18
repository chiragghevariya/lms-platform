import { useEffect, useRef } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { Alert } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { FullPageSpinner } from '@/components/FullPageSpinner'
import { Seo } from '@/components/Seo'
import { AuthCard } from '../components/AuthCard'
import { useVerifyEmail } from '../hooks'

/** Landing page for the email link; must be signed in (wrapped in RequireAuth). */
export default function VerifyEmailPage() {
  const [params] = useSearchParams()
  const path = params.get('url') ?? ''
  const verify = useVerifyEmail()
  const started = useRef(false)

  useEffect(() => {
    if (path.startsWith('/api/v1/auth/email/verify/') && !started.current) {
      started.current = true
      verify.mutate(path)
    }
  }, [path, verify])

  if (!path.startsWith('/api/v1/auth/email/verify/')) {
    return (
      <AuthCard title="Invalid link">
        <Alert variant="destructive">This verification link is invalid.</Alert>
      </AuthCard>
    )
  }
  if (verify.isPending || verify.isIdle) return <FullPageSpinner label="Verifying email" />

  return (
    <AuthCard title={verify.isSuccess ? 'Email verified' : 'Verification failed'}>
      <Seo title="Verify email" noindex />
      {verify.isSuccess ? (
        <Alert variant="success">Thanks! Your email address is confirmed.</Alert>
      ) : (
        <Alert variant="destructive">
          {verify.error?.message ?? 'The link is invalid or expired.'} You can request a new link from your
          dashboard.
        </Alert>
      )}
      <Button asChild>
        <Link to="/app">Go to dashboard</Link>
      </Button>
    </AuthCard>
  )
}
