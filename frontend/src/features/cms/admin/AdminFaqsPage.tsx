import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Pencil, Plus, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'
import { PageHeader } from '@/components/PageHeader'
import { Seo } from '@/components/Seo'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { cmsApi } from '../api'
import type { Faq, FaqCategory } from '../types'
import { SortableList } from './SortableList'
import { ConfirmDelete, Field, Toggle } from './shared'
import { aria, errorsOf, fieldClass, selectClass, type FieldErrors } from './utils'

const CATEGORIES: { value: FaqCategory; label: string }[] = [
  { value: 'general', label: 'General' },
  { value: 'billing', label: 'Billing' },
  { value: 'courses', label: 'Courses' },
  { value: 'live', label: 'Live classes' },
  { value: 'affiliates', label: 'Affiliates' },
]

const KEY = ['admin', 'cms', 'faqs']

export default function AdminFaqsPage() {
  const qc = useQueryClient()
  const { data, isLoading } = useQuery({ queryKey: KEY, queryFn: () => cmsApi.items('faqs') })
  const [editing, setEditing] = useState<Faq | 'new' | null>(null)

  const refresh = () => {
    qc.invalidateQueries({ queryKey: KEY })
    qc.invalidateQueries({ queryKey: ['cms', 'home'] })
  }

  async function reorder(ids: string[]) {
    const prev = data
    qc.setQueryData<Faq[]>(KEY, (list) => ids.map((id) => list!.find((f) => f.id === id)!))
    try {
      qc.setQueryData(KEY, await cmsApi.reorder('faqs', ids))
      qc.invalidateQueries({ queryKey: ['cms', 'home'] })
    } catch {
      qc.setQueryData(KEY, prev)
      toast.error('Could not save the new order.')
    }
  }

  async function togglePublished(f: Faq) {
    try {
      await cmsApi.updateItem('faqs', f.id, { is_published: !f.is_published })
      refresh()
    } catch {
      toast.error('Could not update the FAQ.')
    }
  }

  return (
    <>
      <Seo title="FAQs" noindex />
      <PageHeader
        title="FAQs"
        description="Questions shown on the homepage, in this order. Drag to reorder."
        actions={
          <Button onClick={() => setEditing('new')}>
            <Plus aria-hidden /> Add question
          </Button>
        }
      />
      {isLoading || !data ? (
        <Skeleton className="h-60" />
      ) : data.length === 0 ? (
        <p className="text-muted-foreground rounded-lg border border-dashed p-8 text-center">
          No questions yet. The homepage shows the built-in FAQ until you add some.
        </p>
      ) : (
        <SortableList
          items={data}
          label="FAQs"
          nameOf={(f) => f.question}
          onReorder={reorder}
          render={(f) => (
            <div className="flex flex-wrap items-start gap-3">
              <div className="min-w-0 flex-1">
                <p className="font-medium">{f.question}</p>
                <p className="text-muted-foreground line-clamp-2 text-sm">{f.answer}</p>
              </div>
              <Badge variant="outline">
                {CATEGORIES.find((c) => c.value === f.category)?.label ?? f.category}
              </Badge>
              <Toggle
                checked={!!f.is_published}
                label={`Publish “${f.question}”`}
                onChange={() => void togglePublished(f)}
              />
              <Button
                size="icon"
                variant="ghost"
                aria-label={`Edit “${f.question}”`}
                onClick={() => setEditing(f)}
              >
                <Pencil aria-hidden />
              </Button>
              <ConfirmDelete
                title="Delete this question?"
                description={f.question}
                onConfirm={async () => {
                  await cmsApi.deleteItem('faqs', f.id)
                  refresh()
                }}
              >
                {(open) => (
                  <Button size="icon" variant="ghost" aria-label={`Delete “${f.question}”`} onClick={open}>
                    <Trash2 aria-hidden />
                  </Button>
                )}
              </ConfirmDelete>
            </div>
          )}
        />
      )}
      {editing && (
        <FaqDialog
          faq={editing === 'new' ? null : editing}
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

function FaqDialog({ faq, onClose, onSaved }: { faq: Faq | null; onClose: () => void; onSaved: () => void }) {
  const [form, setForm] = useState({
    question: faq?.question ?? '',
    answer: faq?.answer ?? '',
    category: faq?.category ?? ('general' as FaqCategory),
    is_published: faq?.is_published ?? true,
  })
  const [errors, setErrors] = useState<FieldErrors>({})
  const [saving, setSaving] = useState(false)

  async function submit(e: React.FormEvent) {
    e.preventDefault()
    setSaving(true)
    setErrors({})
    try {
      if (faq) await cmsApi.updateItem('faqs', faq.id, form)
      else await cmsApi.createItem('faqs', form)
      toast.success('FAQ saved')
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
          <DialogTitle>{faq ? 'Edit question' : 'New question'}</DialogTitle>
        </DialogHeader>
        <form onSubmit={submit} className="grid gap-4" noValidate>
          <Field id="faq-q" label="Question" error={errors.question}>
            <Input
              {...aria('faq-q', errors.question)}
              value={form.question}
              maxLength={300}
              required
              onChange={(e) => setForm({ ...form, question: e.target.value })}
            />
          </Field>
          <Field id="faq-a" label="Answer" error={errors.answer}>
            <textarea
              {...aria('faq-a', errors.answer)}
              rows={5}
              className={fieldClass}
              value={form.answer}
              maxLength={3000}
              required
              onChange={(e) => setForm({ ...form, answer: e.target.value })}
            />
          </Field>
          <Field id="faq-c" label="Category" error={errors.category}>
            <select
              {...aria('faq-c', errors.category)}
              className={selectClass}
              value={form.category}
              onChange={(e) => setForm({ ...form, category: e.target.value as FaqCategory })}
            >
              {CATEGORIES.map((c) => (
                <option key={c.value} value={c.value}>
                  {c.label}
                </option>
              ))}
            </select>
          </Field>
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
