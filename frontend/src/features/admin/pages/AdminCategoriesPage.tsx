import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import type { ColumnDef } from '@tanstack/react-table'
import { Pencil, Plus, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'
import { DataTable } from '@/components/data-table/DataTable'
import { PageHeader } from '@/components/PageHeader'
import { Seo } from '@/components/Seo'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { FormField } from '@/components/ui/form-field'
import { Input } from '@/components/ui/input'
import { ApiError } from '@/lib/api-client'
import type { AdminCategory } from '@/types/admin-catalog'
import { catalogAdminApi, type CategoryInput } from '../catalog-api'

const KEY = ['admin', 'categories']
const errorOf = (e: unknown) => (e instanceof Error ? e.message : 'Something went wrong.')
const fieldError = (e: unknown, f: string) => (e instanceof ApiError ? e.errors[f]?.[0] : undefined)

export default function AdminCategoriesPage() {
  const qc = useQueryClient()
  const { data, isLoading } = useQuery({ queryKey: KEY, queryFn: catalogAdminApi.categories })
  const [name, setName] = useState('')
  const [editing, setEditing] = useState<AdminCategory | null>(null)
  const [deleting, setDeleting] = useState<AdminCategory | null>(null)

  const create = useMutation({
    mutationFn: (body: CategoryInput) => catalogAdminApi.createCategory(body),
    onSuccess: (c) => {
      setName('')
      toast.success(`Added “${c.name}”`)
      void qc.invalidateQueries({ queryKey: KEY })
    },
  })
  const remove = useMutation({
    mutationFn: (id: string) => catalogAdminApi.deleteCategory(id),
    onSuccess: () => {
      toast.success('Category deleted')
      setDeleting(null)
      void qc.invalidateQueries({ queryKey: KEY })
    },
    onError: (e) => toast.error(errorOf(e)),
  })

  const columns: ColumnDef<AdminCategory, unknown>[] = [
    {
      id: 'name',
      header: 'Name',
      enableHiding: false,
      cell: ({ row }) => (
        <div>
          <p className="font-medium">{row.original.name}</p>
          {row.original.description && (
            <p className="text-muted-foreground line-clamp-1 text-xs">{row.original.description}</p>
          )}
        </div>
      ),
    },
    { id: 'slug', header: 'Slug', cell: ({ row }) => <code className="text-xs">{row.original.slug}</code> },
    {
      id: 'courses',
      header: 'Courses',
      cell: ({ row }) => <span className="tabular-nums">{row.original.courses_count ?? 0}</span>,
    },
    {
      id: 'sort',
      header: 'Order',
      cell: ({ row }) => <span className="tabular-nums">{row.original.sort}</span>,
    },
    {
      id: 'actions',
      header: '',
      enableHiding: false,
      cell: ({ row }) => (
        <div className="flex justify-end gap-1">
          <Button
            size="sm"
            variant="ghost"
            onClick={() => setEditing(row.original)}
            aria-label={`Edit ${row.original.name}`}
          >
            <Pencil aria-hidden />
          </Button>
          <Button
            size="sm"
            variant="ghost"
            onClick={() => setDeleting(row.original)}
            aria-label={`Delete ${row.original.name}`}
          >
            <Trash2 aria-hidden />
          </Button>
        </div>
      ),
    },
  ]

  return (
    <>
      <Seo title="Categories" noindex />
      <PageHeader
        title="Categories"
        description="Organise the course catalogue. Categories in use can't be deleted."
      />
      <DataTable
        label="Categories"
        columns={columns}
        data={data}
        loading={isLoading}
        getRowId={(c) => c.id}
        storageKey="admin-categories"
        empty="No categories yet."
        toolbar={
          <form
            className="flex flex-wrap items-start gap-2"
            onSubmit={(e) => {
              e.preventDefault()
              if (name.trim()) create.mutate({ name: name.trim() })
            }}
          >
            <div className="grid gap-1">
              <Input
                aria-label="New category name"
                placeholder="New category name"
                className="w-64"
                value={name}
                maxLength={120}
                aria-invalid={!!fieldError(create.error, 'name')}
                onChange={(e) => setName(e.target.value)}
              />
              {create.error && (
                <p role="alert" className="text-destructive text-xs">
                  {fieldError(create.error, 'name') ?? errorOf(create.error)}
                </p>
              )}
            </div>
            <Button type="submit" size="sm" className="h-9" disabled={create.isPending || !name.trim()}>
              <Plus aria-hidden /> Add
            </Button>
          </form>
        }
      />

      {editing && <EditCategoryDialog category={editing} onClose={() => setEditing(null)} />}

      <Dialog open={!!deleting} onOpenChange={(o) => !o && setDeleting(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Delete “{deleting?.name}”?</DialogTitle>
            <DialogDescription>
              {deleting?.courses_count
                ? `${deleting.courses_count} course(s) use this category; move them first.`
                : 'This category has no courses.'}
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" onClick={() => setDeleting(null)}>
              Cancel
            </Button>
            <Button
              variant="destructive"
              disabled={remove.isPending}
              onClick={() => deleting && remove.mutate(deleting.id)}
            >
              Delete
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  )
}

function EditCategoryDialog({ category, onClose }: { category: AdminCategory; onClose: () => void }) {
  const qc = useQueryClient()
  const [form, setForm] = useState({
    name: category.name,
    slug: category.slug,
    description: category.description ?? '',
    sort: String(category.sort),
  })
  const save = useMutation({
    mutationFn: () =>
      catalogAdminApi.updateCategory(category.id, {
        name: form.name.trim(),
        slug: form.slug.trim() || undefined,
        description: form.description.trim() || null,
        sort: Number(form.sort) || 0,
      }),
    onSuccess: () => {
      toast.success('Category saved')
      void qc.invalidateQueries({ queryKey: KEY })
      onClose()
    },
  })
  const err = (f: string) => fieldError(save.error, f)
  const set = (k: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) =>
    setForm((s) => ({ ...s, [k]: e.target.value }))

  return (
    <Dialog open onOpenChange={(o) => !o && onClose()}>
      <DialogContent>
        <form
          className="grid gap-4"
          onSubmit={(e) => {
            e.preventDefault()
            save.mutate()
          }}
        >
          <DialogHeader>
            <DialogTitle>Edit category</DialogTitle>
          </DialogHeader>
          <FormField id="cat-name" label="Name" error={err('name')}>
            {(a) => <Input {...a} value={form.name} onChange={set('name')} maxLength={120} required />}
          </FormField>
          <FormField
            id="cat-slug"
            label="Slug"
            error={err('slug')}
            hint="Lowercase letters, numbers and dashes."
          >
            {(a) => <Input {...a} value={form.slug} onChange={set('slug')} maxLength={140} />}
          </FormField>
          <FormField id="cat-desc" label="Description" error={err('description')}>
            {(a) => (
              <textarea
                {...a}
                className="border-input bg-background min-h-20 rounded-md border px-3 py-2 text-sm"
                value={form.description}
                onChange={set('description')}
                maxLength={500}
              />
            )}
          </FormField>
          <FormField id="cat-sort" label="Order" error={err('sort')} hint="Lower numbers are listed first.">
            {(a) => (
              <Input {...a} type="number" min={0} value={form.sort} onChange={set('sort')} className="w-28" />
            )}
          </FormField>
          {save.error && !(save.error instanceof ApiError && save.error.isValidation) && (
            <p role="alert" className="text-destructive text-sm">
              {errorOf(save.error)}
            </p>
          )}
          <DialogFooter>
            <Button type="button" variant="outline" onClick={onClose}>
              Cancel
            </Button>
            <Button type="submit" disabled={save.isPending}>
              {save.isPending ? 'Saving…' : 'Save'}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
