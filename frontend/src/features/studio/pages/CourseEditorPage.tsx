import * as Tabs from '@radix-ui/react-tabs'
import { AlertTriangle, ExternalLink, ImageUp, Send } from 'lucide-react'
import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { toast } from 'sonner'
import { RichTextEditor } from '@/components/RichTextEditor'
import { Seo } from '@/components/Seo'
import { Alert } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { FormField } from '@/components/ui/form-field'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'
import { useCategories } from '@/features/courses/api'
import { ApiError } from '@/lib/api-client'
import type { StudioCourse } from '@/types/courses'
import { studioApi, useStudioCourse, useStudioMutation } from '../api'
import { CurriculumBuilder } from '../components/CurriculumBuilder'
import { STATUS_BADGE } from '../components/StatusBadge'

export default function CourseEditorPage() {
  const { courseId = '' } = useParams()
  const { data: course, isLoading } = useStudioCourse(courseId)
  const submit = useStudioMutation(courseId, () => studioApi.submit(courseId))

  if (isLoading || !course) return <Skeleton className="h-96" />
  const locked = course.status === 'review'

  return (
    <>
      <Seo title={`Edit: ${course.title}`} noindex />
      <div className="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
          <p className="text-muted-foreground text-sm">
            <Link to="/instructor/courses" className="hover:underline">
              Courses
            </Link>{' '}
            / Edit
          </p>
          <h1 className="mt-1 flex flex-wrap items-center gap-2 text-2xl font-bold">
            {course.title} {STATUS_BADGE[course.status]}
          </h1>
        </div>
        <div className="flex gap-2">
          <Button variant="outline" asChild>
            <Link to={`/courses/${course.slug}`} target="_blank">
              <ExternalLink aria-hidden /> Preview
            </Link>
          </Button>
          {course.status === 'draft' && (
            <Button
              loading={submit.isPending}
              onClick={() =>
                submit.mutate(undefined, {
                  onSuccess: () => toast.success('Submitted for review'),
                  onError: (e) =>
                    toast.error(
                      e instanceof ApiError
                        ? Object.values(e.errors).flat().join(' ') || e.message
                        : e.message,
                    ),
                })
              }
            >
              <Send aria-hidden /> Submit for review
            </Button>
          )}
        </div>
      </div>

      {course.review_notes && course.status === 'draft' && (
        <Alert variant="destructive" className="mb-4">
          <p className="font-semibold">Changes requested by the review team</p>
          <p className="mt-1 whitespace-pre-wrap">{course.review_notes}</p>
        </Alert>
      )}
      {locked && (
        <Alert variant="info" className="mb-4">
          This course is in review. Editing is paused until the review team responds.
        </Alert>
      )}
      {course.status === 'draft' && course.readiness.length > 0 && (
        <Alert className="mb-4 flex gap-3">
          <AlertTriangle className="text-tier-elite size-5 shrink-0" aria-hidden />
          <div>
            <p className="font-semibold">Before you can submit:</p>
            <ul className="mt-1 list-disc pl-5 text-sm">
              {course.readiness.map((r) => (
                <li key={r}>{r}</li>
              ))}
            </ul>
          </div>
        </Alert>
      )}

      <Tabs.Root defaultValue="curriculum" className="grid gap-6">
        <Tabs.List aria-label="Editor sections" className="flex gap-1 border-b">
          {(
            [
              ['curriculum', 'Curriculum'],
              ['details', 'Details'],
            ] as const
          ).map(([v, l]) => (
            <Tabs.Trigger
              key={v}
              value={v}
              className="data-[state=active]:border-primary data-[state=active]:text-foreground text-muted-foreground -mb-px border-b-2 border-transparent px-4 py-2 text-sm font-medium"
            >
              {l}
            </Tabs.Trigger>
          ))}
        </Tabs.List>
        <Tabs.Content value="curriculum">
          <fieldset disabled={locked} className="disabled:opacity-60">
            <CurriculumBuilder course={course} />
          </fieldset>
        </Tabs.Content>
        <Tabs.Content value="details">
          <DetailsForm course={course} disabled={locked} />
        </Tabs.Content>
      </Tabs.Root>
    </>
  )
}

