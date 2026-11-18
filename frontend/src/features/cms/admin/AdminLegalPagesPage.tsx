import { useQuery, useQueryClient } from '@tanstack/react-query'
import { ExternalLink, Pencil, Plus } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'
import { PageHeader } from '@/components/PageHeader'
import { RichTextEditor } from '@/components/RichTextEditor'
import { Seo } from '@/components/Seo'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import {
  Sheet,
  SheetBody,
  SheetContent,
  SheetDescription,
  SheetFooter,
  SheetHeader,
  SheetTitle,
} from '@/components/ui/sheet'
import { Skeleton } from '@/components/ui/skeleton'
import { formatDate } from '@/lib/format'
import { cmsApi } from '../api'
import type { AdminPage } from '../types'
import { Field, Toggle } from './shared'
import { aria, errorsOf, publicPath, type FieldErrors } from './utils'

const KEY = ['admin', 'cms', 'pages']

export default function AdminLegalPagesPage() {
  const qc = useQueryClient()
  const { data, isLoading } = useQuery({ queryKey: KEY, queryFn: cmsApi.pages })
  const [editing, setEditing] = useState<AdminPage | null>(null)
  const [newSlug, setNewSlug] = useState('')
  const slugValid = /^[a-z0-9-]{2,60}$/.test(newSlug)

  return (
    <>
      <Seo title="Legal pages" noindex />
      <PageHeader
        title="Legal & static pages"
        description="Terms and Privacy show the built-in template until you publish your own version here. Other pages live at /p/your-slug."
      />
      <form
        className="mb-4 flex flex-wrap items-end gap-2"
        onSubmit={(e) => {
          e.preventDefault()
          if (!slugValid) return
          const existing = data?.find((p) => p.slug === newSlug)
          setEditing(
            existing ?? {
              slug: newSlug,
              title: '',
              body: null,
              is_published: true,
              customised: false,
              updated_at: null,
            },
          )
          setNewSlug('')
        }}
      >
        <Field
          id="new-slug"
          label="New page address"
          hint="Lowercase letters, numbers and dashes, e.g. shipping-policy"
        >
          <Input
            {...aria('new-slug')}
            value={newSlug}
            onChange={(e) => setNewSlug(e.target.value.toLowerCase())}
            className="w-64"
          />
        </Field>
        <Button type="submit" variant="outline" disabled={!slugValid}>
          <Plus aria-hidden /> Create page
        </Button>
      </form>
      {isLoading || !data ? (
        <Skeleton className="h-60" />
      ) : (
        <div className="overflow-x-auto rounded-xl border">
          <table className="w-full text-sm">
            <thead className="bg-muted/50 text-muted-foreground text-left">
              <tr>
                <th scope="col" className="p-3 font-medium">
                  Page
                </th>
                <th scope="col" className="p-3 font-medium">
                  Address
                </th>
                <th scope="col" className="p-3 font-medium">
                  Status
                </th>
                <th scope="col" className="p-3 font-medium">
                  Updated
                </th>
                <th scope="col" className="p-3">
                  <span className="sr-only">Actions</span>
                </th>
              </tr>
            </thead>
            <tbody className="divide-y">
              {data.map((p) => (
                <tr key={p.slug}>
                  <td className="p-3 font-medium">{p.title}</td>
                  <td className="p-3">
                    <a
                      href={publicPath(p.slug)}
                      target="_blank"
                      rel="noopener"
                      className="text-primary inline-flex items-center gap-1 hover:underline"
                    >
                      {publicPath(p.slug)} <ExternalLink className="size-3" aria-hidden />
                    </a>
                  </td>
                  <td className="p-3">
                    {!p.customised ? (
                      <Badge variant="outline">
                        {p.slug === 'terms' || p.slug === 'privacy' ? 'Built-in template' : 'Not created'}
                      </Badge>
                    ) : p.is_published ? (
                      <Badge>Published</Badge>
                    ) : (
                      <Badge variant="secondary">Draft</Badge>
                    )}
                  </td>
                  <td className="text-muted-foreground p-3">{formatDate(p.updated_at)}</td>
                  <td className="p-3 text-right">
                    <Button size="sm" variant="ghost" onClick={() => setEditing(p)}>
                      <Pencil aria-hidden /> Edit
                    </Button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {editing && (
        <PageEditor
          page={editing}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null)
            qc.invalidateQueries({ queryKey: KEY })
            qc.invalidateQueries({ queryKey: ['cms', 'page'] })
          }}
        />
      )}
    </>
  )
}

function PageEditor({
  page,
  onClose,
  onSaved,
}: {
  page: AdminPage
  onClose: () => void
  onSaved: () => void
}) {
  const [title, setTitle] = useState(page.title)
  const [body, setBody] = useState(page.body ?? '')
  const [published, setPublished] = useState(page.customised ? page.is_published : true)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [saving, setSaving] = useState(false)

  async function save() {
    setSaving(true)
    setErrors({})
    try {
      await cmsApi.savePage(page.slug, { title, body, is_published: published })
      toast.success('Page saved')
      onSaved()
    } catch (e) {
      setErrors(errorsOf(e))
    } finally {
      setSaving(false)
    }
  }

  return (
    <Sheet open onOpenChange={(o) => !o && onClose()}>
      <SheetContent className="max-w-3xl">
        <SheetHeader>
          <SheetTitle>
            {page.customised ? `Edit ${page.title}` : `Write ${page.title || page.slug}`}
          </SheetTitle>
          <SheetDescription>
            {publicPath(page.slug)} · unpublished pages{' '}
            {page.slug === 'terms' || page.slug === 'privacy'
              ? 'fall back to the built-in template'
              : 'return “not found”'}
            .
          </SheetDescription>
        </SheetHeader>
        <SheetBody>
          <Field id="pg-title" label="Title" error={errors.title}>
            <Input
              {...aria('pg-title', errors.title)}
              value={title}
              maxLength={200}
              onChange={(e) => setTitle(e.target.value)}
            />
          </Field>
          <div className="grid gap-1.5">
            <p id="pg-body-label" className="text-sm font-medium">
              Content
            </p>
            <RichTextEditor
              value={body}
              onChange={setBody}
              aria-labelledby="pg-body-label"
              placeholder="Write the page…"
            />
            {errors.body && (
              <p role="alert" className="text-destructive text-sm">
                {errors.body}
              </p>
            )}
          </div>
          <div className="flex items-center gap-3 text-sm">
            <Toggle checked={published} label="Published" onChange={setPublished} /> Published
          </div>
          {errors._ && (
            <p role="alert" className="text-destructive text-sm">
              {errors._}
            </p>
          )}
        </SheetBody>
        <SheetFooter>
          <Button variant="outline" onClick={onClose}>
            Cancel
          </Button>
          <Button loading={saving} onClick={save}>
            Save page
          </Button>
        </SheetFooter>
      </SheetContent>
    </Sheet>
  )
}
