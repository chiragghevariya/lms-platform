import { useQuery } from '@tanstack/react-query'
import { Search } from 'lucide-react'
import { useEffect, useState } from 'react'
import { PageHeader } from '@/components/PageHeader'
import { ProgressRing } from '@/components/ProgressRing'
import { Seo } from '@/components/Seo'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { formatDate } from '@/lib/format'
import { teachingApi } from '../api'
import { CoursePicker } from '../components/CoursePicker'

export default function StudentsPage() {
  const [course, setCourse] = useState('')
  const [input, setInput] = useState('')
  const [q, setQ] = useState('')
  const [sort, setSort] = useState('recent')
  const [page, setPage] = useState(1)
  useEffect(() => {
    const t = window.setTimeout(() => {
      setQ(input.trim())
      setPage(1)
    }, 300)
    return () => window.clearTimeout(t)
  }, [input])
  const { data, isLoading } = useQuery({
    queryKey: ['instructor', 'students', course, q, sort, page],
    queryFn: () => teachingApi.students(course, { q: q || undefined, sort, page }),
    enabled: !!course,
  })

  return (
    <>
      <Seo title="Students" noindex />
      <PageHeader title="Students" description="Who's taking each course and how far they've got." />
      <div className="mb-4 flex flex-wrap items-center gap-3">
        <CoursePicker
          value={course}
          onChange={(id) => {
            setCourse(id)
            setPage(1)
          }}
        />
        <div className="relative">
          <Search
            className="text-muted-foreground absolute top-1/2 left-3 size-4 -translate-y-1/2"
            aria-hidden
          />
          <Input
            type="search"
            aria-label="Search students"
            placeholder="Name or email"
            className="w-56 pl-9"
            value={input}
            onChange={(e) => setInput(e.target.value)}
          />
        </div>
        <label htmlFor="st-sort" className="text-sm">
          Sort
        </label>
        <select
          id="st-sort"
          className="border-input bg-background h-9 rounded-md border px-2 text-sm"
          value={sort}
          onChange={(e) => setSort(e.target.value)}
        >
          <option value="recent">Newest first</option>
          <option value="progress">Most progress</option>
          <option value="name">Name</option>
        </select>
        {data && (
          <span className="text-muted-foreground text-sm">
            {data.meta.total} student{data.meta.total === 1 ? '' : 's'}
          </span>
        )}
      </div>
      {isLoading && <Skeleton className="h-40" />}
      {data?.data.length === 0 && (
        <Card className="border-dashed">
          <CardContent className="text-muted-foreground p-10 text-center">
            No students {q ? 'match that search' : 'in this course yet'}.
          </CardContent>
        </Card>
      )}
      {!!data?.data.length && (
        <div className="overflow-x-auto rounded-xl border">
          <table className="w-full min-w-[640px] text-sm">
            <thead className="bg-muted/50 text-muted-foreground text-left">
              <tr>
                <th className="p-3 font-medium">Student</th>
                <th className="p-3 font-medium">Progress</th>
                <th className="p-3 font-medium">Avg quiz</th>
                <th className="p-3 font-medium">Enrolled</th>
                <th className="p-3 font-medium">Last active</th>
              </tr>
            </thead>
            <tbody className="divide-y">
              {data.data.map((s) => (
                <tr key={s.id}>
                  <td className="p-3">
                    <p className="font-medium">{s.name}</p>
                    <p className="text-muted-foreground text-xs">{s.email}</p>
                  </td>
                  <td className="p-3">
                    <div className="flex items-center gap-2">
                      <ProgressRing
                        value={s.progress_percent}
                        size={36}
                        stroke={4}
                        label={`${s.name} progress`}
                      />
                      {s.completed_at && <span className="text-accent text-xs font-medium">Completed</span>}
                    </div>
                  </td>
                  <td className="p-3 tabular-nums">
                    {s.avg_quiz_score === null ? '—' : `${s.avg_quiz_score}%`}
                  </td>
                  <td className="p-3">{formatDate(s.enrolled_at)}</td>
                  <td className="p-3">{formatDate(s.last_activity_at)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {data && data.meta.last_page > 1 && (
        <div className="mt-4 flex items-center gap-2">
          <Button size="sm" variant="outline" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
            Previous
          </Button>
          <span className="text-sm">
            Page {page} of {data.meta.last_page}
          </span>
          <Button
            size="sm"
            variant="outline"
            disabled={page >= data.meta.last_page}
            onClick={() => setPage((p) => p + 1)}
          >
            Next
          </Button>
        </div>
      )}
    </>
  )
}
