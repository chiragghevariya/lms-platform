import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { ChevronLeft, ChevronRight, Plus } from 'lucide-react'
import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { PageHeader } from '@/components/PageHeader'
import { Seo } from '@/components/Seo'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { formatDate } from '@/lib/format'
import { cmsApi } from '../api'
import { postState, selectClass } from './utils'

export default function AdminBlogPage() {
  const navigate = useNavigate()
  const [f, setF] = useState({ status: '', search: '', page: 1 })
  const params = { status: f.status || undefined, search: f.search || undefined, page: f.page }
  const { data, isLoading } = useQuery({
    queryKey: ['admin', 'cms', 'posts', params],
    queryFn: () => cmsApi.adminPosts(params),
    placeholderData: keepPreviousData,
  })

  return (
    <>
      <Seo title="Blog" noindex />
      <PageHeader
        title="Blog"
        description="Posts at /blog. Publish now, schedule for later, or keep as a draft."
        actions={
          <Button asChild>
            <Link to="/admin/blog/new">
              <Plus aria-hidden /> New post
            </Link>
          </Button>
        }
      />
      <div className="mb-4 flex flex-wrap gap-2">
        <select
          aria-label="Status"
          className={selectClass}
          value={f.status}
          onChange={(e) => setF({ ...f, status: e.target.value, page: 1 })}
        >
          <option value="">All posts</option>
          <option value="published">Published & scheduled</option>
          <option value="draft">Drafts</option>
        </select>
        <Input
          aria-label="Search titles"
          placeholder="Search titles"
          className="w-64"
          value={f.search}
          onChange={(e) => setF({ ...f, search: e.target.value, page: 1 })}
        />
      </div>
      {isLoading || !data ? (
        <Skeleton className="h-60" />
      ) : (
        <>
          <div className="overflow-x-auto rounded-xl border">
            <table className="w-full text-sm" aria-label="Posts">
              <thead className="bg-muted/50 text-muted-foreground text-left">
                <tr>
                  <th scope="col" className="p-3 font-medium">
                    Title
                  </th>
                  <th scope="col" className="p-3 font-medium">
                    Status
                  </th>
                  <th scope="col" className="p-3 font-medium">
                    Publish date
                  </th>
                  <th scope="col" className="p-3 font-medium">
                    Author
                  </th>
                  <th scope="col" className="p-3 font-medium">
                    Updated
                  </th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {data.data.map((p) => {
                  const state = postState(p)
                  return (
                    <tr
                      key={p.id}
                      tabIndex={0}
                      className="hover:bg-muted/40 focus-visible:bg-muted/40 cursor-pointer outline-none"
                      onClick={() => navigate(`/admin/blog/${p.id}`)}
                      onKeyDown={(e) => {
                        if (e.key === 'Enter') navigate(`/admin/blog/${p.id}`)
                      }}
                    >
                      <td className="p-3">
                        <Link
                          to={`/admin/blog/${p.id}`}
                          className="font-medium hover:underline"
                          onClick={(e) => e.stopPropagation()}
                        >
                          {p.title}
                        </Link>
                        <span className="text-muted-foreground block text-xs">/blog/{p.slug}</span>
                      </td>
                      <td className="p-3">
                        <Badge
                          variant={
                            state === 'published'
                              ? 'default'
                              : state === 'scheduled'
                                ? 'secondary'
                                : 'outline'
                          }
                        >
                          {state === 'published'
                            ? 'Published'
                            : state === 'scheduled'
                              ? 'Scheduled'
                              : 'Draft'}
                        </Badge>
                      </td>
                      <td className="text-muted-foreground p-3">{formatDate(p.published_at)}</td>
                      <td className="text-muted-foreground p-3">{p.author?.name ?? '—'}</td>
                      <td className="text-muted-foreground p-3">{formatDate(p.updated_at)}</td>
                    </tr>
                  )
                })}
                {data.data.length === 0 && (
                  <tr>
                    <td colSpan={5} className="text-muted-foreground p-8 text-center">
                      No posts yet.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
          {data.meta.last_page > 1 && (
            <nav aria-label="Post pages" className="mt-3 flex items-center justify-end gap-2 text-sm">
              <span className="text-muted-foreground tabular-nums">
                Page {data.meta.current_page} of {data.meta.last_page}
              </span>
              <Button
                size="sm"
                variant="outline"
                disabled={f.page <= 1}
                onClick={() => setF({ ...f, page: f.page - 1 })}
              >
                <ChevronLeft aria-hidden /> Previous
              </Button>
              <Button
                size="sm"
                variant="outline"
                disabled={f.page >= data.meta.last_page}
                onClick={() => setF({ ...f, page: f.page + 1 })}
              >
                Next <ChevronRight aria-hidden />
              </Button>
            </nav>
          )}
        </>
      )}
    </>
  )
}
