import { zodResolver } from '@hookform/resolvers/zod'
import { CaptchaWidget } from '@/components/CaptchaWidget'
import { useMutation } from '@tanstack/react-query'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { Link } from 'react-router-dom'
import { Alert } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { FormField } from '@/components/ui/form-field'
import { Input } from '@/components/ui/input'
import { applyServerErrors } from '@/lib/form-errors'
import { authApi } from '../api'
import { AuthCard } from '../components/AuthCard'
import { type ForgotInput, forgotSchema } from '../schemas'

export default function ForgotPasswordPage() {
  const mutation = useMutation({ mutationFn: (v: ForgotInput) => authApi.forgotPassword(v.email) })
  const [formError, setFormError] = useState<string | null>(null)
  const { register, handleSubmit, setError, formState } = useForm<ForgotInput>({
    resolver: zodResolver(forgotSchema),
    defaultValues: { email: '' },
  })

  const onSubmit = handleSubmit(async (v) => {
    setFormError(null)
    try {
      await mutation.mutateAsync(v)
    } catch (e) {
      setFormError(applyServerErrors(e, setError))
    }
  })

  return (
    <AuthCard
      title="Reset your password"
      description="We'll email you a link to choose a new one."
      footer={
        <Link to="/login" className="text-primary hover:underline">
          Back to log in
        </Link>
      }
    >
      {mutation.isSuccess ? (
        <Alert variant="success">{mutation.data.message}</Alert>
      ) : (
        <form onSubmit={onSubmit} className="grid gap-4" noValidate>
          {formError && <Alert variant="destructive">{formError}</Alert>}
          <FormField id="email" label="Email" error={formState.errors.email?.message}>
            {(a) => <Input type="email" autoComplete="email" {...a} {...register('email')} />}
          </FormField>
          <CaptchaWidget />
          <Button type="submit" loading={mutation.isPending}>
            Send reset link
          </Button>
        </form>
      )}
    </AuthCard>
  )
}
