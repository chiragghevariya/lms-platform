import { useQuery } from '@tanstack/react-query'
import { Lock, PlayCircle, Search } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { PageHeader } from '@/components/PageHeader'
import { Seo } from '@/components/Seo'
import { Card, CardContent } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { TierBadge } from '@/features/membership/components/TierBadge'
import { formatClock, formatDate } from '@/lib/format'
import { liveApi } from '../api'

export default function ReplaysPage() {
  const [input, setInput] = useState('')
  const [q, setQ] = useState('')
  useEffect(() => {
    const t = window.setTimeout(() => setQ(input.trim()), 300)
    return () => window.clearTimeout(t)
  }, [input])
  const { data, isLoading } = useQuery({
    queryKey: ['live', 'replays', q],
    queryFn: () => liveApi.replays(q),
  })

  return (
    <>
      <Seo title="Replays" noindex />
      <PageHeader title="Replays" description="Recordings of past live classes, for your membership tier." />
      <div className="relative mb-6 max-w-md">
        <Search
          className="text-muted-foreground absolute top-1/2 left-3 size-4 -translate-y-1/2"
          aria-hidden
        />
        <Input
          type="search"
          aria-label="Search replays"
          placeholder="Search replays…"
          className="pl-9"
          value={input}
          onChange={(e) => setInput(e.target.value)}
        />
      </div>
      {isLoading && <Skeleton className="h-40" />}
      {data?.length === 0 && (
        <Card className="border-dashed">
          <CardContent className="text-muted-foreground p-10 text-center">
            No replays yet. Recordings appear here after each class.
          </CardContent>
        </Card>
      )}
      <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {data?.map((r) => {
          const to =
            r.course && r.replay_lesson_id
              ? `/learn/${r.course.slug}/${r.replay_lesson_id}`
              : `/app/replays/${r.id}`
          return (
            <li key={r.id}>
              <Card className="h-full overflow-hidden">
                <Link
                  to={to}
                  className="group block focus-visible:outline-none"
                  aria-label={`${r.locked ? 'Locked: ' : 'Watch '}${r.title}`}
                >
                  <div className="from-primary/70 to-accent/60 relative grid aspect-video place-items-center bg-gradient-to-br text-white">
                    {r.locked ? (
                      <Lock className="size-10" aria-hidden />
                    ) : (
                      <PlayCircle
                        className="size-12 transition-transform group-hover:scale-110"
                        aria-hidden
                      />
                    )}
                    {r.replay_duration_seconds && (
                      <span className="absolute right-2 bottom-2 rounded bg-black/70 px-1.5 text-xs">
                        {formatClock(r.replay_duration_seconds)}
                      </span>
                    )}
                  </div>
                </Link>
                <CardContent className="grid gap-1 p-4">
                  <div className="flex items-start justify-between gap-2">
                    <h2 className="font-semibold">{r.title}</h2>
                    <TierBadge tier={r.required_tier} />
                  </div>
                  <p className="text-muted-foreground text-sm">
                    {r.instructor.name} · {formatDate(r.starts_at)}
                  </p>
                  {r.course && <p className="text-muted-foreground text-xs">In {r.course.title}</p>}
                </CardContent>
              </Card>
            </li>
          )
        })}
      </ul>
    </>
  )
}
