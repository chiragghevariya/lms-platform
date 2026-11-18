import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation } from '@tanstack/react-query'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { Link, useSearchParams } from 'react-router-dom'
import { Alert } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { FormField } from '@/components/ui/form-field'
import { Input } from '@/components/ui/input'
import { applyServerErrors } from '@/lib/form-errors'
import { authApi } from '../api'
import { AuthCard } from '../components/AuthCard'
import { type ResetInput, resetSchema } from '../schemas'

export default function ResetPasswordPage() {
  const [params] = useSearchParams()
  const token = params.get('token') ?? ''
  const email = params.get('email') ?? ''
  const mutation = useMutation({ mutationFn: authApi.resetPassword })
  const [formError, setFormError] = useState<string | null>(null)
  const { register, handleSubmit, setError, formState } = useForm<ResetInput>({
    resolver: zodResolver(resetSchema),
    defaultValues: { password: '', password_confirmation: '' },
  })

  if (!token || !email) {
    return (
      <AuthCard title="Invalid link">
        <Alert variant="destructive">This reset link is incomplete. Request a new one.</Alert>
        <Button asChild>
          <Link to="/forgot-password">Request new link</Link>
        </Button>
      </AuthCard>
    )
  }

  const onSubmit = handleSubmit(async (v) => {
    setFormError(null)
    try {
      await mutation.mutateAsync({ ...v, token, email })
    } catch (e) {
      setFormError(applyServerErrors(e, setError) ?? (e instanceof Error ? e.message : null))
    }
  })

  return (
    <AuthCard title="Choose a new password" description={email}>
      {mutation.isSuccess ? (
        <>
          <Alert variant="success">Your password has been reset.</Alert>
          <Button asChild>
            <Link to="/login">Log in</Link>
          </Button>
        </>
      ) : (
        <form onSubmit={onSubmit} className="grid gap-4" noValidate>
          {formError && <Alert variant="destructive">{formError}</Alert>}
          <FormField id="password" label="New password" error={formState.errors.password?.message}>
            {(a) => <Input type="password" autoComplete="new-password" {...a} {...register('password')} />}
          </FormField>
          <FormField
            id="password_confirmation"
            label="Confirm password"
            error={formState.errors.password_confirmation?.message}
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
          <Button type="submit" loading={mutation.isPending}>
            Reset password
          </Button>
        </form>
      )}
    </AuthCard>
  )
}
