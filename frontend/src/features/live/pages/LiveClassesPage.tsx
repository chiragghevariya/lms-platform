import { CalendarDays, Download, List } from 'lucide-react'
import { useMemo, useState } from 'react'
import { toast } from 'sonner'
import { PageHeader } from '@/components/PageHeader'
import { Seo } from '@/components/Seo'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { cn } from '@/lib/utils'
import { liveApi, useLiveSessions, type LiveFilters } from '../api'
import { MonthCalendar } from '../components/MonthCalendar'
import { monthGrid } from '../components/month-grid'
import { SessionCard } from '../components/SessionCard'

const select = 'border-input bg-background h-9 rounded-md border px-2 text-sm'

export default function LiveClassesPage() {
  const [view, setView] = useState<'list' | 'calendar'>('list')
  const [month, setMonth] = useState(() => new Date())
  const [tier, setTier] = useState('')
  const [instructor, setInstructor] = useState('')
  const [mine, setMine] = useState(false)

  const filters = useMemo<LiveFilters>(() => {
    const base = { tier: tier || undefined, instructor: instructor || undefined, mine }
    if (view === 'list') return base
    const grid = monthGrid(month)
    const to = new Date(grid[41]!)
    to.setDate(to.getDate() + 1)
    return { ...base, from: grid[0]!.toISOString(), to: to.toISOString() }
  }, [view, month, tier, instructor, mine])
  const { data, isLoading } = useLiveSessions(filters)
  const sessions = data?.data ?? []

  return (
    <>
      <Seo title="Live classes" noindex />
      <PageHeader
        title="Live classes"
        description={`Register, then join from here ${data?.meta.join_early_minutes ?? 10} minutes before the start.`}
        actions={
          <Button
            variant="outline"
            onClick={() =>
              liveApi
                .downloadIcs('/live-sessions/mine.ics', 'my-live-classes.ics')
                .catch(() => toast.error('Download failed'))
            }
          >
            <Download aria-hidden /> My sessions (.ics)
          </Button>
        }
      />

      <div className="mb-5 flex flex-wrap items-center gap-3">
        <div role="radiogroup" aria-label="View" className="bg-muted inline-flex rounded-md p-1">
          {(
            [
              ['list', 'List', List],
              ['calendar', 'Calendar', CalendarDays],
            ] as const
          ).map(([v, label, Icon]) => (
            <button
              key={v}
              role="radio"
              aria-checked={view === v}
              onClick={() => setView(v)}
              className={cn(
                'inline-flex items-center gap-1.5 rounded px-3 py-1.5 text-sm font-medium',
                view === v ? 'bg-background shadow-sm' : 'text-muted-foreground',
              )}
            >
              <Icon className="size-4" aria-hidden /> {label}
            </button>
          ))}
        </div>
        <label className="flex items-center gap-2 text-sm">
          Tier
          <select className={select} value={tier} onChange={(e) => setTier(e.target.value)}>
            <option value="">All</option>
            <option value="open">Open to all members</option>
            <option value="starter">Starter</option>
            <option value="pro">Pro</option>
            <option value="elite">Elite</option>
          </select>
        </label>
        <label className="flex items-center gap-2 text-sm">
          Instructor
          <select className={select} value={instructor} onChange={(e) => setInstructor(e.target.value)}>
            <option value="">All</option>
            {data?.meta.instructors.map((i) => (
              <option key={i.id} value={i.id}>
                {i.name}
              </option>
            ))}
          </select>
        </label>
        <label className="flex items-center gap-2 text-sm">
          <input type="checkbox" checked={mine} onChange={(e) => setMine(e.target.checked)} /> Only my
          registrations
        </label>
      </div>

      {view === 'calendar' ? (
        <MonthCalendar month={month} onMonth={setMonth} sessions={sessions} />
      ) : (
        <>
          {isLoading && <Skeleton className="h-28" />}
          {!isLoading && sessions.length === 0 && (
            <Card className="border-dashed">
              <CardContent className="text-muted-foreground p-10 text-center">
                No upcoming sessions match these filters.
              </CardContent>
            </Card>
          )}
          <ul className="grid gap-3">
            {sessions.map((s) => (
              <li key={s.id}>
                <SessionCard session={s} />
              </li>
            ))}
          </ul>
        </>
      )}
    </>
  )
}
