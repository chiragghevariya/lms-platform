import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { HandCoins } from 'lucide-react'
import { Link } from 'react-router-dom'

import { useForm } from 'react-hook-form'
import { toast } from 'sonner'
import { z } from 'zod'
import { PageHeader } from '@/components/PageHeader'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { FormField } from '@/components/ui/form-field'
import { Input } from '@/components/ui/input'
import { meQueryKey } from '@/features/auth/hooks'
import { applyServerErrors } from '@/lib/form-errors'
import { useAuthStore } from '@/stores/auth-store'
import type { User } from '@/types/api'
import { profileApi } from '../api'

const profileSchema = z.object({
  name: z.string().trim().min(2).max(120),
  phone: z
    .string()
    .trim()
    .regex(/^\+?[0-9\s-]{7,20}$/, 'Enter a valid phone number')
    .or(z.literal('')),
  country: z
    .string()
    .trim()
    .regex(/^[A-Za-z]{2}$/, 'Use a 2-letter country code, e.g. IN')
    .or(z.literal('')),
  timezone: z.string().min(1),
  headline: z.string().trim().max(160),
  bio: z.string().trim().max(2000),
})
type ProfileInput = z.infer<typeof profileSchema>

const passwordSchema = z
  .object({
    current_password: z.string().min(1, 'Required'),
    password: z
      .string()
      .min(8, 'At least 8 characters')
      .regex(/[A-Za-z]/)
      .regex(/[0-9]/, 'Include a number'),
    password_confirmation: z.string(),
  })
  .refine((d) => d.password === d.password_confirmation, {
    path: ['password_confirmation'],
    message: 'Passwords do not match',
  })
type PasswordInput = z.infer<typeof passwordSchema>

function useSyncUser() {
  const qc = useQueryClient()
  const setUser = useAuthStore((s) => s.setUser)
  return (u: User) => {
    qc.setQueryData(meQueryKey, u)
    setUser(u)
  }
}

export default function ProfilePage() {
  const user = useAuthStore((s) => s.user)!
  return (
    <>
      <PageHeader title="Profile" description="Manage your account details." />
      <div className="grid gap-6 lg:grid-cols-2">
        <AvatarCard user={user} />
        <ProfileForm user={user} />
        <PasswordForm />
        <AffiliateCard user={user} />
      </div>
    </>
  )
}

function AvatarCard({ user }: { user: User }) {
  const sync = useSyncUser()
  const upload = useMutation({ mutationFn: profileApi.uploadAvatar, onSuccess: sync })
  const remove = useMutation({ mutationFn: profileApi.removeAvatar, onSuccess: sync })

  return (
    <Card className="lg:col-span-2">
      <CardContent className="flex flex-col items-center gap-4 p-6 sm:flex-row">
        <Avatar className="size-20 text-xl">
          {user.avatar_url && <AvatarImage src={user.avatar_url} alt="" />}
          <AvatarFallback>{user.name.slice(0, 2).toUpperCase()}</AvatarFallback>
        </Avatar>
        <div className="grid gap-2 text-center sm:text-left">
          <p className="font-semibold">Profile photo</p>
          <p className="text-muted-foreground text-sm">JPG, PNG or WebP, up to 2 MB.</p>
          <div className="flex flex-wrap justify-center gap-2 sm:justify-start">
            <Button asChild variant="outline" size="sm">
              <label
                className="focus-within:ring-ring cursor-pointer focus-within:ring-2"
                aria-busy={upload.isPending}
              >
                {upload.isPending ? 'Uploading…' : 'Upload photo'}
                <input
                  type="file"
                  accept="image/jpeg,image/png,image/webp"
                  className="sr-only"
                  onChange={(e) => {
                    const file = e.target.files?.[0]
                    e.target.value = ''
                    if (!file) return
                    if (file.size > 2 * 1024 * 1024) return void toast.error('Image must be 2 MB or smaller')
                    upload.mutate(file, {
                      onSuccess: () => toast.success('Photo updated'),
                      onError: (err) => toast.error(err.message),
                    })
                  }}
                />
              </label>
            </Button>
            {user.avatar_url && (
              <Button variant="ghost" size="sm" loading={remove.isPending} onClick={() => remove.mutate()}>
                Remove
              </Button>
            )}
          </div>
        </div>
      </CardContent>
    </Card>
  )
}

