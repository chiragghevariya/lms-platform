import { useQuery, useQueryClient } from '@tanstack/react-query'
import { CalendarClock, FileText, Paperclip, X } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'
import { RichTextView } from '@/components/RichTextView'
import { Alert } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { learnApi } from '@/features/learn/api'
import { applyServerErrors } from '@/lib/form-errors'
import { formatBytes, formatDate } from '@/lib/format'
import type { Submission } from '@/types/learning'
import { learningApi } from '../api'

const STATUS: Record<Submission['status'], { label: string; className: string }> = {
  submitted: { label: 'Awaiting review', className: '' },
  graded: { label: 'Graded', className: 'bg-accent text-accent-foreground border-transparent' },
  returned: {
    label: 'Changes requested',
    className: 'border-destructive/50 text-destructive bg-destructive/10',
  },
}

export function AssignmentPanel({ lessonId, onGraded }: { lessonId: string; onGraded?: () => void }) {
  const qc = useQueryClient()
  const {
    data: a,
    isLoading,
    error,
  } = useQuery({
    queryKey: ['learn', 'assignment', lessonId],
    queryFn: () => learningApi.assignment(lessonId),
  })
  const [text, setText] = useState('')
  const [files, setFiles] = useState<File[]>([])
  const [errors, setErrors] = useState<string[]>([])
  const [sending, setSending] = useState(false)

  if (isLoading) return <Skeleton className="h-48" />
  if (error || !a) return <Alert variant="destructive">This assignment isn't available yet.</Alert>

  const sub = a.submission
  const overdue = a.due_at && new Date(a.due_at) < new Date() && !sub
  const canSubmit = sub?.status !== 'graded'
  const accept = a.file_types.map((t) => `.${t}`).join(',')

  const addFiles = (list: FileList | null) => {
    const picked = Array.from(list ?? [])
    const tooBig = picked.filter((f) => f.size > a.max_file_mb * 1024 * 1024)
    if (tooBig.length) toast.error(`${tooBig.map((f) => f.name).join(', ')} is over ${a.max_file_mb} MB`)
    setFiles((cur) => [...cur, ...picked.filter((f) => !tooBig.includes(f))].slice(0, 5))
  }

  const submit = async () => {
    setSending(true)
    setErrors([])
    try {
      await learningApi.submitAssignment(lessonId, { text, files })
      toast.success(sub ? 'Resubmitted' : 'Submitted for review')
      setText('')
      setFiles([])
      await qc.invalidateQueries({ queryKey: ['learn', 'assignment', lessonId] })
      void qc.invalidateQueries({ queryKey: ['me', 'dashboard'] })
    } catch (err) {
      const msgs: string[] = []
      applyServerErrors<Record<string, string>>(err, (_n, { message }) => message && msgs.push(message))
      setErrors(msgs.length ? msgs : [err instanceof Error ? err.message : 'Upload failed'])
    } finally {
      setSending(false)
    }
  }

  return (
    <div className="grid gap-4">
      <Card>
        <CardHeader className="flex-row flex-wrap items-center justify-between gap-2">
          <CardTitle>Assignment</CardTitle>
          <div className="text-muted-foreground flex flex-wrap items-center gap-3 text-sm">
            {a.due_at && (
              <span className={overdue ? 'text-destructive font-medium' : ''}>
                <CalendarClock className="mr-1 inline size-4" aria-hidden />
                {overdue ? 'Overdue — was due ' : 'Due '}
                {formatDate(a.due_at)}
              </span>
            )}
            <span>
              Out of {a.max_score}
              {a.pass_score !== null && ` · pass at ${a.pass_score}`}
            </span>
          </div>
        </CardHeader>
        <CardContent>
          {a.instructions ? (
            <RichTextView html={a.instructions} />
          ) : (
            <p className="text-muted-foreground text-sm">Follow the lesson brief above.</p>
          )}
        </CardContent>
      </Card>

      {sub && (
        <Card>
          <CardHeader className="flex-row flex-wrap items-center justify-between gap-2">
            <CardTitle className="text-base">Your submission</CardTitle>
            <div className="flex items-center gap-2">
              {sub.revision > 1 && (
                <span className="text-muted-foreground text-xs">Revision {sub.revision}</span>
              )}
              <Badge variant="secondary" className={STATUS[sub.status].className}>
                {STATUS[sub.status].label}
              </Badge>
            </div>
          </CardHeader>
          <CardContent className="grid gap-3 text-sm">
            <p className="text-muted-foreground">Submitted {formatDate(sub.submitted_at)}</p>
            {sub.text && <p className="bg-muted rounded-md p-3 whitespace-pre-wrap">{sub.text}</p>}
            {sub.files.length > 0 && (
              <ul className="grid gap-1">
                {sub.files.map((f) => (
                  <li key={f.index}>
                    <button
                      type="button"
                      className="text-primary inline-flex items-center gap-2 underline-offset-4 hover:underline"
                      onClick={() =>
                        learnApi
                          .download(`/learn/submissions/${sub.id}/files/${f.index}`, f.name)
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
            {(sub.score !== null || sub.feedback) && (
              <div className="rounded-md border p-3" role="status">
                {sub.score !== null && (
                  <p className="font-semibold">
                    Score: {sub.score} / {a.max_score}
                  </p>
                )}
                {sub.feedback && (
                  <p className="mt-1 whitespace-pre-wrap">
                    <span className="font-medium">Instructor feedback: </span>
                    {sub.feedback}
                  </p>
                )}
              </div>
            )}
            {sub.status === 'graded' && onGraded && (
              <Button size="sm" className="justify-self-start" onClick={onGraded}>
                Continue
              </Button>
            )}
          </CardContent>
        </Card>
      )}

      {canSubmit && (
        <Card>
          <CardHeader>
            <CardTitle className="text-base">
              {sub
                ? sub.status === 'returned'
                  ? 'Submit your revision'
                  : 'Update your submission'
                : 'Submit your work'}
            </CardTitle>
          </CardHeader>
          <CardContent className="grid gap-3">
            {a.allow_text && (
              <div className="grid gap-1.5">
                <label htmlFor="sub-text" className="text-sm font-medium">
                  Answer / notes{' '}
                  {a.file_types.length > 0 && (
                    <span className="text-muted-foreground font-normal">(optional)</span>
                  )}
                </label>
                <textarea
                  id="sub-text"
                  rows={5}
                  maxLength={20000}
                  value={text}
                  onChange={(e) => setText(e.target.value)}
                  className="border-input bg-background focus-visible:ring-ring rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                />
              </div>
            )}
            {a.file_types.length > 0 && (
              <div className="grid gap-2">
                <Button type="button" variant="outline" size="sm" className="justify-self-start" asChild>
                  <label className="focus-within:ring-ring cursor-pointer focus-within:ring-2">
                    <Paperclip aria-hidden /> Attach files
                    <input
                      type="file"
                      multiple
                      accept={accept}
                      className="sr-only"
                      onChange={(e) => {
                        addFiles(e.target.files)
                        e.target.value = ''
                      }}
                    />
                  </label>
                </Button>
                <p className="text-muted-foreground text-xs">
                  {a.file_types.join(', ').toUpperCase()} · up to {a.max_file_mb} MB each · max 5 files
                  {sub && sub.files.length > 0 && ' · new files replace your previous upload'}
                </p>
                {files.length > 0 && (
                  <ul className="grid gap-1 text-sm">
                    {files.map((f, i) => (
                      <li key={`${f.name}-${i}`} className="flex items-center gap-2">
                        <FileText className="text-muted-foreground size-4" aria-hidden />
                        <span className="flex-1 truncate">{f.name}</span>
                        <span className="text-muted-foreground text-xs">{formatBytes(f.size)}</span>
                        <Button
                          size="icon"
                          variant="ghost"
                          className="size-7"
                          aria-label={`Remove ${f.name}`}
                          onClick={() => setFiles((cur) => cur.filter((_, j) => j !== i))}
                        >
                          <X aria-hidden />
                        </Button>
                      </li>
                    ))}
                  </ul>
                )}
              </div>
            )}
            {errors.length > 0 && (
              <Alert variant="destructive">
                {errors.map((e) => (
                  <p key={e}>{e}</p>
                ))}
              </Alert>
            )}
            <Button
              className="justify-self-start"
              loading={sending}
              disabled={!text.trim() && files.length === 0}
              onClick={() => void submit()}
            >
              {sub ? 'Resubmit' : 'Submit'}
            </Button>
          </CardContent>
        </Card>
      )}
    </div>
  )
}
