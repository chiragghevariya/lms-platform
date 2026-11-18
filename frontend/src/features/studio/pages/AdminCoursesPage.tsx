import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { toast } from 'sonner'
import { PageHeader } from '@/components/PageHeader'
import { Seo } from '@/components/Seo'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Skeleton } from '@/components/ui/skeleton'
import { formatDate, formatMinutes } from '@/lib/format'
import type { CourseCard } from '@/types/courses'
import { adminCoursesApi } from '../api'
import { STATUS_BADGE } from '../components/StatusBadge'

export default function AdminCoursesPage() {
  const [params, setParams] = useSearchParams()
  const status = params.get('status') ?? 'review'
  const qc = useQueryClient()
  const { data, isLoading } = useQuery({
    queryKey: ['admin', 'courses', status],
    queryFn: () => adminCoursesApi.list(status),
  })
  const done = () => qc.invalidateQueries({ queryKey: ['admin', 'courses'] })
  const approve = useMutation({
    mutationFn: adminCoursesApi.approve,
    onSuccess: () => {
      toast.success('Published')
      void done()
    },
  })
  const feature = useMutation({
    mutationFn: (c: CourseCard) => adminCoursesApi.update(c.id, { is_featured: !c.is_featured }),
    onSuccess: done,
  })
  const [rejecting, setRejecting] = useState<CourseCard | null>(null)
  const [notes, setNotes] = useState('')
  const reject = useMutation({
    mutationFn: () => adminCoursesApi.reject(rejecting!.id, notes),
    onSuccess: () => {
      toast.success('Sent back to the instructor')
      setRejecting(null)
      setNotes('')
      void done()
    },
  })

  return (
    <>
      <Seo title="Course review" noindex />
      <PageHeader title="Courses" description="Review submissions and manage the catalogue." />
      <div role="tablist" aria-label="Status" className="mb-4 flex gap-2">
        {(
          [
            ['review', 'Awaiting review'],
            ['published', 'Published'],
            ['draft', 'Drafts'],
          ] as const
        ).map(([v, l]) => (
          <Button
            key={v}
            role="tab"
            aria-selected={status === v}
            size="sm"
            variant={status === v ? 'default' : 'outline'}
            onClick={() => setParams({ status: v })}
          >
            {l}
          </Button>
        ))}
      </div>
      <Card className="overflow-x-auto">
        <table className="w-full text-left text-sm">
          <thead className="bg-muted/50 text-muted-foreground">
            <tr>
              <th scope="col" className="px-4 py-3 font-medium">
                Course
              </th>
              <th scope="col" className="hidden px-4 py-3 font-medium md:table-cell">
                Instructor
              </th>
              <th scope="col" className="hidden px-4 py-3 font-medium md:table-cell">
                Content
              </th>
              <th scope="col" className="px-4 py-3 font-medium">
                Status
              </th>
              <th scope="col" className="px-4 py-3">
                <span className="sr-only">Actions</span>
              </th>
            </tr>
          </thead>
          <tbody className="divide-y">
            {isLoading && (
              <tr>
                <td colSpan={5} className="p-4">
                  <Skeleton className="h-16" />
                </td>
              </tr>
            )}
            {data?.data.map((c) => (
              <tr key={c.id}>
                <td className="px-4 py-3">
                  <Link to={`/courses/${c.slug}`} target="_blank" className="font-medium hover:underline">
                    {c.title}
                  </Link>
                </td>
                <td className="hidden px-4 py-3 md:table-cell">{c.instructor.name}</td>
                <td className="hidden px-4 py-3 md:table-cell">
                  {c.lessons_count} lessons · {formatMinutes(c.duration_minutes)}
                </td>
                <td className="px-4 py-3">
                  {STATUS_BADGE[c.status]}
                  {c.published_at && (
                    <span className="text-muted-foreground ml-2 text-xs">{formatDate(c.published_at)}</span>
                  )}
                </td>
                <td className="px-4 py-3 text-right">
                  {c.status === 'review' && (
                    <div className="flex justify-end gap-2">
                      <Button size="sm" variant="outline" onClick={() => setRejecting(c)}>
                        Request changes
                      </Button>
                      <Button
                        size="sm"
                        loading={approve.isPending && approve.variables === c.id}
                        onClick={() => approve.mutate(c.id)}
                      >
                        Approve & publish
                      </Button>
                    </div>
                  )}
                  {c.status === 'published' && (
                    <Button size="sm" variant="outline" onClick={() => feature.mutate(c)}>
                      {c.is_featured ? 'Unfeature' : 'Feature'}
                    </Button>
                  )}
                </td>
              </tr>
            ))}
            {data?.data.length === 0 && (
              <tr>
                <td colSpan={5} className="text-muted-foreground p-8 text-center">
                  Nothing here.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </Card>
      <Dialog open={!!rejecting} onOpenChange={(o) => !o && setRejecting(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Request changes</DialogTitle>
            <DialogDescription>
              The course returns to draft and the instructor is emailed your notes.
            </DialogDescription>
          </DialogHeader>
          <label htmlFor="reject-notes" className="text-sm font-medium">
            What needs to change?
          </label>
          <textarea
            id="reject-notes"
            rows={5}
            value={notes}
            onChange={(e) => setNotes(e.target.value)}
            className="border-input bg-background focus-visible:ring-ring rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
          />
          <DialogFooter>
            <Button variant="outline" onClick={() => setRejecting(null)}>
              Cancel
            </Button>
            <Button
              disabled={notes.trim().length < 10}
              loading={reject.isPending}
              onClick={() => reject.mutate()}
            >
              Send back
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  )
}
