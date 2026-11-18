import { useQuery, useQueryClient } from '@tanstack/react-query'
import { CheckCircle2, Clock, RotateCcw, XCircle } from 'lucide-react'
import { useCallback, useEffect, useRef, useState } from 'react'
import { toast } from 'sonner'
import { Alert } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { formatClock, formatDate } from '@/lib/format'
import { cn } from '@/lib/utils'
import type { AnswerMap, AttemptQuestion, QuizAttempt } from '@/types/learning'
import { learningApi } from '../api'

/** Quiz lesson: intro → timed attempt (autosaved, server-timed) → review with explanations. */
export function QuizPlayer({ lessonId, onPassed }: { lessonId: string; onPassed?: () => void }) {
  const qc = useQueryClient()
  const summary = useQuery({
    queryKey: ['learn', 'quiz', lessonId],
    queryFn: () => learningApi.quiz(lessonId),
  })
  const [attempt, setAttempt] = useState<QuizAttempt | null>(null)
  const [starting, setStarting] = useState(false)

  const refresh = useCallback(() => {
    void qc.invalidateQueries({ queryKey: ['learn', 'quiz', lessonId] })
  }, [qc, lessonId])

  if (summary.isLoading) return <Skeleton className="h-48" />
  if (summary.error || !summary.data)
    return <Alert variant="destructive">This quiz isn't available yet.</Alert>
  const q = summary.data
  const attemptsLeft = q.attempts_allowed === null ? null : Math.max(0, q.attempts_allowed - q.attempts_used)

  const start = async () => {
    setStarting(true)
    try {
      setAttempt(await learningApi.startQuiz(lessonId))
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'Could not start the quiz')
    } finally {
      setStarting(false)
    }
  }

  if (attempt?.status === 'in_progress') {
    return (
      <AttemptView
        key={attempt.id}
        attempt={attempt}
        onDone={(result) => {
          setAttempt(result)
          refresh()
          if (result.passed) onPassed?.()
        }}
      />
    )
  }
  if (attempt) {
    return (
      <ReviewView
        attempt={attempt}
        canRetry={!attempt.passed && attemptsLeft !== 0}
        onRetry={() => void start()}
        onBack={() => setAttempt(null)}
      />
    )
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>Quiz</CardTitle>
        <CardDescription>
          {q.question_count} question{q.question_count === 1 ? '' : 's'} · pass mark {q.pass_mark}%
          {q.time_limit_seconds ? ` · ${Math.round(q.time_limit_seconds / 60)} min time limit` : ' · untimed'}
          {attemptsLeft !== null && ` · ${attemptsLeft} attempt${attemptsLeft === 1 ? '' : 's'} left`}
        </CardDescription>
      </CardHeader>
      <CardContent className="grid gap-4">
        {q.passed && (
          <Alert className="border-accent/40">
            <CheckCircle2 className="text-accent inline size-4" aria-hidden /> Passed — best score{' '}
            {q.best_percent}%.
          </Alert>
        )}
        <div className="flex flex-wrap gap-2">
          {q.open_attempt_id ? (
            <Button onClick={() => void start()} loading={starting}>
              Resume attempt
            </Button>
          ) : (
            attemptsLeft !== 0 && (
              <Button
                onClick={() => void start()}
                loading={starting}
                variant={q.passed ? 'outline' : 'default'}
              >
                {q.attempts_used ? 'Retake quiz' : 'Start quiz'}
              </Button>
            )
          )}
          {attemptsLeft === 0 && !q.passed && (
            <p className="text-muted-foreground text-sm">
              You've used all attempts. Contact your instructor for help.
            </p>
          )}
        </div>
        {q.attempts.length > 0 && (
          <div>
            <h3 className="mb-2 text-sm font-semibold">Previous attempts</h3>
            <ul className="divide-y rounded-lg border text-sm">
              {q.attempts.map((a) => (
                <li key={a.id} className="flex items-center gap-3 p-3">
                  {a.passed ? (
                    <CheckCircle2 className="text-accent size-4" aria-hidden />
                  ) : (
                    <XCircle className="text-destructive size-4" aria-hidden />
                  )}
                  <span className="flex-1">
                    {a.percent ?? 0}% · {a.passed ? 'passed' : 'not passed'}
                    {a.status === 'expired' && ' (time ran out)'}
                  </span>
                  <span className="text-muted-foreground text-xs">{formatDate(a.submitted_at)}</span>
                  <Button
                    size="sm"
                    variant="ghost"
                    onClick={() =>
                      learningApi.attempt(a.id).then(setAttempt, () => toast.error('Could not load'))
                    }
                  >
                    Review
                  </Button>
                </li>
              ))}
            </ul>
          </div>
        )}
      </CardContent>
    </Card>
  )
}

