import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { qaApi } from '@/features/instructor/api'
import { ApiError } from '@/lib/api-client'
import { cn } from '@/lib/utils'
import { QuestionThread } from './QuestionThread'

/** The lesson player's Q&A tab: this lesson's questions (or the whole course's) + ask form. */
export function LessonQuestions({ lessonId }: { lessonId: string }) {
  const qc = useQueryClient()
  const [scope, setScope] = useState<'lesson' | 'course'>('lesson')
  const [asking, setAsking] = useState(false)
  const [title, setTitle] = useState('')
  const [body, setBody] = useState('')
  const [errors, setErrors] = useState<Record<string, string>>({})
  const { data, isLoading } = useQuery({
    queryKey: ['qa', 'lesson', lessonId, scope],
    queryFn: () => qaApi.list(lessonId, scope),
  })
  const refresh = () => void qc.invalidateQueries({ queryKey: ['qa', 'lesson', lessonId] })

  const submit = async () => {
    setErrors({})
    try {
      await qaApi.ask(lessonId, { title, body })
      toast.success('Question posted — the instructor will be notified')
      setTitle('')
      setBody('')
      setAsking(false)
      refresh()
    } catch (e) {
      if (e instanceof ApiError && e.isValidation)
        setErrors(Object.fromEntries(Object.entries(e.errors).map(([k, m]) => [k, m[0] ?? ''])))
      else toast.error(e instanceof Error ? e.message : 'Could not post')
    }
  }

  return (
    <div className="grid gap-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div
          role="radiogroup"
          aria-label="Show questions from"
          className="bg-muted inline-flex rounded-md p-1 text-sm"
        >
          {(['lesson', 'course'] as const).map((s) => (
            <button
              key={s}
              role="radio"
              aria-checked={scope === s}
              onClick={() => setScope(s)}
              className={cn(
                'rounded px-3 py-1',
                scope === s ? 'bg-background shadow-sm' : 'text-muted-foreground',
              )}
            >
              {s === 'lesson' ? 'This lesson' : 'Whole course'}
            </button>
          ))}
        </div>
        {!asking && (
          <Button size="sm" onClick={() => setAsking(true)}>
            Ask a question
          </Button>
        )}
      </div>
      {asking && (
        <form
          className="grid gap-2 rounded-lg border p-4"
          onSubmit={(e) => {
            e.preventDefault()
            void submit()
          }}
        >
          <label htmlFor="qa-title" className="text-sm font-medium">
            Question
          </label>
          <Input
            id="qa-title"
            maxLength={200}
            value={title}
            onChange={(e) => setTitle(e.target.value)}
            aria-invalid={!!errors.title}
          />
          {errors.title && (
            <p role="alert" className="text-destructive text-xs">
              {errors.title}
            </p>
          )}
          <label htmlFor="qa-body" className="text-sm font-medium">
            Details
          </label>
          <textarea
            id="qa-body"
            rows={4}
            maxLength={5000}
            value={body}
            onChange={(e) => setBody(e.target.value)}
            className="border-input bg-background rounded-md border px-3 py-2 text-sm"
            aria-invalid={!!errors.body}
          />
          {errors.body && (
            <p role="alert" className="text-destructive text-xs">
              {errors.body}
            </p>
          )}
          <div className="flex gap-2">
            <Button type="submit" size="sm">
              Post question
            </Button>
            <Button type="button" size="sm" variant="ghost" onClick={() => setAsking(false)}>
              Cancel
            </Button>
          </div>
        </form>
      )}
      {isLoading && <Skeleton className="h-24" />}
      {data?.length === 0 && (
        <p className="text-muted-foreground text-sm">No questions yet. Be the first to ask.</p>
      )}
      <ul className="grid gap-2">
        {data?.map((q) => (
          <li key={q.id}>
            <QuestionThread question={q} showCourse={scope === 'course'} onChanged={refresh} />
          </li>
        ))}
      </ul>
    </div>
  )
}
