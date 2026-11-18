import { ChevronLeft, ChevronRight, Clock } from 'lucide-react'
import { Link, useSearchParams } from 'react-router-dom'
import { Seo } from '@/components/Seo'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { formatDate } from '@/lib/format'
import { usePosts } from '../api'

export default function BlogPage() {
  const [params, setParams] = useSearchParams()
  const page = Math.max(1, Number(params.get('page')) || 1)
  const { data, isLoading, isError } = usePosts(page)
  const go = (p: number) => setParams(p > 1 ? { page: String(p) } : {})

  return (
    <div className="mx-auto max-w-6xl px-4 py-16">
      <Seo
        title="Blog"
        description="Guides, stories and income tips from our mentors and learners."
        path="/blog"
      />
      <header className="mb-10 max-w-2xl">
        <p className="text-primary mb-2 text-sm font-semibold tracking-wider uppercase">Blog</p>
        <h1 className="text-4xl font-extrabold tracking-tight">Learn, earn, repeat</h1>
        <p className="text-muted-foreground mt-3 text-lg">
          Guides, stories and income tips from our mentors and learners.
        </p>
      </header>

      {isError && (
        <p className="text-muted-foreground">The blog couldn't be loaded. Please try again later.</p>
      )}
      {data?.data.length === 0 && <p className="text-muted-foreground">No posts yet — check back soon.</p>}

      <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
        {isLoading &&
          Array.from({ length: 6 }).map((_, i) => <Skeleton key={i} className="h-80 rounded-xl" />)}
        {data?.data.map((p) => (
          <Card key={p.id} className="group overflow-hidden">
            <Link
              to={`/blog/${p.slug}`}
              className="focus-visible:ring-ring block h-full focus-visible:ring-2 focus-visible:outline-none"
            >
              {p.cover_url ? (
                <img src={p.cover_url} alt="" loading="lazy" className="aspect-[16/9] w-full object-cover" />
              ) : (
                <div
                  aria-hidden
                  className="from-primary/20 to-accent/20 aspect-[16/9] w-full bg-gradient-to-br"
                />
              )}
              <CardContent className="grid gap-2 p-5">
                <p className="text-muted-foreground flex items-center gap-2 text-xs">
                  <time dateTime={p.published_at ?? undefined}>{formatDate(p.published_at)}</time>
                  <span aria-hidden>·</span>
                  <Clock className="size-3" aria-hidden /> {p.reading_minutes} min read
                </p>
                <h2 className="text-lg font-semibold group-hover:underline">{p.title}</h2>
                {p.excerpt && <p className="text-muted-foreground line-clamp-3 text-sm">{p.excerpt}</p>}
              </CardContent>
            </Link>
          </Card>
        ))}
      </div>

      {data && data.meta.last_page > 1 && (
        <nav aria-label="Blog pages" className="mt-10 flex items-center justify-center gap-3 text-sm">
          <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => go(page - 1)}>
            <ChevronLeft aria-hidden /> Newer
          </Button>
          <span className="text-muted-foreground tabular-nums">
            Page {page} of {data.meta.last_page}
          </span>
          <Button
            variant="outline"
            size="sm"
            disabled={page >= data.meta.last_page}
            onClick={() => go(page + 1)}
          >
            Older <ChevronRight aria-hidden />
          </Button>
        </nav>
      )}
    </div>
  )
}
