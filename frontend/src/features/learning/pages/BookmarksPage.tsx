import { Bookmark, Trash2 } from 'lucide-react'
import { Link } from 'react-router-dom'
import { toast } from 'sonner'
import { useQueryClient } from '@tanstack/react-query'
import { PageHeader } from '@/components/PageHeader'
import { Seo } from '@/components/Seo'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { formatClock } from '@/lib/format'
import { learningApi, useBookmarks } from '../api'

export default function BookmarksPage() {
  const { data, isLoading } = useBookmarks()
  const qc = useQueryClient()

  const remove = async (id: string) => {
    try {
      await learningApi.deleteBookmark(id)
      await qc.invalidateQueries({ queryKey: ['me', 'bookmarks'] })
      void qc.invalidateQueries({ queryKey: ['courses', 'detail'] })
    } catch {
      toast.error('Could not remove the bookmark')
    }
  }

  return (
    <>
      <Seo title="Bookmarks" noindex />
      <PageHeader title="Bookmarks" description="Courses saved for later and lessons you want to revisit." />
      {isLoading && <Skeleton className="h-32" />}
      {data?.length === 0 && (
        <Card className="border-dashed">
          <CardContent className="text-muted-foreground grid place-items-center gap-2 p-10 text-center">
            <Bookmark className="size-8" aria-hidden />
            Use “Save for later” on a course page or “Bookmark” inside a lesson.
          </CardContent>
        </Card>
      )}
      <ul className="grid gap-3">
        {data?.map((b) => {
          const to = b.lesson ? `/learn/${b.course.slug}/${b.lesson.id}` : `/courses/${b.course.slug}`
          return (
            <li key={b.id}>
              <Card>
                <CardContent className="flex items-center gap-4 p-3">
                  {b.course.thumbnail_url ? (
                    <img
                      src={b.course.thumbnail_url}
                      alt=""
                      className="aspect-video w-24 rounded object-cover"
                    />
                  ) : (
                    <div
                      className="from-primary/70 to-accent/60 aspect-video w-24 rounded bg-gradient-to-br"
                      aria-hidden
                    />
                  )}
                  <div className="min-w-0 flex-1">
                    <Link to={to} className="font-medium hover:underline">
                      {b.lesson ? b.lesson.title : b.course.title}
                    </Link>
                    <p className="text-muted-foreground text-sm">
                      {b.lesson ? b.course.title : 'Course'}
                      {b.position_seconds !== null && ` · at ${formatClock(b.position_seconds)}`}
                      {b.label && ` · ${b.label}`}
                    </p>
                  </div>
                  <Button
                    size="icon"
                    variant="ghost"
                    aria-label={`Remove bookmark ${b.lesson?.title ?? b.course.title}`}
                    onClick={() => void remove(b.id)}
                  >
                    <Trash2 aria-hidden />
                  </Button>
                </CardContent>
              </Card>
            </li>
          )
        })}
      </ul>
    </>
  )
}
