import { Search } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { PageHeader } from '@/components/PageHeader'
import { Seo } from '@/components/Seo'
import { Card, CardContent } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { formatClock, formatDate } from '@/lib/format'
import type { NoteItem } from '@/types/learning'
import { useNotes } from '../api'

export default function NotesPage() {
  const [input, setInput] = useState('')
  const [q, setQ] = useState('')
  useEffect(() => {
    const t = window.setTimeout(() => setQ(input.trim()), 300)
    return () => window.clearTimeout(t)
  }, [input])
  const { data, isLoading } = useNotes(q)

  // Group by course for scanning.
  const groups = (data ?? []).reduce<Record<string, { title: string; slug: string; notes: NoteItem[] }>>(
    (acc, n) => {
      ;(acc[n.course.id] ??= { title: n.course.title, slug: n.course.slug, notes: [] }).notes.push(n)
      return acc
    },
    {},
  )

  return (
    <>
      <Seo title="Notes" noindex />
      <PageHeader title="Notes" description="Everything you've jotted down while learning." />
      <div className="relative mb-6 max-w-md">
        <Search
          className="text-muted-foreground absolute top-1/2 left-3 size-4 -translate-y-1/2"
          aria-hidden
        />
        <Input
          type="search"
          aria-label="Search notes"
          placeholder="Search notes…"
          className="pl-9"
          value={input}
          onChange={(e) => setInput(e.target.value)}
        />
      </div>
      {isLoading && <Skeleton className="h-32" />}
      {data?.length === 0 && (
        <Card className="border-dashed">
          <CardContent className="text-muted-foreground p-10 text-center">
            {q ? `No notes match “${q}”.` : 'Add notes from the Notes tab inside any lesson.'}
          </CardContent>
        </Card>
      )}
      <div className="grid gap-6">
        {Object.entries(groups).map(([id, g]) => (
          <section key={id} aria-label={g.title} className="grid gap-2">
            <h2 className="font-semibold">
              <Link to={`/courses/${g.slug}`} className="hover:underline">
                {g.title}
              </Link>
            </h2>
            <ul className="grid gap-2">
              {g.notes.map((n) => (
                <li key={n.id} className="rounded-lg border p-3 text-sm">
                  <div className="text-muted-foreground mb-1 flex flex-wrap items-center gap-2 text-xs">
                    <Link
                      to={`/learn/${g.slug}/${n.lesson.id}`}
                      className="text-foreground font-medium hover:underline"
                    >
                      {n.lesson.title}
                    </Link>
                    {n.position_seconds !== null && (
                      <span className="bg-secondary text-secondary-foreground rounded px-1.5 font-mono">
                        {formatClock(n.position_seconds)}
                      </span>
                    )}
                    <span>{formatDate(n.created_at)}</span>
                  </div>
                  <p className="whitespace-pre-wrap">{n.body}</p>
                </li>
              ))}
            </ul>
          </section>
        ))}
      </div>
    </>
  )
}