function DetailsForm({ course, disabled }: { course: StudioCourse; disabled: boolean }) {
  const { data: categories } = useCategories()
  const [v, setV] = useState(() => toForm(course))
  const save = useStudioMutation(course.id, () =>
    studioApi.update(course.id, {
      title: v.title,
      subtitle: v.subtitle || null,
      description: v.description || null,
      category_id: v.category_id || null,
      level: v.level,
      language: v.language,
      required_tier: v.required_tier ? Number(v.required_tier) : null,
      price_amount: v.price ? Math.round(Number(v.price) * 100) : null,
      currency: v.price ? v.currency : null,
      tags: v.tags
        .split(',')
        .map((t) => t.trim())
        .filter(Boolean),
    }),
  )
  const thumb = useStudioMutation(course.id, (f: File) => studioApi.thumbnail(course.id, f))
  const [errors, setErrors] = useState<Record<string, string>>({})

  const [synced, setSynced] = useState(course)
  if (synced !== course) {
    setSynced(course)
    setV(toForm(course))
  }
  const set = (k: keyof typeof v) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) =>
    setV((p) => ({ ...p, [k]: e.target.value }))

  return (
    <form
      className="grid gap-6 lg:grid-cols-[1fr_20rem]"
      onSubmit={(e) => {
        e.preventDefault()
        setErrors({})
        save.mutate(undefined, {
          onSuccess: () => toast.success('Course saved'),
          onError: (err) => {
            if (err instanceof ApiError && err.isValidation)
              setErrors(Object.fromEntries(Object.entries(err.errors).map(([k, m]) => [k, m[0] ?? ''])))
            else toast.error(err.message)
          },
        })
      }}
    >
      <fieldset disabled={disabled} className="grid content-start gap-4">
        <FormField id="c-title" label="Title" error={errors.title}>
          {(a) => <Input {...a} value={v.title} onChange={set('title')} maxLength={160} />}
        </FormField>
        <FormField id="c-subtitle" label="Subtitle" error={errors.subtitle}>
          {(a) => <Input {...a} value={v.subtitle} onChange={set('subtitle')} maxLength={255} />}
        </FormField>
        <div className="grid gap-2">
          <span id="c-desc-label" className="text-sm font-medium">
            Description
          </span>
          <RichTextEditor
            value={v.description}
            onChange={(html) => setV((p) => ({ ...p, description: html }))}
            aria-labelledby="c-desc-label"
            placeholder="What will learners build and achieve?"
          />
        </div>
        <FormField id="c-tags" label="Tags" hint="Comma separated, up to 10" error={errors.tags}>
          {(a) => <Input {...a} value={v.tags} onChange={set('tags')} />}
        </FormField>
      </fieldset>

      <fieldset disabled={disabled} className="grid content-start gap-4">
        <Card>
          <CardHeader>
            <CardTitle className="text-base">Thumbnail</CardTitle>
          </CardHeader>
          <CardContent className="grid gap-3">
            {course.thumbnail_url ? (
              <img
                src={course.thumbnail_url}
                alt=""
                className="aspect-video w-full rounded-md object-cover"
              />
            ) : (
              <div className="bg-muted aspect-video rounded-md" aria-hidden />
            )}
            <Button type="button" variant="outline" size="sm" asChild>
              <label
                className="focus-within:ring-ring cursor-pointer focus-within:ring-2"
                aria-busy={thumb.isPending}
              >
                <ImageUp aria-hidden /> {thumb.isPending ? 'Uploading…' : 'Upload image (1280×720)'}
                <input
                  type="file"
                  accept="image/jpeg,image/png,image/webp"
                  className="sr-only"
                  onChange={(e) => {
                    const f = e.target.files?.[0]
                    e.target.value = ''
                    if (f) thumb.mutate(f, { onError: (err) => toast.error(err.message) })
                  }}
                />
              </label>
            </Button>
          </CardContent>
        </Card>
        <div className="grid gap-2">
          <Label htmlFor="c-cat">Category</Label>
          <select
            id="c-cat"
            className="border-input bg-background h-10 rounded-md border px-3 text-sm"
            value={v.category_id}
            onChange={set('category_id')}
          >
            <option value="">Choose…</option>
            {categories?.map((c) => (
              <option key={c.id} value={c.id}>
                {c.name}
              </option>
            ))}
          </select>
        </div>
        <div className="grid gap-2">
          <Label htmlFor="c-tier">Required membership</Label>
          <select
            id="c-tier"
            className="border-input bg-background h-10 rounded-md border px-3 text-sm"
            value={v.required_tier}
            onChange={set('required_tier')}
          >
            <option value="">Free for all members</option>
            <option value="1">Starter and above</option>
            <option value="2">Pro and above</option>
            <option value="3">Elite only</option>
          </select>
        </div>
        <div className="grid grid-cols-2 gap-3">
          <div className="grid gap-2">
            <Label htmlFor="c-level">Level</Label>
            <select
              id="c-level"
              className="border-input bg-background h-10 rounded-md border px-3 text-sm"
              value={v.level}
              onChange={set('level')}
            >
              <option value="beginner">Beginner</option>
              <option value="intermediate">Intermediate</option>
              <option value="advanced">Advanced</option>
              <option value="all_levels">All levels</option>
            </select>
          </div>
          <FormField id="c-lang" label="Language" error={errors.language}>
            {(a) => <Input {...a} value={v.language} onChange={set('language')} maxLength={10} />}
          </FormField>
        </div>
        <div className="grid grid-cols-[1fr_6rem] gap-3">
          <FormField
            id="c-price"
            label="One-time price (optional)"
            hint="For non-members"
            error={errors.price_amount}
          >
            {(a) => (
              <Input {...a} type="number" min={0} step="0.01" value={v.price} onChange={set('price')} />
            )}
          </FormField>
          <div className="grid gap-2">
            <Label htmlFor="c-cur">Currency</Label>
            <select
              id="c-cur"
              className="border-input bg-background h-10 rounded-md border px-2 text-sm"
              value={v.currency}
              onChange={set('currency')}
            >
              <option>INR</option>
              <option>USD</option>
            </select>
          </div>
        </div>
        <Button type="submit" loading={save.isPending}>
          Save details
        </Button>
      </fieldset>
    </form>
  )
}

function toForm(c: StudioCourse) {
  return {
    title: c.title,
    subtitle: c.subtitle ?? '',
    description: c.description ?? '',
    category_id: c.category_id ?? '',
    level: c.level,
    language: c.language,
    required_tier: c.required_tier ? String(c.required_tier) : '',
    price: c.price_amount ? String(c.price_amount / 100) : '',
    currency: c.currency ?? 'INR',
    tags: c.tags.join(', '),
  }
}
