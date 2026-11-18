import { zodResolver } from '@hookform/resolvers/zod'
import { CaptchaWidget } from '@/components/CaptchaWidget'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { Link, useNavigate } from 'react-router-dom'
import { Alert } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { FormField } from '@/components/ui/form-field'
import { Input } from '@/components/ui/input'
import { applyServerErrors } from '@/lib/form-errors'
import { AuthCard } from '../components/AuthCard'
import { GoogleButton, OrDivider } from '../components/GoogleButton'
import { Seo } from '@/components/Seo'
import { useRegister } from '../hooks'
import { type RegisterInput, registerSchema } from '../schemas'
import { track } from '@/features/cms/tracking'

export default function RegisterPage() {
  const registerUser = useRegister()
  const navigate = useNavigate()
  const [formError, setFormError] = useState<string | null>(null)
  const { register, handleSubmit, setError, formState } = useForm<RegisterInput>({
    resolver: zodResolver(registerSchema),
    defaultValues: { name: '', email: '', phone: '', password: '', password_confirmation: '' },
  })

  const onSubmit = handleSubmit(async (values) => {
    setFormError(null)
    try {
      await registerUser.mutateAsync(values)
      track('sign_up', { method: 'email' })
      navigate('/verify-email/pending', { replace: true })
    } catch (e) {
      setFormError(
        applyServerErrors(e, setError, ['name', 'email', 'phone', 'password', 'password_confirmation']),
      )
    }
  })

  const { errors } = formState
  return (
    <AuthCard
      title="Create your account"
      description="Start learning skills that build income."
      footer={
        <>
          Already have an account?{' '}
          <Link to="/login" className="text-primary font-medium hover:underline">
            Log in
          </Link>
        </>
      }
    >
      <Seo title="Create your account" path="/register" />
      <GoogleButton label="Sign up with Google" />
      <OrDivider />
      <form onSubmit={onSubmit} className="grid gap-4" noValidate>
        {formError && <Alert variant="destructive">{formError}</Alert>}
        <FormField id="name" label="Full name" error={errors.name?.message}>
          {(a) => <Input autoComplete="name" {...a} {...register('name')} />}
        </FormField>
        <FormField id="email" label="Email" error={errors.email?.message}>
          {(a) => <Input type="email" autoComplete="email" {...a} {...register('email')} />}
        </FormField>
        <FormField
          id="phone"
          label="Phone (optional)"
          hint="Used for WhatsApp class reminders."
          error={errors.phone?.message}
        >
          {(a) => <Input type="tel" autoComplete="tel" {...a} {...register('phone')} />}
        </FormField>
        <FormField
          id="password"
          label="Password"
          hint="8+ characters with letters and numbers."
          error={errors.password?.message}
        >
          {(a) => <Input type="password" autoComplete="new-password" {...a} {...register('password')} />}
        </FormField>
        <FormField
          id="password_confirmation"
          label="Confirm password"
          error={errors.password_confirmation?.message}
        >
          {(a) => (
            <Input
              type="password"
              autoComplete="new-password"
              {...a}
              {...register('password_confirmation')}
            />
          )}
        </FormField>
        <CaptchaWidget />
        <Button type="submit" loading={registerUser.isPending} className="w-full">
          Create account
        </Button>
      </form>
    </AuthCard>
  )
}
