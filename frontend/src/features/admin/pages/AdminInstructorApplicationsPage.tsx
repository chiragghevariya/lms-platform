import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { toast } from 'sonner'
import { PageHeader } from '@/components/PageHeader'
import { Seo } from '@/components/Seo'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { adminInstructorApi } from '@/features/instructor/api'
import { formatDate } from '@/lib/format'
import { cn } from '@/lib/utils'
import type { InstructorApplication } from '@/types/instructor'

export default function AdminInstructorApplicationsPage() {
  const [status, setStatus] = useState<'pending' | 'approved' | 'rejected'>('pending')
  const { data } = useQuery({
    queryKey: ['admin', 'applications', status],
    queryFn: () => adminInstructorApi.applications(status),
  })

  return (
    <>
      <Seo title="Instructor applications" noindex />
      <PageHeader
        title="Instructor applications"
        description="Approving grants the instructor role and copies the headline and expertise to their profile."
      />
      <div role="tablist" aria-label="Status" className="mb-4 flex gap-1 border-b">
        {(['pending', 'approved', 'rejected'] as const).map((s) => (
          <button
            key={s}
            role="tab"
            aria-selected={status === s}
            onClick={() => setStatus(s)}
            className={cn(
              '-mb-px border-b-2 px-3 py-2 text-sm font-medium capitalize',
              status === s ? 'border-primary text-foreground' : 'text-muted-foreground border-transparent',
            )}
          >
            {s}
          </button>
        ))}
      </div>
      {data?.data.length === 0 && <p className="text-muted-foreground text-sm">Nothing here.</p>}
      <ul className="grid gap-4">
        {data?.data.map((a) => (
          <li key={a.id}>
            <ApplicationCard app={a} />
          </li>
        ))}
      </ul>
    </>
  )
}

function ApplicationCard({ app: a }: { app: InstructorApplication }) {
  const qc = useQueryClient()
  const [notes, setNotes] = useState('')
  const [busy, setBusy] = useState<'approve' | 'reject' | null>(null)
  const decide = async (kind: 'approve' | 'reject') => {
    setBusy(kind)
    try {
      if (kind === 'approve') await adminInstructorApi.approve(a.id, notes || undefined)
      else await adminInstructorApi.reject(a.id, notes)
      toast.success(kind === 'approve' ? `${a.applicant?.name} is now an instructor` : 'Application rejected')
      void qc.invalidateQueries({ queryKey: ['admin', 'applications'] })
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'Could not save')
    } finally {
      setBusy(null)
    }
  }
  return (
    <Card>
      <CardContent className="grid gap-3 p-5">
        <div className="flex flex-wrap items-center gap-2">
          <p className="font-semibold">{a.applicant?.name}</p>
          <span className="text-muted-foreground text-sm">{a.applicant?.email}</span>
          <span className="text-muted-foreground ml-auto text-xs">Applied {formatDate(a.created_at)}</span>
        </div>
        <p className="font-medium">{a.headline}</p>
        <div className="flex flex-wrap gap-1">
          {a.expertise.map((e) => (
            <Badge key={e} variant="secondary">
              {e}
            </Badge>
          ))}
        </div>
        <dl className="grid gap-2 text-sm">
          <dt className="text-muted-foreground text-xs font-medium uppercase">About</dt>
          <dd className="whitespace-pre-wrap">{a.bio}</dd>
          <dt className="text-muted-foreground text-xs font-medium uppercase">First course idea</dt>
          <dd className="whitespace-pre-wrap">{a.motivation}</dd>
          {a.links.length > 0 && (
            <>
              <dt className="text-muted-foreground text-xs font-medium uppercase">Links</dt>
              <dd className="grid">
                {a.links.map((l) => (
                  <a
                    key={l}
                    href={l}
                    target="_blank"
                    rel="noopener noreferrer nofollow"
                    className="text-primary truncate hover:underline"
                  >
                    {l}
                  </a>
                ))}
              </dd>
            </>
          )}
        </dl>
        {a.status === 'pending' ? (
          <div className="grid gap-2">
            <label htmlFor={`notes-${a.id}`} className="text-sm font-medium">
              Notes to the applicant{' '}
              <span className="text-muted-foreground font-normal">(required to reject)</span>
            </label>
            <Input
              id={`notes-${a.id}`}
              maxLength={2000}
              value={notes}
              onChange={(e) => setNotes(e.target.value)}
            />
            <div className="flex gap-2">
              <Button
                size="sm"
                loading={busy === 'approve'}
                disabled={!!busy}
                onClick={() => void decide('approve')}
              >
                Approve
              </Button>
              <Button
                size="sm"
                variant="outline"
                loading={busy === 'reject'}
                disabled={!!busy || notes.trim().length < 10}
                onClick={() => void decide('reject')}
              >
                Reject
              </Button>
            </div>
          </div>
        ) : (
          a.review_notes && <p className="bg-muted rounded-md p-2 text-sm">Notes: {a.review_notes}</p>
        )}
      </CardContent>
    </Card>
  )
}
