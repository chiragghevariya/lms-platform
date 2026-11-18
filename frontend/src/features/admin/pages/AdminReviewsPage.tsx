import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import type { ColumnDef } from '@tanstack/react-table'
import { EyeOff, ExternalLink } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { toast } from 'sonner'
import { DataTable, toolbarSelect } from '@/components/data-table/DataTable'
import { PageHeader } from '@/components/PageHeader'
import { Seo } from '@/components/Seo'
import { Stars } from '@/components/StarRating'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { formatDate } from '@/lib/format'
import type { AdminReview } from '@/types/admin-catalog'
import { ReasonDialog } from '../catalog/ReasonDialog'
import { catalogAdminApi, type ReviewFilters } from '../catalog-api'

const errorOf = (e: unknown) => (e instanceof Error ? e.message : 'Something went wrong.')

export default function AdminReviewsPage() {
  const qc = useQueryClient()
  const [f, setF] = useState<ReviewFilters>({ search: '', rating: '', state: '', page: 1 })
  const set = (patch: Partial<ReviewFilters>) => setF((s) => ({ ...s, page: 1, ...patch }))
  const { data, isLoading } = useQuery({
    queryKey: ['admin', 'reviews', f],
    queryFn: () => catalogAdminApi.reviews(f),
    placeholderData: keepPreviousData,
  })
  const [hiding, setHiding] = useState<AdminReview | null>(null)
  const [deleting, setDeleting] = useState<AdminReview | null>(null)
  const refresh = () => qc.invalidateQueries({ queryKey: ['admin', 'reviews'] })

  const unhide = useMutation({
    mutationFn: (id: string) => catalogAdminApi.unhideReview(id),
    onSuccess: () => {
      toast.success('Review restored')
      void refresh()
    },
    onError: (e) => toast.error(errorOf(e)),
  })

  const columns: ColumnDef<AdminReview, unknown>[] = [
    {
      id: 'review',
      header: 'Review',
      enableHiding: false,
      cell: ({ row: { original: r } }) => (
        <div className="grid max-w-xl gap-1">
          <div className="flex flex-wrap items-center gap-2">
            <Stars value={r.rating} />
            {r.hidden && (
              <Badge variant="outline" className="border-destructive/50 text-destructive">
                <EyeOff className="size-3" aria-hidden /> Hidden
              </Badge>
            )}
          </div>
          <p className={r.hidden ? 'text-muted-foreground line-through decoration-1' : undefined}>
            {r.comment || <span className="text-muted-foreground italic">Rating only</span>}
          </p>
          {r.hidden && r.hidden_reason && (
            <p className="text-muted-foreground text-xs">Hidden: {r.hidden_reason}</p>
          )}
        </div>
      ),
    },
    {
      id: 'course',
      header: 'Course',
      cell: ({ row: { original: r } }) => (
        <Link
          to={`/courses/${r.course.slug}`}
          className="hover:text-primary inline-flex items-center gap-1 underline-offset-2 hover:underline"
        >
          {r.course.title} <ExternalLink className="size-3" aria-hidden />
        </Link>
      ),
    },
    {
      id: 'learner',
      header: 'Learner',
      cell: ({ row: { original: r } }) => (
        <div>
          <p>{r.user.name}</p>
          <p className="text-muted-foreground text-xs">{r.user.email}</p>
        </div>
      ),
    },
    {
      id: 'date',
      header: 'Date',
      cell: ({ row }) => <span className="whitespace-nowrap">{formatDate(row.original.created_at)}</span>,
    },
    {
      id: 'actions',
      header: '',
      enableHiding: false,
      cell: ({ row: { original: r } }) => (
        <div className="flex justify-end gap-1">
          {r.hidden ? (
            <Button
              size="sm"
              variant="outline"
              disabled={unhide.isPending}
              onClick={() => unhide.mutate(r.id)}
            >
              Restore
            </Button>
          ) : (
            <Button size="sm" variant="outline" onClick={() => setHiding(r)}>
              Hide
            </Button>
          )}
          <Button size="sm" variant="ghost" onClick={() => setDeleting(r)}>
            Delete
          </Button>
        </div>
      ),
    },
  ]

  return (
    <>
      <Seo title="Reviews" noindex />
      <PageHeader
        title="Review moderation"
        description="Hidden reviews disappear from course pages and no longer count towards the course rating."
      />
      <DataTable
        label="Course reviews"
        columns={columns}
        data={data?.data}
        meta={data?.meta}
        loading={isLoading}
        getRowId={(r) => r.id}
        onPageChange={(page) => setF((s) => ({ ...s, page }))}
        storageKey="admin-reviews"
        toolbar={
          <>
            <Input
              aria-label="Search reviews"
              placeholder="Text, learner name or email"
              className="w-60"
              value={f.search}
              onChange={(e) => set({ search: e.target.value })}
            />
            <select
              aria-label="Rating"
              className={toolbarSelect}
              value={String(f.rating ?? '')}
              onChange={(e) => set({ rating: e.target.value ? Number(e.target.value) : '' })}
            >
              <option value="">All ratings</option>
              {[5, 4, 3, 2, 1].map((n) => (
                <option key={n} value={n}>
                  {n} star{n > 1 ? 's' : ''}
                </option>
              ))}
            </select>
            <select
              aria-label="Visibility"
              className={toolbarSelect}
              value={f.state}
              onChange={(e) => set({ state: e.target.value as ReviewFilters['state'] })}
            >
              <option value="">Visible and hidden</option>
              <option value="visible">Visible</option>
              <option value="hidden">Hidden</option>
            </select>
          </>
        }
      />

      <ReasonDialog
        open={!!hiding}
        onOpenChange={(o) => !o && setHiding(null)}
        title="Hide this review?"
        description="The learner's review stays on record (and in the audit log) but is removed from the course page and rating."
        confirmLabel="Hide review"
        onConfirm={async (reason) => {
          await catalogAdminApi.hideReview(hiding!.id, reason)
          toast.success('Review hidden')
          await refresh()
        }}
      />
      <ReasonDialog
        open={!!deleting}
        onOpenChange={(o) => !o && setDeleting(null)}
        title="Delete this review permanently?"
        description="Prefer hiding: deleting can't be undone (a copy is kept in the audit log)."
        label="Note"
        required={false}
        destructive
        confirmLabel="Delete"
        onConfirm={async () => {
          await catalogAdminApi.deleteReview(deleting!.id)
          toast.success('Review deleted')
          await refresh()
        }}
      />
    </>
  )
}