function initialAnswers(attempt: QuizAttempt): AnswerMap {
  return Object.fromEntries(
    attempt.questions.map((q) => [
      q.id,
      q.type === 'short_text' ? { text: q.answer.text ?? '' } : { option_ids: q.answer.option_ids },
    ]),
  )
}

function AttemptView({ attempt, onDone }: { attempt: QuizAttempt; onDone: (a: QuizAttempt) => void }) {
  const [answers, setAnswers] = useState<AnswerMap>(() => initialAnswers(attempt))
  const [submitting, setSubmitting] = useState(false)
  const [confirming, setConfirming] = useState(false)
  const [deadline] = useState(() =>
    attempt.seconds_left !== null ? Date.now() + attempt.seconds_left * 1000 : null,
  )
  const [left, setLeft] = useState(attempt.seconds_left)
  const dirty = useRef(false)
  const answersRef = useRef(answers)
  useEffect(() => {
    answersRef.current = answers
  }, [answers])

  const submit = useCallback(async () => {
    setSubmitting(true)
    try {
      onDone(await learningApi.submitQuiz(attempt.id, answersRef.current))
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'Could not submit')
      setSubmitting(false)
    }
  }, [attempt.id, onDone])

  // Autosave (debounced) so a refresh or device switch resumes with answers intact.
  useEffect(() => {
    if (!dirty.current) return
    const t = window.setTimeout(() => {
      dirty.current = false
      learningApi.saveAnswers(attempt.id, answersRef.current).catch(() => (dirty.current = true))
    }, 1200)
    return () => window.clearTimeout(t)
  }, [answers, attempt.id])

  // Countdown; the server enforces the deadline too, so this is a convenience.
  useEffect(() => {
    if (deadline === null) return
    const tick = window.setInterval(() => {
      const s = Math.max(0, Math.round((deadline - Date.now()) / 1000))
      setLeft(s)
      if (s === 0) {
        window.clearInterval(tick)
        toast.info("Time's up — submitting your answers")
        void submit()
      }
    }, 1000)
    return () => window.clearInterval(tick)
  }, [deadline, submit])

  const set = (qid: string, value: AnswerMap[string]) => {
    dirty.current = true
    setAnswers((a) => ({ ...a, [qid]: value }))
  }
  const answered = attempt.questions.filter((q) =>
    q.type === 'short_text' ? !!answers[q.id]?.text?.trim() : !!answers[q.id]?.option_ids?.length,
  ).length

  return (
    <form
      className="grid gap-4"
      onSubmit={(e) => {
        e.preventDefault()
        if (answered < attempt.questions.length && !confirming) setConfirming(true)
        else void submit()
      }}
    >
      <div className="bg-background/95 sticky top-14 z-10 flex items-center justify-between gap-3 rounded-lg border p-3 backdrop-blur">
        <span className="text-sm">
          {answered} / {attempt.questions.length} answered
        </span>
        {left !== null && (
          <span
            role="timer"
            aria-live={left <= 60 ? 'assertive' : 'off'}
            aria-label={`Time left ${formatClock(left)}`}
            className={cn(
              'flex items-center gap-1 font-mono text-sm tabular-nums',
              left <= 60 && 'text-destructive font-semibold',
            )}
          >
            <Clock className="size-4" aria-hidden /> {formatClock(left)}
          </span>
        )}
      </div>

      {attempt.questions.map((q, i) => (
        <fieldset key={q.id} className="grid gap-3 rounded-lg border p-4">
          <legend className="px-1 text-sm font-semibold">
            Question {i + 1}{' '}
            <span className="text-muted-foreground font-normal">
              · {q.points} pt{q.points === 1 ? '' : 's'}
            </span>
          </legend>
          <p className="whitespace-pre-wrap">{q.prompt}</p>
          {q.type === 'short_text' ? (
            <input
              aria-label={`Answer to question ${i + 1}`}
              className="border-input bg-background focus-visible:ring-ring h-10 rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-none"
              value={answers[q.id]?.text ?? ''}
              maxLength={500}
              onChange={(e) => set(q.id, { text: e.target.value })}
            />
          ) : (
            <div className="grid gap-2">
              {q.type === 'mcq_multi' && (
                <p className="text-muted-foreground text-xs">Select all that apply.</p>
              )}
              {q.options.map((o) => {
                const multi = q.type === 'mcq_multi'
                const selected = answers[q.id]?.option_ids ?? []
                const checked = selected.includes(o.id)
                return (
                  <label
                    key={o.id}
                    className={cn(
                      'hover:bg-muted flex cursor-pointer items-center gap-3 rounded-md border p-3 text-sm',
                      checked && 'border-primary bg-primary/5',
                    )}
                  >
                    <input
                      type={multi ? 'checkbox' : 'radio'}
                      name={q.id}
                      checked={checked}
                      onChange={() =>
                        set(q.id, {
                          option_ids: multi
                            ? checked
                              ? selected.filter((x) => x !== o.id)
                              : [...selected, o.id]
                            : [o.id],
                        })
                      }
                    />
                    {o.text}
                  </label>
                )
              })}
            </div>
          )}
        </fieldset>
      ))}

      {confirming && (
        <Alert>You have {attempt.questions.length - answered} unanswered question(s). Submit anyway?</Alert>
      )}
      <div className="flex gap-2">
        <Button type="submit" loading={submitting}>
          {confirming ? 'Yes, submit' : 'Submit quiz'}
        </Button>
        {confirming && (
          <Button type="button" variant="outline" onClick={() => setConfirming(false)}>
            Keep answering
          </Button>
        )}
      </div>
    </form>
  )
}

