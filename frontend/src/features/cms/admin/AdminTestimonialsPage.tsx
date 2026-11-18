import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Pencil, Plus, Star, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'
import { PageHeader } from '@/components/PageHeader'
import { Seo } from '@/components/Seo'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { cmsApi } from '../api'
import type { Testimonial } from '../types'
import { SortableList } from './SortableList'
import { ConfirmDelete, Field, Toggle } from './shared'
import { aria, errorsOf, fieldClass, selectClass, type FieldErrors } from './utils'

const KEY = ['admin', 'cms', 'testimonials']

export default function AdminTestimonialsPage() {
  const qc = useQueryClient()
  const { data, isLoading } = useQuery({ queryKey: KEY, queryFn: () => cmsApi.items('testimonials') })
  const [editing, setEditing] = useState<Testimonial | 'new' | null>(null)

  const refresh = () => {
    qc.invalidateQueries({ queryKey: KEY })
    qc.invalidateQueries({ queryKey: ['cms', 'home'] })
  }

  async function reorder(ids: string[]) {
    const prev = data
    qc.setQueryData<Testimonial[]>(KEY, (list) => ids.map((id) => list!.find((t) => t.id === id)!))
    try {
      qc.setQueryData(KEY, await cmsApi.reorder('testimonials', ids))
      qc.invalidateQueries({ queryKey: ['cms', 'home'] })
    } catch {
      qc.setQueryData(KEY, prev)
      toast.error('Could not save the new order.')
    }
  }

  async function togglePublished(t: Testimonial) {
    try {
      await cmsApi.updateItem('testimonials', t.id, { is_published: !t.is_published })
      refresh()
    } catch {
      toast.error('Could not update the testimonial.')
    }
  }

  return (
    <>
      <Seo title="Testimonials" noindex />
      <PageHeader
        title="Testimonials"
        description="Learner stories on the homepage. The section is hidden until at least one is published."
        actions={
          <Button onClick={() => setEditing('new')}>
            <Plus aria-hidden /> Add testimonial
          </Button>
        }
      />
      {isLoading || !data ? (
        <Skeleton className="h-60" />
      ) : data.length === 0 ? (
        <p className="text-muted-foreground rounded-lg border border-dashed p-8 text-center">
          No testimonials yet.
        </p>
      ) : (
        <SortableList
          items={data}
          label="Testimonials"
          nameOf={(t) => t.name}
          onReorder={reorder}
          render={(t) => (
            <div className="flex flex-wrap items-start gap-3">
              {t.avatar_url && (
                <img src={t.avatar_url} alt="" className="size-10 rounded-full object-cover" />
              )}
              <div className="min-w-0 flex-1">
                <p className="font-medium">
                  {t.name}
                  {t.role && <span className="text-muted-foreground font-normal"> · {t.role}</span>}
                </p>
                <p className="text-muted-foreground line-clamp-2 text-sm italic">“{t.quote}”</p>
                {t.rating && (
                  <p className="text-muted-foreground mt-1 flex items-center gap-1 text-xs">
                    <Star className="size-3 fill-amber-400 text-amber-400" aria-hidden /> {t.rating}/5
                  </p>
                )}
              </div>
              <Toggle
                checked={!!t.is_published}
                label={`Publish testimonial from ${t.name}`}
                onChange={() => void togglePublished(t)}
              />
              <Button
                size="icon"
                variant="ghost"
                aria-label={`Edit testimonial from ${t.name}`}
                onClick={() => setEditing(t)}
              >
                <Pencil aria-hidden />
              </Button>
              <ConfirmDelete
                title="Delete this testimonial?"
                description={`From ${t.name}.`}
                onConfirm={async () => {
                  await cmsApi.deleteItem('testimonials', t.id)
                  refresh()
                }}
              >
                {(open) => (
                  <Button
                    size="icon"
                    variant="ghost"
                    aria-label={`Delete testimonial from ${t.name}`}
                    onClick={open}
                  >
                    <Trash2 aria-hidden />
                  </Button>
                )}
              </ConfirmDelete>
            </div>
          )}
        />
      )}
      {editing && (
        <TestimonialDialog
          item={editing === 'new' ? null : editing}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null)
            refresh()
          }}
        />
      )}
    </>
  )
}

