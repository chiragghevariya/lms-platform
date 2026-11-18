import { useQuery, useQueryClient } from '@tanstack/react-query'
import { ShieldCheck } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'
import { PageHeader } from '@/components/PageHeader'
import { Seo } from '@/components/Seo'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { ApiError } from '@/lib/api-client'
import type { InstructorProfileData } from '@/types/instructor'
import { teachingApi } from '../api'

const SOCIALS = ['linkedin', 'youtube', 'instagram', 'x'] as const
const input = 'border-input bg-background rounded-md border px-3 py-2 text-sm'

export default function TeachingProfilePage() {
  const { data } = useQuery({ queryKey: ['instructor', 'profile'], queryFn: teachingApi.profile })
  return (
    <>
      <Seo title="Teaching profile" noindex />
      <PageHeader
        title="Teaching profile"
        description="What learners see about you, and where we send your earnings."
      />
      {data ? (
        <div className="grid gap-6 lg:grid-cols-2">
          <PublicCard key={`p-${data.headline}`} initial={data} />
          <PayoutCard key={`pay-${data.payout.summary}`} initial={data} />
        </div>
      ) : (
        <Skeleton className="h-80" />
      )}
    </>
  )
}

function useSave() {
  const qc = useQueryClient()
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [saving, setSaving] = useState(false)
  const save = async (body: Record<string, unknown>, ok: string) => {
    setSaving(true)
    setErrors({})
    try {
      qc.setQueryData(['instructor', 'profile'], await teachingApi.updateProfile(body))
      toast.success(ok)
      return true
    } catch (e) {
      if (e instanceof ApiError && e.isValidation)
        setErrors(Object.fromEntries(Object.entries(e.errors).map(([k, m]) => [k, m[0] ?? ''])))
      else toast.error(e instanceof Error ? e.message : 'Could not save')
      return false
    } finally {
      setSaving(false)
    }
  }
  return { save, saving, errors }
}

function PublicCard({ initial }: { initial: InstructorProfileData }) {
  const [v, setV] = useState({
    headline: initial.headline ?? '',
    bio: initial.bio ?? '',
    expertise: initial.expertise.join(', '),
    website: initial.website ?? '',
    social: Object.fromEntries(SOCIALS.map((s) => [s, initial.social_links[s] ?? ''])) as Record<
      string,
      string
    >,
  })
  const { save, saving, errors } = useSave()
  const err = (k: string) =>
    errors[k] && (
      <p role="alert" className="text-destructive text-xs">
        {errors[k]}
      </p>
    )

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">Public profile</CardTitle>
        <CardDescription>Shown on your course pages.</CardDescription>
      </CardHeader>
      <CardContent>
        <form
          className="grid gap-3"
          onSubmit={(e) => {
            e.preventDefault()
            void save(
              {
                headline: v.headline || null,
                bio: v.bio || null,
                website: v.website || null,
                expertise: v.expertise
                  .split(',')
                  .map((s) => s.trim())
                  .filter(Boolean),
                social_links: Object.fromEntries(Object.entries(v.social).filter(([, url]) => url.trim())),
              },
              'Profile saved',
            )
          }}
        >
          <label htmlFor="tp-head" className="text-sm font-medium">
            Headline
          </label>
          <Input
            id="tp-head"
            maxLength={160}
            value={v.headline}
            onChange={(e) => setV({ ...v, headline: e.target.value })}
          />
          <label htmlFor="tp-bio" className="text-sm font-medium">
            Bio
          </label>
          <textarea
            id="tp-bio"
            rows={5}
            maxLength={3000}
            className={input}
            value={v.bio}
            onChange={(e) => setV({ ...v, bio: e.target.value })}
          />
          <label htmlFor="tp-exp" className="text-sm font-medium">
            Expertise <span className="text-muted-foreground font-normal">(comma-separated)</span>
          </label>
          <Input
            id="tp-exp"
            value={v.expertise}
            onChange={(e) => setV({ ...v, expertise: e.target.value })}
          />
          <label htmlFor="tp-web" className="text-sm font-medium">
            Website
          </label>
          <Input
            id="tp-web"
            type="url"
            placeholder="https://"
            value={v.website}
            onChange={(e) => setV({ ...v, website: e.target.value })}
          />
          {err('website')}
          <fieldset className="grid gap-2">
            <legend className="mb-1 text-sm font-medium">Social links</legend>
            {SOCIALS.map((s) => (
              <div key={s} className="grid grid-cols-[6rem_1fr] items-center gap-2">
                <label htmlFor={`tp-${s}`} className="text-sm capitalize">
                  {s === 'x' ? 'X / Twitter' : s}
                </label>
                <Input
                  id={`tp-${s}`}
                  type="url"
                  placeholder="https://"
                  value={v.social[s]}
                  onChange={(e) => setV({ ...v, social: { ...v.social, [s]: e.target.value } })}
                />
              </div>
            ))}
            {Object.keys(errors).some((k) => k.startsWith('social_links')) && (
              <p role="alert" className="text-destructive text-xs">
                Social links must be https:// URLs.
              </p>
            )}
          </fieldset>
          <Button type="submit" loading={saving} className="justify-self-start">
            Save profile
          </Button>
        </form>
      </CardContent>
    </Card>
  )
}

