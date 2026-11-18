import { useState } from 'react'
import { Link } from 'react-router-dom'
import { PageHeader } from '@/components/PageHeader'
import { ProgressRing } from '@/components/ProgressRing'
import { Seo } from '@/components/Seo'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { formatDate } from '@/lib/format'
import { cn } from '@/lib/utils'
import { useEnrollments } from '../api'

type Tab = 'progress' | 'completed'

export default function MyCoursesPage() {
  const { data, isLoading } = useEnrollments()
  const [tab, setTab] = useState<Tab>('progress')
  const inProgress = data?.filter((c) => !c.completed_at) ?? []
  const completed = data?.filter((c) => c.completed_at) ?? []
  const list = tab === 'progress' ? inProgress : completed

  return (
    <>
      <Seo title="My learning" noindex />
      <PageHeader
        title="My learning"
        description="Pick up where you left off."
        actions={
          <Button asChild variant="outline">
            <Link to="/courses">Browse catalogue</Link>
          </Button>
        }
      />
      <div role="tablist" aria-label="Filter courses" className="mb-4 flex gap-1 border-b">
        {(
          [
            ['progress', `In progress (${inProgress.length})`],
            ['completed', `Completed (${completed.length})`],
          ] as const
        ).map(([v, label]) => (
          <button
            key={v}
            role="tab"
            aria-selected={tab === v}
            onClick={() => setTab(v)}
            className={cn(
              '-mb-px border-b-2 px-3 py-2 text-sm font-medium',
              tab === v ? 'border-primary text-foreground' : 'text-muted-foreground border-transparent',
            )}
          >
            {label}
          </button>
        ))}
      </div>
      {isLoading && <Skeleton className="h-40" />}
      {data && list.length === 0 && (
        <Card className="border-dashed">
          <CardContent className="text-muted-foreground p-10 text-center">
            {tab === 'progress'
              ? "You don't have a course in progress. "
              : "You haven't completed a course yet. "}
            <Link to="/courses" className="text-primary underline">
              Explore the catalogue
            </Link>
            .
          </CardContent>
        </Card>
      )}
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {list.map((c) => (
          <Card key={c.course.id} className="overflow-hidden">
            {c.course.thumbnail_url ? (
              <img src={c.course.thumbnail_url} alt="" className="aspect-video w-full object-cover" />
            ) : (
              <div className="from-primary/70 to-accent/60 aspect-video bg-gradient-to-br" aria-hidden />
            )}
            <CardContent className="grid gap-3 p-4">
              <div className="flex items-center gap-3">
                <ProgressRing value={c.progress_percent} size={48} label={`${c.course.title} progress`} />
                <div className="min-w-0">
                  <h2 className="truncate font-semibold">{c.course.title}</h2>
                  <p className="text-muted-foreground text-xs">
                    {c.completed_at
                      ? `Completed ${formatDate(c.completed_at)}`
                      : `${c.completed_lessons} of ${c.total_lessons} lessons`}
                  </p>
                </div>
              </div>
              <Button asChild size="sm" variant={c.completed_at ? 'outline' : 'default'}>
                <Link
                  to={
                    c.last_lesson_id && !c.completed_at
                      ? `/learn/${c.course.slug}/${c.last_lesson_id}`
                      : `/courses/${c.course.slug}`
                  }
                >
                  {c.completed_at ? 'Review course' : 'Continue'}
                </Link>
              </Button>
            </CardContent>
          </Card>
        ))}
      </div>
    </>
  )
}