function ReviewView({
  attempt,
  canRetry,
  onRetry,
  onBack,
}: {
  attempt: QuizAttempt
  canRetry: boolean
  onRetry: () => void
  onBack: () => void
}) {
  return (
    <div className="grid gap-4">
      <Card className={attempt.passed ? 'border-accent/50' : 'border-destructive/40'}>
        <CardContent className="flex flex-wrap items-center gap-4 p-6">
          {attempt.passed ? (
            <CheckCircle2 className="text-accent size-10" aria-hidden />
          ) : (
            <XCircle className="text-destructive size-10" aria-hidden />
          )}
          <div className="flex-1" role="status">
            <p className="text-2xl font-bold">{attempt.percent}%</p>
            <p className="text-muted-foreground text-sm">
              {attempt.score} / {attempt.max_score} points ·{' '}
              {attempt.passed ? 'Passed' : `Pass mark is ${attempt.pass_mark}%`}
              {attempt.status === 'expired' &&
                ' · time ran out, answers saved before the deadline were graded'}
            </p>
          </div>
          <div className="flex gap-2">
            <Button variant="outline" onClick={onBack}>
              Back
            </Button>
            {canRetry && (
              <Button onClick={onRetry}>
                <RotateCcw aria-hidden /> Try again
              </Button>
            )}
          </div>
        </CardContent>
      </Card>
      {attempt.questions.map((q, i) => (
        <ReviewQuestion key={q.id} q={q} n={i + 1} />
      ))}
    </div>
  )
}

function ReviewQuestion({ q, n }: { q: AttemptQuestion; n: number }) {
  const revealed = q.options.some((o) => o.is_correct !== undefined) || q.accepted_answers !== null
  return (
    <section aria-label={`Question ${n}`} className="grid gap-3 rounded-lg border p-4">
      <div className="flex items-start gap-2">
        {q.is_correct ? (
          <CheckCircle2 className="text-accent mt-0.5 size-5 shrink-0" aria-label="Correct" />
        ) : (
          <XCircle className="text-destructive mt-0.5 size-5 shrink-0" aria-label="Incorrect" />
        )}
        <p className="flex-1 whitespace-pre-wrap">
          <span className="font-semibold">Q{n}.</span> {q.prompt}
        </p>
        <span className="text-muted-foreground text-xs whitespace-nowrap">
          {q.points_awarded} / {q.points}
        </span>
      </div>
      {q.type === 'short_text' ? (
        <div className="grid gap-1 text-sm">
          <p>
            Your answer: <span className="font-medium">{q.answer.text || '—'}</span>
          </p>
          {q.accepted_answers && (
            <p className="text-muted-foreground">Accepted: {q.accepted_answers.join(', ')}</p>
          )}
        </div>
      ) : (
        <ul className="grid gap-1.5 text-sm">
          {q.options.map((o) => {
            const chosen = q.answer.option_ids.includes(o.id)
            return (
              <li
                key={o.id}
                className={cn(
                  'flex items-center gap-2 rounded-md border px-3 py-2',
                  o.is_correct && 'border-accent bg-accent/10',
                  chosen && o.is_correct === false && 'border-destructive bg-destructive/10',
                )}
              >
                <span className="flex-1">{o.text}</span>
                {chosen && <span className="text-xs font-medium">Your answer</span>}
                {o.is_correct && <span className="text-accent text-xs font-medium">Correct</span>}
              </li>
            )
          })}
        </ul>
      )}
      {q.explanation && (
        <p className="bg-muted rounded-md p-3 text-sm">
          <span className="font-semibold">Why: </span>
          {q.explanation}
        </p>
      )}
      {!revealed && (
        <p className="text-muted-foreground text-xs">Correct answers are hidden for this quiz.</p>
      )}
    </section>
  )
}
