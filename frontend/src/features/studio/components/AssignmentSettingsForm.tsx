import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { toast } from 'sonner'
import { RichTextEditor } from '@/components/RichTextEditor'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { instructorAssessmentApi } from '@/features/learning/api'
import { ApiError } from '@/lib/api-client'
import type { AssignmentSettings } from '@/types/learning'

const FILE_TYPES = [
  'pdf',
  'docx',
  'zip',
  'png',
  'jpg',
  'txt',
  'csv',
  'xlsx',
  'pptx',
  'psd',
  'fig',
  'mp4',
  'mp3',
]
const DEFAULTS: AssignmentSettings = {
  instructions: null,
  due_days: 7,
  max_score: 100,
  pass_score: 50,
  file_types: ['pdf', 'zip'],
  max_file_mb: 20,
  allow_text: true,
}

export function AssignmentSettingsForm({ lessonId, courseId }: { lessonId: string; courseId: string }) {
  const { data, isLoading } = useQuery({
    queryKey: ['studio', 'assignment', lessonId],
    queryFn: () => instructorAssessmentApi.assignment(lessonId),
  })
  if (isLoading) return <Skeleton className="h-40" />
  return <Form lessonId={lessonId} courseId={courseId} initial={data ?? DEFAULTS} isNew={!data} />
}

function Form({
  lessonId,
  courseId,
  initial,
  isNew,
}: {
  lessonId: string
  courseId: string
  initial: AssignmentSettings
  isNew: boolean
}) {
  const qc = useQueryClient()
  const [v, setV] = useState(initial)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [saving, setSaving] = useState(false)
  const num = (s: string) => (s === '' ? null : Number(s))

  const save = async () => {
    setSaving(true)
    setErrors({})
    try {
      setV(await instructorAssessmentApi.saveAssignment(lessonId, v))
      toast.success('Assignment saved')
      void qc.invalidateQueries({ queryKey: ['studio', 'course', courseId] })
      void qc.invalidateQueries({ queryKey: ['studio', 'assignment', lessonId] })
    } catch (e) {
      if (e instanceof ApiError && e.isValidation) {
        setErrors(Object.fromEntries(Object.entries(e.errors).map(([k, m]) => [k.split('.')[0], m[0] ?? ''])))
      } else toast.error(e instanceof Error ? e.message : 'Could not save')
    } finally {
      setSaving(false)
    }
  }

  const field = (
    key: 'due_days' | 'max_score' | 'pass_score' | 'max_file_mb',
    label: string,
    hint?: string,
  ) => (
    <div className="grid gap-1">
      <label htmlFor={`as-${key}`} className="text-sm font-medium">
        {label}
      </label>
      <Input
        id={`as-${key}`}
        type="number"
        min={0}
        value={v[key] ?? ''}
        aria-invalid={!!errors[key]}
        onChange={(e) =>
          setV((s) => ({
            ...s,
            [key]:
              key === 'max_score' || key === 'max_file_mb' ? Number(e.target.value) : num(e.target.value),
          }))
        }
      />
      {hint && !errors[key] && <p className="text-muted-foreground text-xs">{hint}</p>}
      {errors[key] && (
        <p role="alert" className="text-destructive text-xs">
          {errors[key]}
        </p>
      )}
    </div>
  )

  return (
    <div className="grid gap-4 rounded-lg border p-4">
      <h3 className="font-semibold">
        Assignment
        {isNew && <span className="text-muted-foreground ml-2 text-sm font-normal">not configured yet</span>}
      </h3>
      <div className="grid gap-2">
        <span id="as-ins" className="text-sm font-medium">
          Instructions
        </span>
        <RichTextEditor
          value={v.instructions ?? ''}
          onChange={(html) => setV((s) => ({ ...s, instructions: html }))}
          aria-labelledby="as-ins"
          placeholder="What should learners build or submit?"
        />
      </div>
      <div className="grid gap-3 sm:grid-cols-4">
        {field('due_days', 'Due (days after enrolling)', 'Blank = no due date')}
        {field('max_score', 'Max score')}
        {field('pass_score', 'Pass score', 'Blank = any grade passes')}
        {field('max_file_mb', 'Max file size (MB)')}
      </div>
      <fieldset className="grid gap-2">
        <legend className="mb-1 text-sm font-medium">Accepted file types</legend>
        <div className="flex flex-wrap gap-3 text-sm">
          {FILE_TYPES.map((t) => (
            <label key={t} className="flex items-center gap-1.5">
              <input
                type="checkbox"
                checked={v.file_types.includes(t)}
                onChange={(e) =>
                  setV((s) => ({
                    ...s,
                    file_types: e.target.checked ? [...s.file_types, t] : s.file_types.filter((x) => x !== t),
                  }))
                }
              />
              .{t}
            </label>
          ))}
        </div>
      </fieldset>
      <label className="flex items-center gap-2 text-sm">
        <input
          type="checkbox"
          checked={v.allow_text}
          onChange={(e) => setV((s) => ({ ...s, allow_text: e.target.checked }))}
        />
        Allow a written answer
      </label>
      <Button type="button" className="justify-self-start" loading={saving} onClick={() => void save()}>
        Save assignment
      </Button>
    </div>
  )
}