function PayoutCard({ initial }: { initial: InstructorProfileData }) {
  const [method, setMethod] = useState(initial.payout.method ?? 'bank')
  const [d, setD] = useState<Record<string, string>>({})
  const [tax, setTax] = useState('')
  const { save, saving, errors } = useSave()
  const field = (k: string, label: string, props: React.InputHTMLAttributes<HTMLInputElement> = {}) => (
    <div className="grid gap-1">
      <label htmlFor={`po-${k}`} className="text-sm font-medium">
        {label}
      </label>
      <Input
        id={`po-${k}`}
        autoComplete="off"
        value={d[k] ?? ''}
        onChange={(e) => setD({ ...d, [k]: e.target.value })}
        aria-invalid={!!errors[`payout_details.${k}`]}
        {...props}
      />
      {errors[`payout_details.${k}`] && (
        <p role="alert" className="text-destructive text-xs">
          {errors[`payout_details.${k}`]}
        </p>
      )}
    </div>
  )

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-base">
          <ShieldCheck className="size-4" aria-hidden /> Payout details
        </CardTitle>
        <CardDescription>
          Encrypted and only visible to our finance team. Revenue share on course sales:{' '}
          {initial.revenue_share_percent}%.
        </CardDescription>
      </CardHeader>
      <CardContent className="grid gap-4">
        {initial.payout.summary ? (
          <p className="bg-muted rounded-md p-3 text-sm">
            On file: <span className="font-medium">{initial.payout.summary}</span>
            {initial.payout.tax_id && (
              <span className="text-muted-foreground block text-xs">Tax ID {initial.payout.tax_id}</span>
            )}
          </p>
        ) : (
          <p className="text-muted-foreground text-sm">Add payout details so we can pay your statements.</p>
        )}
        <form
          className="grid gap-3"
          onSubmit={(e) => {
            e.preventDefault()
            void save(
              { payout_method: method, payout_details: d, ...(tax ? { tax_id: tax } : {}) },
              'Payout details saved',
            ).then((ok) => {
              if (ok) {
                setD({})
                setTax('')
              }
            })
          }}
        >
          <fieldset className="flex flex-wrap gap-4 text-sm">
            <legend className="mb-1 text-sm font-medium">Method</legend>
            {(['bank', 'upi', 'paypal'] as const).map((m) => (
              <label key={m} className="flex items-center gap-2">
                <input type="radio" name="method" checked={method === m} onChange={() => setMethod(m)} />
                {m === 'bank' ? 'Bank transfer (India)' : m === 'upi' ? 'UPI' : 'PayPal'}
              </label>
            ))}
          </fieldset>
          {method === 'bank' && (
            <>
              {field('account_name', 'Account holder name')}
              {field('account_number', 'Account number', { inputMode: 'numeric' })}
              {field('ifsc', 'IFSC', { placeholder: 'HDFC0001234' })}
            </>
          )}
          {method === 'upi' && field('upi_id', 'UPI ID', { placeholder: 'name@bank' })}
          {method === 'paypal' && field('paypal_email', 'PayPal email', { type: 'email' })}
          <div className="grid gap-1">
            <label htmlFor="po-tax" className="text-sm font-medium">
              PAN / tax ID <span className="text-muted-foreground font-normal">(optional)</span>
            </label>
            <Input
              id="po-tax"
              autoComplete="off"
              maxLength={20}
              value={tax}
              onChange={(e) => setTax(e.target.value)}
            />
          </div>
          <Button type="submit" loading={saving} className="justify-self-start">
            Save payout details
          </Button>
        </form>
      </CardContent>
    </Card>
  )
}
