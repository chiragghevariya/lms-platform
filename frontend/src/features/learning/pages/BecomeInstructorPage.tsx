import { useQuery, useQueryClient } from '@tanstack/react-query'
import { CheckCircle2, Clock, XCircle } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { toast } from 'sonner'
import { PageHeader } from '@/components/PageHeader'
import { Seo } from '@/components/Seo'
import { Alert } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { applicationApi } from '@/features/instructor/api'
import { ApiError } from '@/lib/api-client'
import { formatDate } from '@/lib/format'
import { useAuthStore } from '@/stores/auth-store'

const area = 'border-input bg-background rounded-md border px-3 py-2 text-sm'

export default function BecomeInstructorPage() {
  const { data, isLoading } = useQuery({
    queryKey: ['me', 'instructor-application'],
    queryFn: applicationApi.mine,
  })
  const [reapply, setReapply] = useState(false)
  const app = data?.application

  return (
    <>
      <Seo title="Become an instructor" noindex />
      <PageHeader
        title="Teach on the platform"
        description="Share what you know, earn from membership watch time and course sales."
      />
      {isLoading ? (
        <Skeleton className="h-64" />
      ) : data?.is_instructor ? (
        <Card>
          <CardContent className="grid justify-items-start gap-3 p-6">
            <p className="flex items-center gap-2 font-semibold">
              <CheckCircle2 className="text-accent size-5" aria-hidden /> You're an instructor.
            </p>
            <Button asChild>
              <Link to="/instructor">Open the instructor studio</Link>
            </Button>
          </CardContent>
        </Card>
      ) : app?.status === 'pending' ? (
        <Card>
          <CardContent className="grid gap-2 p-6">
            <p className="flex items-center gap-2 font-semibold">
              <Clock className="size-5" aria-hidden /> Application under review
            </p>
            <p className="text-muted-foreground text-sm">
              Submitted {formatDate(app.created_at)}. We'll email you when it's decided.
            </p>
          </CardContent>
        </Card>
      ) : app?.status === 'rejected' && !reapply ? (
        <Card>
          <CardContent className="grid justify-items-start gap-3 p-6">
            <p className="flex items-center gap-2 font-semibold">
              <XCircle className="text-destructive size-5" aria-hidden /> Not approved this time
            </p>
            {app.review_notes && <p className="bg-muted rounded-md p-3 text-sm">{app.review_notes}</p>}
            <Button variant="outline" onClick={() => setReapply(true)}>
              Apply again
            </Button>
          </CardContent>
        </Card>
      ) : (
        <ApplyForm previous={app ?? null} />
      )}
    </>
  )
}

function ApplyForm({
  previous,
}: {
  previous: { headline: string; expertise: string[]; bio: string; motivation: string; links: string[] } | null
}) {
  const qc = useQueryClient()
  const user = useAuthStore((s) => s.user)
  const [v, setV] = useState({
    headline: previous?.headline ?? '',
    expertise: previous?.expertise.join(', ') ?? '',
    bio: previous?.bio ?? user?.bio ?? '',
    motivation: previous?.motivation ?? '',
    links: previous?.links.join('\n') ?? '',
  })
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [sending, setSending] = useState(false)
  const err = (k: string) =>
    errors[k] && (
      <p role="alert" className="text-destructive text-xs">
        {errors[k]}
      </p>
    )

  return (
    <Card>
      <CardContent className="p-6">
        <form
          className="grid max-w-2xl gap-4"
          onSubmit={async (e) => {
            e.preventDefault()
            setSending(true)
            setErrors({})
            try {
              await applicationApi.apply({
                headline: v.headline,
                expertise: v.expertise
                  .split(',')
                  .map((s) => s.trim())
                  .filter(Boolean),
                bio: v.bio,
                motivation: v.motivation,
                links: v.links.split(/\s+/).filter(Boolean),
              })
              toast.success('Application sent')
              void qc.invalidateQueries({ queryKey: ['me', 'instructor-application'] })
            } catch (e2) {
              if (e2 instanceof ApiError && e2.isValidation)
                setErrors(
                  Object.fromEntries(
                    Object.entries(e2.errors).map(([k, m]) => [k.split('.')[0]!, m[0] ?? '']),
                  ),
                )
              else toast.error(e2 instanceof Error ? e2.message : 'Could not send')
            } finally {
              setSending(false)
            }
          }}
        >
          <div className="grid gap-1">
            <label htmlFor="ap-head" className="text-sm font-medium">
              Headline
            </label>
            <Input
              id="ap-head"
              maxLength={160}
              placeholder="e.g. Performance marketer teaching Meta Ads"
              value={v.headline}
              onChange={(e) => setV({ ...v, headline: e.target.value })}
            />
            {err('headline')}
          </div>
          <div className="grid gap-1">
            <label htmlFor="ap-exp" className="text-sm font-medium">
              Topics you'd teach <span className="text-muted-foreground font-normal">(comma-separated)</span>
            </label>
            <Input
              id="ap-exp"
              value={v.expertise}
              onChange={(e) => setV({ ...v, expertise: e.target.value })}
            />
            {err('expertise')}
          </div>
          <div className="grid gap-1">
            <label htmlFor="ap-bio" className="text-sm font-medium">
              About you <span className="text-muted-foreground font-normal">(at least 50 characters)</span>
            </label>
            <textarea
              id="ap-bio"
              rows={4}
              maxLength={3000}
              className={area}
              value={v.bio}
              onChange={(e) => setV({ ...v, bio: e.target.value })}
            />
            {err('bio')}
          </div>
          <div className="grid gap-1">
            <label htmlFor="ap-why" className="text-sm font-medium">
              What would your first course be, and who is it for?
            </label>
            <textarea
              id="ap-why"
              rows={4}
              maxLength={3000}
              className={area}
              value={v.motivation}
              onChange={(e) => setV({ ...v, motivation: e.target.value })}
            />
            {err('motivation')}
          </div>
          <div className="grid gap-1">
            <label htmlFor="ap-links" className="text-sm font-medium">
              Links to your work{' '}
              <span className="text-muted-foreground font-normal">(https, one per line, optional)</span>
            </label>
            <textarea
              id="ap-links"
              rows={2}
              className={area}
              value={v.links}
              onChange={(e) => setV({ ...v, links: e.target.value })}
            />
            {err('links')}
          </div>
          {errors.application && <Alert variant="destructive">{errors.application}</Alert>}
          <Button type="submit" loading={sending} className="justify-self-start">
            Send application
          </Button>
        </form>
      </CardContent>
    </Card>
  )
}