function ProfileForm({ user }: { user: User }) {
  const sync = useSyncUser()
  const mutation = useMutation({ mutationFn: profileApi.update, onSuccess: sync })
  const { register, handleSubmit, setError, formState } = useForm<ProfileInput>({
    resolver: zodResolver(profileSchema),
    defaultValues: {
      name: user.name,
      phone: user.phone ?? '',
      country: user.country ?? '',
      timezone: user.timezone,
      headline: user.profile.headline ?? '',
      bio: user.bio ?? '',
    },
  })
  const onSubmit = handleSubmit(async (v) => {
    try {
      await mutation.mutateAsync({
        name: v.name,
        phone: v.phone || null,
        country: v.country ? v.country.toUpperCase() : null,
        timezone: v.timezone,
        bio: v.bio || null,
        profile: { headline: v.headline || null },
      })
      toast.success('Profile saved')
    } catch (e) {
      const msg = applyServerErrors(e, setError)
      if (msg) toast.error(msg)
    }
  })
  return (
    <Card>
      <CardHeader>
        <CardTitle>Personal details</CardTitle>
        <CardDescription>{user.email}</CardDescription>
      </CardHeader>
      <CardContent>
        <form onSubmit={onSubmit} className="grid gap-4" noValidate>
          <FormField id="p-name" label="Name" error={formState.errors.name?.message}>
            {(a) => <Input {...a} {...register('name')} />}
          </FormField>
          <FormField id="p-phone" label="Phone" error={formState.errors.phone?.message}>
            {(a) => <Input type="tel" {...a} {...register('phone')} />}
          </FormField>
          <FormField
            id="p-tz"
            label="Timezone"
            hint="e.g. Asia/Kolkata"
            error={formState.errors.timezone?.message}
          >
            {(a) => <Input {...a} {...register('timezone')} />}
          </FormField>
          <Button type="submit" loading={mutation.isPending} className="justify-self-start">
            Save changes
          </Button>
        </form>
      </CardContent>
    </Card>
  )
}

function PasswordForm() {
  const mutation = useMutation({ mutationFn: profileApi.updatePassword })
  const { register, handleSubmit, setError, reset, formState } = useForm<PasswordInput>({
    resolver: zodResolver(passwordSchema),
    defaultValues: { current_password: '', password: '', password_confirmation: '' },
  })
  const onSubmit = handleSubmit(async (v) => {
    try {
      await mutation.mutateAsync(v)
      reset()
      toast.success('Password updated')
    } catch (e) {
      const msg = applyServerErrors(e, setError)
      if (msg) toast.error(msg)
    }
  })
  return (
    <Card>
      <CardHeader>
        <CardTitle>Password</CardTitle>
        <CardDescription>Changing it signs out your other devices.</CardDescription>
      </CardHeader>
      <CardContent>
        <form onSubmit={onSubmit} className="grid gap-4" noValidate>
          <FormField
            id="pw-current"
            label="Current password"
            error={formState.errors.current_password?.message}
          >
            {(a) => (
              <Input
                type="password"
                autoComplete="current-password"
                {...a}
                {...register('current_password')}
              />
            )}
          </FormField>
          <FormField id="pw-new" label="New password" error={formState.errors.password?.message}>
            {(a) => <Input type="password" autoComplete="new-password" {...a} {...register('password')} />}
          </FormField>
          <FormField
            id="pw-confirm"
            label="Confirm new password"
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
          <Button type="submit" loading={mutation.isPending} className="justify-self-start">
            Update password
          </Button>
        </form>
      </CardContent>
    </Card>
  )
}

function AffiliateCard({ user }: { user: User }) {
  return (
    <Card className="lg:col-span-2">
      <CardHeader>
        <CardTitle className="flex items-center gap-2">
          <HandCoins className="text-accent size-5" aria-hidden /> Referrals
        </CardTitle>
        <CardDescription>
          Invite friends for free membership days, or join the affiliate programme to earn commission.
        </CardDescription>
      </CardHeader>
      <CardContent className="flex flex-wrap gap-2">
        <Button variant="outline" asChild>
          <Link to="/app/invite">Invite friends</Link>
        </Button>
        <Button variant={user.affiliate.active ? 'default' : 'accent'} asChild>
          <Link to="/app/affiliate">
            {user.affiliate.active ? 'Affiliate dashboard' : 'Become an affiliate'}
          </Link>
        </Button>
      </CardContent>
    </Card>
  )
}
