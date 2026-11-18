import { zodResolver } from '@hookform/resolvers/zod'
import { CaptchaWidget } from '@/components/CaptchaWidget'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { Link, useLocation, useNavigate, useSearchParams } from 'react-router-dom'
import { Seo } from '@/components/Seo'
import { Alert } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { FormField } from '@/components/ui/form-field'
import { Input } from '@/components/ui/input'
import { applyServerErrors } from '@/lib/form-errors'
import { homePathFor } from '@/lib/membership'
import { AuthCard } from '../components/AuthCard'
import { GoogleButton, OrDivider } from '../components/GoogleButton'
import { OAUTH_ERRORS } from '../oauth-errors'
import { useLogin } from '../hooks'
import { type LoginInput, loginSchema } from '../schemas'

export default function LoginPage() {
  const login = useLogin()
  const navigate = useNavigate()
  const from = (useLocation().state as { from?: string } | null)?.from
  const [params] = useSearchParams()
  const oauthError = params.get('error')
  const [formError, setFormError] = useState<string | null>(
    oauthError ? (OAUTH_ERRORS[oauthError] ?? 'Sign-in failed.') : null,
  )
  const { register, handleSubmit, setError, formState } = useForm<LoginInput>({
    resolver: zodResolver(loginSchema),
    defaultValues: { email: '', password: '', remember: true },
  })

  const onSubmit = handleSubmit(async (values) => {
    setFormError(null)
    try {
      const payload = await login.mutateAsync(values)
      if (payload.two_factor_required) {
        navigate('/two-factor', { state: { challenge: payload.challenge_token, from } })
        return
      }
      navigate(from ?? homePathFor(payload.user), { replace: true })
    } catch (e) {
      setFormError(applyServerErrors(e, setError, ['email', 'password']))
    }
  })

  const { errors } = formState
  return (
    <AuthCard
      title="Welcome back"
      description="Log in to continue learning."
      footer={
        <>
          New here?{' '}
          <Link to="/register" className="text-primary font-medium hover:underline">
            Create an account
          </Link>
        </>
      }
    >
      <Seo title="Log in" path="/login" />
      <GoogleButton />
      <OrDivider />
      <form onSubmit={onSubmit} className="grid gap-4" noValidate>
        {formError && <Alert variant="destructive">{formError}</Alert>}
        <FormField id="email" label="Email" error={errors.email?.message}>
          {(a) => <Input type="email" autoComplete="email" {...a} {...register('email')} />}
        </FormField>
        <FormField id="password" label="Password" error={errors.password?.message}>
          {(a) => <Input type="password" autoComplete="current-password" {...a} {...register('password')} />}
        </FormField>
        <div className="flex items-center justify-between text-sm">
          <label className="flex items-center gap-2">
            <input type="checkbox" className="accent-primary size-4" {...register('remember')} /> Remember me
          </label>
          <Link to="/forgot-password" className="text-primary hover:underline">
            Forgot password?
          </Link>
        </div>
        <CaptchaWidget />
        <Button type="submit" loading={login.isPending} className="w-full">
          Log in
        </Button>
      </form>
    </AuthCard>
  )
}
