import { useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowDown, ArrowUp, Plus, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'
import { Alert } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { instructorAssessmentApi } from '@/features/learning/api'
import { ApiError } from '@/lib/api-client'
import type { QuestionType, StudioQuestion, StudioQuiz } from '@/types/learning'

const TYPE_LABEL: Record<QuestionType, string> = {
  mcq_single: 'Single choice',
  mcq_multi: 'Multiple choice',
  true_false: 'True / false',
  short_text: 'Short answer',
}

const blank = (type: QuestionType): StudioQuestion => ({
  type,
  prompt: '',
  points: 1,
  explanation: null,
  accepted_answers: type === 'short_text' ? [] : null,
  options:
    type === 'true_false'
      ? [
          { text: 'True', is_correct: true },
          { text: 'False', is_correct: false },
        ]
      : type === 'short_text'
        ? []
        : [
            { text: '', is_correct: true },
            { text: '', is_correct: false },
          ],
})

const DEFAULTS: Omit<StudioQuiz, 'id'> = {
  pass_mark: 70,
  time_limit_seconds: null,
  attempts_allowed: null,
  shuffle: false,
  show_answers: true,
  questions: [],
}

export function QuizBuilder({ lessonId, courseId }: { lessonId: string; courseId: string }) {
  const { data, isLoading } = useQuery({
    queryKey: ['studio', 'quiz', lessonId],
    queryFn: () => instructorAssessmentApi.quiz(lessonId),
  })
  if (isLoading) return <Skeleton className="h-40" />
  return (
    <QuizForm
      key={data?.id ?? 'new'}
      lessonId={lessonId}
      courseId={courseId}
      initial={data ?? { ...DEFAULTS, id: '' }}
    />
  )
}

function QuizForm({
  lessonId,
  courseId,
  initial,
}: {
  lessonId: string
  courseId: string
  initial: StudioQuiz
}) {
  const qc = useQueryClient()
  const [settings, setSettings] = useState({
    pass_mark: initial.pass_mark,
    time_limit_minutes: initial.time_limit_seconds ? Math.round(initial.time_limit_seconds / 60) : '',
    attempts_allowed: initial.attempts_allowed ?? '',
    shuffle: initial.shuffle,
    show_answers: initial.show_answers,
  })
  const [questions, setQuestions] = useState<StudioQuestion[]>(initial.questions)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [saving, setSaving] = useState(false)

  const update = (i: number, patch: Partial<StudioQuestion>) =>
    setQuestions((qs) => qs.map((q, j) => (j === i ? { ...q, ...patch } : q)))
  const move = (i: number, d: -1 | 1) =>
    setQuestions((qs) => {
      const next = [...qs]
      const [q] = next.splice(i, 1)
      next.splice(i + d, 0, q!)
      return next
    })

  const save = async () => {
    setSaving(true)
    setErrors({})
    try {
      const saved = await instructorAssessmentApi.saveQuiz(lessonId, {
        pass_mark: Number(settings.pass_mark),
        time_limit_seconds: settings.time_limit_minutes ? Number(settings.time_limit_minutes) * 60 : null,
        attempts_allowed: settings.attempts_allowed ? Number(settings.attempts_allowed) : null,
        shuffle: settings.shuffle,
        show_answers: settings.show_answers,
        questions: questions.map((q) => ({
          ...q,
          options: q.type === 'short_text' ? [] : q.options,
          accepted_answers: q.type === 'short_text' ? (q.accepted_answers ?? []).filter(Boolean) : null,
        })),
      })
      setQuestions(saved.questions)
      toast.success('Quiz saved')
      void qc.invalidateQueries({ queryKey: ['studio', 'course', courseId] })
    } catch (e) {
      if (e instanceof ApiError && e.isValidation) {
        setErrors(Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k, v[0] ?? ''])))
        toast.error('Please fix the highlighted questions')
      } else toast.error(e instanceof Error ? e.message : 'Could not save the quiz')
    } finally {
      setSaving(false)
    }
  }

  const qError = (i: number) =>
    Object.entries(errors)
      .filter(([k]) => k === `questions.${i}` || k.startsWith(`questions.${i}.`))
      .map(([, v]) => v)

  return (
    <div className="grid gap-4 rounded-lg border p-4">
      <h3 className="font-semibold">Quiz</h3>
      <div className="grid gap-3 sm:grid-cols-3">
        <NumberField
          id="qz-pass"
          label="Pass mark (%)"
          value={settings.pass_mark}
          min={0}
          max={100}
          onChange={(v) => setSettings((s) => ({ ...s, pass_mark: Number(v) }))}
          error={errors.pass_mark}
        />
        <NumberField
          id="qz-time"
          label="Time limit (minutes)"
          hint="Blank = untimed"
          value={settings.time_limit_minutes}
          min={1}
          max={240}
          onChange={(v) => setSettings((s) => ({ ...s, time_limit_minutes: v }))}
          error={errors.time_limit_seconds}
        />
        <NumberField
          id="qz-att"
          label="Attempts allowed"
          hint="Blank = unlimited"
          value={settings.attempts_allowed}
          min={1}
          max={100}
          onChange={(v) => setSettings((s) => ({ ...s, attempts_allowed: v }))}
          error={errors.attempts_allowed}
        />
      </div>
      <div className="flex flex-wrap gap-4 text-sm">
        <label className="flex items-center gap-2">
          <input
            type="checkbox"
            checked={settings.shuffle}
            onChange={(e) => setSettings((s) => ({ ...s, shuffle: e.target.checked }))}
          />
          Shuffle question order
        </label>
        <label className="flex items-center gap-2">
          <input
            type="checkbox"
            checked={settings.show_answers}
            onChange={(e) => setSettings((s) => ({ ...s, show_answers: e.target.checked }))}
          />
          Show correct answers & explanations after submitting
        </label>
      </div>
      {errors.questions && <Alert variant="destructive">{errors.questions}</Alert>}

      <ol className="grid gap-3">
        {questions.map((q, i) => (
          <li key={q.id ?? `new-${i}`} className="grid gap-3 rounded-md border p-3">
            <fieldset className="grid gap-3">
              <legend className="sr-only">Question {i + 1}</legend>
              <div className="flex flex-wrap items-center gap-2">
                <span className="text-sm font-semibold">Q{i + 1}</span>
                <span className="bg-secondary text-secondary-foreground rounded px-2 py-0.5 text-xs">
                  {TYPE_LABEL[q.type]}
                </span>
                <label htmlFor={`qpts-${i}`} className="ml-auto text-xs">
                  Points
                </label>
                <Input
                  id={`qpts-${i}`}
                  type="number"
                  min={1}
                  max={100}
                  value={q.points}
                  className="h-8 w-16"
                  onChange={(e) => update(i, { points: Number(e.target.value) || 1 })}
                />
                <Button
                  type="button"
                  size="icon"
                  variant="ghost"
                  className="size-8"
                  aria-label={`Move question ${i + 1} up`}
                  disabled={i === 0}
                  onClick={() => move(i, -1)}
                >
                  <ArrowUp aria-hidden />
                </Button>
                <Button
                  type="button"
                  size="icon"
                  variant="ghost"
                  className="size-8"
                  aria-label={`Move question ${i + 1} down`}
                  disabled={i === questions.length - 1}
                  onClick={() => move(i, 1)}
                >
                  <ArrowDown aria-hidden />
                </Button>
                <Button
                  type="button"
                  size="icon"
                  variant="ghost"
                  className="size-8"
                  aria-label={`Delete question ${i + 1}`}
                  title={q.has_answers ? 'Learners have answered this question — edit it instead' : undefined}
                  disabled={q.has_answers}
                  onClick={() => setQuestions((qs) => qs.filter((_, j) => j !== i))}
                >
                  <Trash2 aria-hidden />
                </Button>
              </div>
              <textarea
                aria-label={`Question ${i + 1} prompt`}
                rows={2}
                maxLength={2000}
                value={q.prompt}
                placeholder="Question"
                onChange={(e) => update(i, { prompt: e.target.value })}
                className="border-input bg-background focus-visible:ring-ring rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
              />

              {q.type === 'short_text' ? (
                <label className="grid gap-1 text-sm">
                  Accepted answers{' '}
                  <span className="text-muted-foreground text-xs">
                    Comma-separated; matching ignores case and extra spaces
                  </span>
                  <Input
                    value={(q.accepted_answers ?? []).join(', ')}
                    onChange={(e) =>
                      update(i, { accepted_answers: e.target.value.split(',').map((s) => s.trim()) })
                    }
                  />
                </label>
              ) : (
                <div className="grid gap-2">
                  <p className="text-muted-foreground text-xs">
                    {q.type === 'mcq_multi' ? 'Tick every correct option.' : 'Select the correct option.'}
                  </p>
                  {q.options.map((o, j) => (
                    <div key={j} className="flex items-center gap-2">
                      <input
                        type={q.type === 'mcq_multi' ? 'checkbox' : 'radio'}
                        name={`correct-${i}`}
                        aria-label={`Option ${j + 1} is correct`}
                        checked={o.is_correct}
                        onChange={() =>
                          update(i, {
                            options: q.options.map((x, k) =>
                              q.type === 'mcq_multi'
                                ? k === j
                                  ? { ...x, is_correct: !x.is_correct }
                                  : x
                                : { ...x, is_correct: k === j },
                            ),
                          })
                        }
                      />
                      <Input
                        aria-label={`Option ${j + 1}`}
                        value={o.text}
                        maxLength={500}
                        readOnly={q.type === 'true_false'}
                        onChange={(e) =>
                          update(i, {
                            options: q.options.map((x, k) => (k === j ? { ...x, text: e.target.value } : x)),
                          })
                        }
                      />
                      {q.type !== 'true_false' && q.options.length > 2 && (
                        <Button
                          type="button"
                          size="icon"
                          variant="ghost"
                          className="size-8"
                          aria-label={`Remove option ${j + 1}`}
                          onClick={() => update(i, { options: q.options.filter((_, k) => k !== j) })}
                        >
                          <Trash2 aria-hidden />
                        </Button>
                      )}
                    </div>
                  ))}
                  {q.type !== 'true_false' && q.options.length < 10 && (
                    <Button
                      type="button"
                      size="sm"
                      variant="ghost"
                      className="justify-self-start"
                      onClick={() => update(i, { options: [...q.options, { text: '', is_correct: false }] })}
                    >
                      <Plus aria-hidden /> Add option
                    </Button>
                  )}
                </div>
              )}
              <Input
                aria-label={`Question ${i + 1} explanation`}
                placeholder="Explanation shown after submitting (optional)"
                value={q.explanation ?? ''}
                maxLength={2000}
                onChange={(e) => update(i, { explanation: e.target.value || null })}
              />
              {qError(i).map((m) => (
                <p key={m} role="alert" className="text-destructive text-sm">
                  {m}
                </p>
              ))}
            </fieldset>
          </li>
        ))}
      </ol>

      <div className="flex flex-wrap items-center gap-2">
        <span className="text-muted-foreground text-sm">Add question:</span>
        {(Object.keys(TYPE_LABEL) as QuestionType[]).map((t) => (
          <Button
            key={t}
            type="button"
            size="sm"
            variant="outline"
            onClick={() => setQuestions((qs) => [...qs, blank(t)])}
          >
            <Plus aria-hidden /> {TYPE_LABEL[t]}
          </Button>
        ))}
      </div>
      <Button
        type="button"
        className="justify-self-start"
        loading={saving}
        disabled={questions.length === 0}
        onClick={() => void save()}
      >
        Save quiz
      </Button>
    </div>
  )
}

function NumberField({
  id,
  label,
  hint,
  value,
  min,
  max,
  onChange,
  error,
}: {
  id: string
  label: string
  hint?: string
  value: number | string
  min: number
  max: number
  onChange: (v: string) => void
  error?: string
}) {
  return (
    <div className="grid gap-1">
      <label htmlFor={id} className="text-sm font-medium">
        {label}
      </label>
      <Input
        id={id}
        type="number"
        min={min}
        max={max}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        aria-invalid={!!error}
      />
      {hint && !error && <p className="text-muted-foreground text-xs">{hint}</p>}
      {error && (
        <p role="alert" className="text-destructive text-xs">
          {error}
        </p>
      )}
    </div>
  )
}
