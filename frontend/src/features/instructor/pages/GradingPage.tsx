import { useQuery, useQueryClient } from '@tanstack/react-query'
import { FileText } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'
import { PageHeader } from '@/components/PageHeader'
import { Seo } from '@/components/Seo'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { learnApi } from '@/features/learn/api'
import { instructorAssessmentApi } from '@/features/learning/api'
import { ApiError } from '@/lib/api-client'
import { formatBytes, formatDate } from '@/lib/format'
import { cn } from '@/lib/utils'
import type { GradingItem } from '@/types/learning'

const TABS = [
  ['submitted', 'To grade'],
  ['returned', 'Returned'],
  ['graded', 'Graded'],
] as const

export default function GradingPage() {
  const [status, setStatus] = useState<(typeof TABS)[number][0]>('submitted')
  const { data, isLoading } = useQuery({
    queryKey: ['instructor', 'submissions', status],
    queryFn: () => instructorAssessmentApi.submissions(status),
  })

  return (
    <>
      <Seo title="Grading" noindex />
      <PageHeader title="Assignment grading" description="Review learner submissions for your courses." />
      <div role="tablist" aria-label="Submission status" className="mb-4 flex gap-1 border-b">
        {TABS.map(([v, label]) => (
          <button
            key={v}
            role="tab"
            aria-selected={status === v}
            onClick={() => setStatus(v)}
            className={cn(
              '-mb-px border-b-2 px-3 py-2 text-sm font-medium',
              status === v ? 'border-primary text-foreground' : 'text-muted-foreground border-transparent',
            )}
          >
            {label}
            {status === v && data ? ` (${data.meta.total})` : ''}
          </button>
        ))}
      </div>
      {isLoading && <Skeleton className="h-40" />}
      {data?.data.length === 0 && (
        <Card className="border-dashed">
          <CardContent className="text-muted-foreground p-10 text-center">
            Nothing here right now.
          </CardContent>
        </Card>
      )}
      <div className="grid gap-4">
        {data?.data.map((s) => (
          <SubmissionCard key={`${s.id}-${s.revision}`} item={s} />
        ))}
      </div>
    </>
  )
}

function SubmissionCard({ item }: { item: GradingItem }) {
  const qc = useQueryClient()
  const [score, setScore] = useState(item.score?.toString() ?? '')
  const [feedback, setFeedback] = useState(item.feedback ?? '')
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState<'graded' | 'returned' | null>(null)

  const act = async (status: 'graded' | 'returned') => {
    setBusy(status)
    setError(null)
    try {
      await instructorAssessmentApi.grade(item.id, {
        status,
        score: score === '' ? null : Number(score),
        feedback,
      })
      toast.success(status === 'graded' ? 'Graded — the learner has been notified' : 'Returned for changes')
      void qc.invalidateQueries({ queryKey: ['instructor', 'submissions'] })
    } catch (e) {
      setError(
        e instanceof ApiError && e.isValidation
          ? Object.values(e.errors).flat().join(' ')
          : e instanceof Error
            ? e.message
            : 'Could not save',
      )
    } finally {
      setBusy(null)
    }
  }

  return (
    <Card>
      <CardHeader className="pb-3">
        <CardTitle className="text-base">
          {item.lesson.title} <span className="text-muted-foreground font-normal">· {item.course.title}</span>
        </CardTitle>
        <p className="text-muted-foreground text-sm">
          {item.learner.name} ({item.learner.email}) · submitted {formatDate(item.submitted_at)}
          {item.revision > 1 && ` · revision ${item.revision}`}
        </p>
      </CardHeader>
      <CardContent className="grid gap-3 text-sm">
        {item.text && <p className="bg-muted rounded-md p-3 whitespace-pre-wrap">{item.text}</p>}
        {item.files.length > 0 && (
          <ul className="grid gap-1">
            {item.files.map((f) => (
              <li key={f.index}>
                <button
                  type="button"
                  className="text-primary inline-flex items-center gap-2 hover:underline"
                  onClick={() =>
                    learnApi
                      .download(`/learn/submissions/${item.id}/files/${f.index}`, f.name)
                      .catch(() => toast.error('Download failed'))
                  }
                >
                  <FileText className="size-4" aria-hidden /> {f.name}
                </button>{' '}
                <span className="text-muted-foreground text-xs">{formatBytes(f.size)}</span>
              </li>
            ))}
          </ul>
        )}
        <div className="grid gap-3 sm:grid-cols-[8rem_1fr]">
          <div className="grid gap-1">
            <label htmlFor={`score-${item.id}`} className="font-medium">
              Score / {item.max_score}
            </label>
            <Input
              id={`score-${item.id}`}
              type="number"
              min={0}
              max={item.max_score}
              value={score}
              onChange={(e) => setScore(e.target.value)}
            />
          </div>
          <div className="grid gap-1">
            <label htmlFor={`fb-${item.id}`} className="font-medium">
              Feedback
            </label>
            <textarea
              id={`fb-${item.id}`}
              rows={3}
              maxLength={10000}
              value={feedback}
              onChange={(e) => setFeedback(e.target.value)}
              className="border-input bg-background focus-visible:ring-ring rounded-md border px-3 py-2 focus-visible:ring-2 focus-visible:outline-none"
            />
          </div>
        </div>
        {error && (
          <p role="alert" className="text-destructive">
            {error}
          </p>
        )}
        {item.status !== 'graded' && (
          <div className="flex flex-wrap gap-2">
            <Button
              size="sm"
              loading={busy === 'graded'}
              disabled={!!busy || score === ''}
              onClick={() => void act('graded')}
            >
              Grade
            </Button>
            <Button
              size="sm"
              variant="outline"
              loading={busy === 'returned'}
              disabled={!!busy || !feedback.trim()}
              onClick={() => void act('returned')}
            >
              Return for changes
            </Button>
          </div>
        )}
      </CardContent>
    </Card>
  )
}