function TestimonialDialog({
  item,
  onClose,
  onSaved,
}: {
  item: Testimonial | null
  onClose: () => void
  onSaved: () => void
}) {
  const [form, setForm] = useState({
    name: item?.name ?? '',
    role: item?.role ?? '',
    quote: item?.quote ?? '',
    rating: item?.rating ? String(item.rating) : '',
    avatar_url: item?.avatar_url ?? '',
    is_published: item?.is_published ?? true,
  })
  const [errors, setErrors] = useState<FieldErrors>({})
  const [saving, setSaving] = useState(false)

  async function submit(e: React.FormEvent) {
    e.preventDefault()
    setSaving(true)
    setErrors({})
    const body = {
      name: form.name,
      role: form.role || null,
      quote: form.quote,
      rating: form.rating ? Number(form.rating) : null,
      avatar_url: form.avatar_url || null,
      is_published: form.is_published,
    }
    try {
      if (item) await cmsApi.updateItem('testimonials', item.id, body)
      else await cmsApi.createItem('testimonials', body)
      toast.success('Testimonial saved')
      onSaved()
    } catch (err) {
      setErrors(errorsOf(err))
    } finally {
      setSaving(false)
    }
  }

  return (
    <Dialog open onOpenChange={(o) => !o && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{item ? 'Edit testimonial' : 'New testimonial'}</DialogTitle>
        </DialogHeader>
        <form onSubmit={submit} className="grid gap-4" noValidate>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field id="t-name" label="Name" error={errors.name}>
              <Input
                {...aria('t-name', errors.name)}
                value={form.name}
                maxLength={120}
                required
                onChange={(e) => setForm({ ...form, name: e.target.value })}
              />
            </Field>
            <Field id="t-role" label="Role / city" error={errors.role}>
              <Input
                {...aria('t-role', errors.role)}
                value={form.role}
                maxLength={160}
                placeholder="Freelance designer, Pune"
                onChange={(e) => setForm({ ...form, role: e.target.value })}
              />
            </Field>
          </div>
          <Field id="t-quote" label="Quote" error={errors.quote}>
            <textarea
              {...aria('t-quote', errors.quote)}
              rows={4}
              className={fieldClass}
              value={form.quote}
              maxLength={1000}
              required
              onChange={(e) => setForm({ ...form, quote: e.target.value })}
            />
          </Field>
          <div className="grid gap-4 sm:grid-cols-[auto_1fr]">
            <Field id="t-rating" label="Rating" error={errors.rating}>
              <select
                {...aria('t-rating', errors.rating)}
                className={selectClass}
                value={form.rating}
                onChange={(e) => setForm({ ...form, rating: e.target.value })}
              >
                <option value="">None</option>
                {[5, 4, 3, 2, 1].map((r) => (
                  <option key={r} value={r}>
                    {r} stars
                  </option>
                ))}
              </select>
            </Field>
            <Field id="t-avatar" label="Photo URL" hint="Optional https:// image" error={errors.avatar_url}>
              <Input
                {...aria('t-avatar', errors.avatar_url)}
                type="url"
                value={form.avatar_url}
                onChange={(e) => setForm({ ...form, avatar_url: e.target.value })}
              />
            </Field>
          </div>
          <div className="flex items-center gap-3 text-sm">
            <Toggle
              checked={form.is_published}
              label="Published"
              onChange={(v) => setForm({ ...form, is_published: v })}
            />{' '}
            Published
          </div>
          {errors._ && (
            <p role="alert" className="text-destructive text-sm">
              {errors._}
            </p>
          )}
          <DialogFooter>
            <Button type="button" variant="outline" onClick={onClose}>
              Cancel
            </Button>
            <Button type="submit" loading={saving}>
              Save
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
