import { useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, ExternalLink, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { toast } from 'sonner'
import { PageHeader } from '@/components/PageHeader'
import { RichTextEditor } from '@/components/RichTextEditor'
import { Seo } from '@/components/Seo'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { cmsApi } from '../api'
import type { Post } from '../types'
import { ConfirmDelete, Field } from './shared'
import {
  aria,
  errorsOf,
  fieldClass,
  fromLocalInput,
  postState,
  selectClass,
  toLocalInput,
  type FieldErrors,
} from './utils'

/** /admin/blog/:id — `id` is a post uuid or "new". */
export default function AdminPostEditorPage() {
  const { id = 'new' } = useParams()
  const isNew = id === 'new'
  const { data, isLoading, isError } = useQuery({
    queryKey: ['admin', 'cms', 'post', id],
    queryFn: () => cmsApi.adminPost(id),
    enabled: !isNew,
  })

  if (isError) return <p className="text-muted-foreground py-16 text-center">This post doesn't exist.</p>
  if (!isNew && (isLoading || !data)) return <Skeleton className="h-[32rem]" />

  // `key` remounts the form when navigating from "new" to the created post.
  return <PostForm key={data?.id ?? 'new'} post={isNew ? null : data!} />
}

function PostForm({ post }: { post: Post | null }) {
  const qc = useQueryClient()
  const navigate = useNavigate()
  const [form, setForm] = useState({
    title: post?.title ?? '',
    slug: post?.slug ?? '',
    excerpt: post?.excerpt ?? '',
    cover_url: post?.cover_url ?? '',
    body: post?.body ?? '',
    status: post?.status ?? ('draft' as Post['status']),
    published_at: toLocalInput(post?.published_at),
    seo_title: post?.seo_title ?? '',
    seo_description: post?.seo_description ?? '',
  })
  const [errors, setErrors] = useState<FieldErrors>({})
  const [saving, setSaving] = useState(false)
  const set = (patch: Partial<typeof form>) => setForm((f) => ({ ...f, ...patch }))
  const state = post ? postState(post) : 'draft'

  async function save() {
    setSaving(true)
    setErrors({})
    const body: Partial<Post> = {
      title: form.title,
      excerpt: form.excerpt || null,
      cover_url: form.cover_url || null,
      body: form.body,
      status: form.status,
      seo_title: form.seo_title || null,
      seo_description: form.seo_description || null,
    }
    // An empty date means "now" on first publish; never send null (it would unpublish a live post).
    if (form.published_at) body.published_at = fromLocalInput(form.published_at)
    // Only send the slug when it was typed/changed; the server derives it from the title otherwise.
    if (form.slug && form.slug !== post?.slug) body.slug = form.slug
    try {
      const saved = post ? await cmsApi.updatePost(post.id, body) : await cmsApi.createPost(body)
      qc.invalidateQueries({ queryKey: ['admin', 'cms', 'posts'] })
      qc.invalidateQueries({ queryKey: ['blog'] })
      qc.setQueryData(['admin', 'cms', 'post', saved.id], saved)
      toast.success(post ? 'Post saved' : 'Post created')
      if (!post) navigate(`/admin/blog/${saved.id}`, { replace: true })
    } catch (e) {
      setErrors(errorsOf(e))
    } finally {
      setSaving(false)
    }
  }

  return (
    <>
      <Seo title={post ? `Edit: ${post.title}` : 'New post'} noindex />
      <Link
        to="/admin/blog"
        className="text-muted-foreground hover:text-foreground mb-4 inline-flex items-center gap-1 text-sm"
      >
        <ArrowLeft className="size-4" aria-hidden /> All posts
      </Link>
      <PageHeader
        title={post ? post.title : 'New post'}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            {post && (
              <Badge variant={state === 'published' ? 'default' : 'outline'}>
                {state === 'published' ? 'Published' : state === 'scheduled' ? 'Scheduled' : 'Draft'}
              </Badge>
            )}
            {post && state === 'published' && (
              <Button variant="outline" asChild>
                <a href={`/blog/${post.slug}`} target="_blank" rel="noopener">
                  <ExternalLink aria-hidden /> View
                </a>
              </Button>
            )}
            {post && (
              <ConfirmDelete
                title="Delete this post?"
                description="It disappears from the blog immediately."
                onConfirm={async () => {
                  await cmsApi.deletePost(post.id)
                  qc.invalidateQueries({ queryKey: ['admin', 'cms', 'posts'] })
                  navigate('/admin/blog', { replace: true })
                }}
              >
                {(open) => (
                  <Button variant="outline" onClick={open}>
                    <Trash2 aria-hidden /> Delete
                  </Button>
                )}
              </ConfirmDelete>
            )}
            <Button loading={saving} onClick={save}>
              {post ? 'Save' : 'Create post'}
            </Button>
          </div>
        }
      />
      <div className="grid gap-6 lg:grid-cols-[1fr_20rem]">
        <div className="grid content-start gap-4">
          <Field id="p-title" label="Title" error={errors.title}>
            <Input
              {...aria('p-title', errors.title)}
              value={form.title}
              maxLength={200}
              onChange={(e) => set({ title: e.target.value })}
            />
          </Field>
          <Field
            id="p-excerpt"
            label="Excerpt"
            hint="Shown on the blog list and in search results"
            error={errors.excerpt}
          >
            <textarea
              {...aria('p-excerpt', errors.excerpt)}
              rows={2}
              maxLength={500}
              className={fieldClass}
              value={form.excerpt}
              onChange={(e) => set({ excerpt: e.target.value })}
            />
          </Field>
          <div className="grid gap-1.5">
            <p id="p-body-label" className="text-sm font-medium">
              Content
            </p>
            <RichTextEditor
              value={form.body}
              onChange={(body) => set({ body })}
              aria-labelledby="p-body-label"
              placeholder="Write your post…"
            />
            {errors.body && (
              <p role="alert" className="text-destructive text-sm">
                {errors.body}
              </p>
            )}
          </div>
          {errors._ && (
            <p role="alert" className="text-destructive text-sm">
              {errors._}
            </p>
          )}
        </div>
        <div className="grid content-start gap-4">
          <Card>
            <CardHeader>
              <CardTitle className="text-base">Publishing</CardTitle>
            </CardHeader>
            <CardContent className="grid gap-4">
              <Field id="p-status" label="Status" error={errors.status}>
                <select
                  {...aria('p-status', errors.status)}
                  className={selectClass}
                  value={form.status}
                  onChange={(e) => set({ status: e.target.value as Post['status'] })}
                >
                  <option value="draft">Draft</option>
                  <option value="published">Published</option>
                </select>
              </Field>
              <Field
                id="p-date"
                label="Publish date"
                hint="Empty = now when published. A future date schedules the post."
                error={errors.published_at}
              >
                <Input
                  {...aria('p-date', errors.published_at)}
                  type="datetime-local"
                  value={form.published_at}
                  onChange={(e) => set({ published_at: e.target.value })}
                />
              </Field>
              <Field
                id="p-slug"
                label="URL slug"
                hint={post ? `/blog/${post.slug}` : 'Leave empty to use the title'}
                error={errors.slug}
              >
                <Input
                  {...aria('p-slug', errors.slug)}
                  value={form.slug}
                  onChange={(e) => set({ slug: e.target.value.toLowerCase() })}
                />
              </Field>
              <Field
                id="p-cover"
                label="Cover image URL"
                hint="https:// image, 16:9 works best"
                error={errors.cover_url}
              >
                <Input
                  {...aria('p-cover', errors.cover_url)}
                  type="url"
                  value={form.cover_url}
                  onChange={(e) => set({ cover_url: e.target.value })}
                />
              </Field>
              {form.cover_url.startsWith('https://') && (
                <img
                  src={form.cover_url}
                  alt="Cover preview"
                  className="aspect-[16/9] w-full rounded-md object-cover"
                />
              )}
            </CardContent>
          </Card>
          <Card>
            <CardHeader>
              <CardTitle className="text-base">Search & sharing</CardTitle>
            </CardHeader>
            <CardContent className="grid gap-4">
              <Field
                id="p-seo-t"
                label="SEO title"
                hint="Defaults to the post title"
                error={errors.seo_title}
              >
                <Input
                  {...aria('p-seo-t', errors.seo_title)}
                  value={form.seo_title}
                  maxLength={200}
                  onChange={(e) => set({ seo_title: e.target.value })}
                />
              </Field>
              <Field
                id="p-seo-d"
                label="SEO description"
                hint="Defaults to the excerpt"
                error={errors.seo_description}
              >
                <textarea
                  {...aria('p-seo-d', errors.seo_description)}
                  rows={3}
                  maxLength={300}
                  className={fieldClass}
                  value={form.seo_description}
                  onChange={(e) => set({ seo_description: e.target.value })}
                />
              </Field>
            </CardContent>
          </Card>
        </div>
      </div>
    </>
  )
}
