import { useState } from 'react'
import { Link, Navigate, useLocation, useNavigate, useSearchParams } from 'react-router-dom'
import { Seo } from '@/components/Seo'
import { Alert } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { FormField } from '@/components/ui/form-field'
import { Input } from '@/components/ui/input'
import { ApiError } from '@/lib/api-client'
import { homePathFor } from '@/lib/membership'
import { AuthCard } from '../components/AuthCard'
import { useTwoFactorChallenge } from '../hooks'

export default function TwoFactorChallengePage() {
  const location = useLocation()
  const [params] = useSearchParams()
  const navigate = useNavigate()
  const state = location.state as { challenge?: string; from?: string } | null
  const challenge = state?.challenge ?? params.get('challenge') ?? ''
  const [useRecovery, setUseRecovery] = useState(false)
  const [value, setValue] = useState('')
  const [error, setError] = useState<string | null>(null)
  const mutation = useTwoFactorChallenge()

  if (!challenge) return <Navigate to="/login" replace />

  const onSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    setError(null)
    try {
      const payload = await mutation.mutateAsync({
        challenge_token: challenge,
        ...(useRecovery ? { recovery_code: value.trim() } : { code: value.replace(/\s/g, '') }),
      })
      if (!payload.two_factor_required) navigate(state?.from ?? homePathFor(payload.user), { replace: true })
    } catch (err) {
      if (err instanceof ApiError && err.status === 410) {
        navigate('/login', { replace: true })
        return
      }
      setError(err instanceof Error ? err.message : 'Verification failed.')
    }
  }

  return (
    <AuthCard
      title="Two-factor authentication"
      description={
        useRecovery
          ? 'Enter one of your saved recovery codes.'
          : 'Enter the 6-digit code from your authenticator app.'
      }
      footer={
        <Link to="/login" className="text-primary hover:underline">
          Back to log in
        </Link>
      }
    >
      <Seo title="Two-factor authentication" noindex />
      <form onSubmit={onSubmit} className="grid gap-4" noValidate>
        {error && <Alert variant="destructive">{error}</Alert>}
        <FormField id="code" label={useRecovery ? 'Recovery code' : 'Authentication code'}>
          {(a) => (
            <Input
              {...a}
              value={value}
              onChange={(e) => setValue(e.target.value)}
              autoComplete="one-time-code"
              inputMode={useRecovery ? 'text' : 'numeric'}
              maxLength={useRecovery ? 32 : 7}
              className="text-center text-lg tracking-[0.3em]"
            />
          )}
        </FormField>
        <Button type="submit" loading={mutation.isPending} disabled={!value}>
          Verify
        </Button>
        <Button
          type="button"
          variant="link"
          onClick={() => {
            setUseRecovery((v) => !v)
            setValue('')
          }}
        >
          {useRecovery ? 'Use authenticator code instead' : 'Use a recovery code'}
        </Button>
      </form>
    </AuthCard>
  )
}
