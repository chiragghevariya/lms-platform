import { useMutation } from '@tanstack/react-query'
import { Smartphone } from 'lucide-react'
import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { toast } from 'sonner'
import { Seo } from '@/components/Seo'
import { Alert } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { FormField } from '@/components/ui/form-field'
import { Input } from '@/components/ui/input'
import { homePathFor } from '@/lib/membership'
import { useAuthStore } from '@/stores/auth-store'
import { authApi } from '../api'
import { AuthCard } from '../components/AuthCard'
import { useSetSessionUser } from '../hooks'

/** Phone OTP verification (6 digits, 5-minute expiry). */
export default function VerifyPhonePage() {
  const user = useAuthStore((s) => s.user)!
  const setSessionUser = useSetSessionUser()
  const navigate = useNavigate()
  const [code, setCode] = useState('')
  const send = useMutation({ mutationFn: authApi.sendPhoneOtp })
  const verify = useMutation({
    mutationFn: authApi.verifyPhoneOtp,
    onSuccess: (u) => {
      setSessionUser(u)
      toast.success('Phone number verified')
      navigate(`${homePathFor(u)}/security`, { replace: true })
    },
  })

  if (!user.phone) {
    return (
      <AuthCard title="Add a phone number">
        <Alert>Add your phone number in your profile first, then come back to verify it.</Alert>
        <Button asChild>
          <Link to={`${homePathFor(user)}/profile`}>Go to profile</Link>
        </Button>
      </AuthCard>
    )
  }

  if (user.phone_verified) {
    return (
      <AuthCard title="Phone verified">
        <Alert variant="success">{user.phone} is verified.</Alert>
        <Button asChild>
          <Link to="/app">Back to dashboard</Link>
        </Button>
      </AuthCard>
    )
  }

  const sent = send.isSuccess
  return (
    <AuthCard title="Verify your phone" description={`We'll send a 6-digit code to ${user.phone}.`}>
      <Seo title="Verify phone" noindex />
      <Smartphone className="text-primary mx-auto size-10" aria-hidden />
      {send.error && <Alert variant="destructive">{send.error.message}</Alert>}
      {!sent ? (
        <Button loading={send.isPending} onClick={() => send.mutate()}>
          Send code
        </Button>
      ) : (
        <form
          className="grid gap-4"
          onSubmit={(e) => {
            e.preventDefault()
            verify.mutate(code)
          }}
        >
          <Alert variant="info" role="status">
            Code sent. It expires in 5 minutes.
          </Alert>
          <FormField id="otp" label="Verification code" error={verify.error?.message}>
            {(a) => (
              <Input
                {...a}
                value={code}
                onChange={(e) => setCode(e.target.value.replace(/\D/g, '').slice(0, 6))}
                inputMode="numeric"
                autoComplete="one-time-code"
                className="text-center text-2xl tracking-[0.5em]"
              />
            )}
          </FormField>
          <Button type="submit" loading={verify.isPending} disabled={code.length !== 6}>
            Verify
          </Button>
          <Button type="button" variant="link" disabled={send.isPending} onClick={() => send.mutate()}>
            Resend code
          </Button>
        </form>
      )}
    </AuthCard>
  )
}
