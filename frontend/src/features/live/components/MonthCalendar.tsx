import { ChevronLeft, ChevronRight } from 'lucide-react'
import { Link } from 'react-router-dom'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import type { LiveSession } from '@/types/live'
import { monthGrid } from './month-grid'

const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']

const key = (d: Date) => `${d.getFullYear()}-${d.getMonth()}-${d.getDate()}`

export function MonthCalendar({
  month,
  onMonth,
  sessions,
}: {
  month: Date
  onMonth: (d: Date) => void
  sessions: LiveSession[]
}) {
  const days = monthGrid(month)
  const byDay = new Map<string, LiveSession[]>()
  sessions.forEach((s) => {
    const k = key(new Date(s.starts_at))
    byDay.set(k, [...(byDay.get(k) ?? []), s])
  })
  const today = key(new Date())
  const shift = (n: number) => onMonth(new Date(month.getFullYear(), month.getMonth() + n, 1))

  return (
    <div className="grid gap-3">
      <div className="flex items-center justify-between">
        <h2 className="text-lg font-semibold" aria-live="polite">
          {month.toLocaleDateString(undefined, { month: 'long', year: 'numeric' })}
        </h2>
        <div className="flex gap-1">
          <Button size="icon" variant="outline" aria-label="Previous month" onClick={() => shift(-1)}>
            <ChevronLeft aria-hidden />
          </Button>
          <Button size="sm" variant="outline" onClick={() => onMonth(new Date())}>
            Today
          </Button>
          <Button size="icon" variant="outline" aria-label="Next month" onClick={() => shift(1)}>
            <ChevronRight aria-hidden />
          </Button>
        </div>
      </div>
      <div role="grid" aria-label="Live classes calendar" className="overflow-hidden rounded-lg border">
        <div role="row" className="bg-muted/50 grid grid-cols-7 text-center text-xs font-medium">
          {WEEKDAYS.map((d) => (
            <div key={d} role="columnheader" className="py-2">
              {d}
            </div>
          ))}
        </div>
        {Array.from({ length: 6 }, (_, w) => (
          <div role="row" key={w} className="grid grid-cols-7">
            {days.slice(w * 7, w * 7 + 7).map((d) => {
              const items = byDay.get(key(d)) ?? []
              const outside = d.getMonth() !== month.getMonth()
              return (
                <div
                  key={key(d)}
                  role="gridcell"
                  aria-label={`${d.toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long' })}, ${items.length} session${items.length === 1 ? '' : 's'}`}
                  className={cn(
                    'min-h-24 min-w-0 overflow-hidden border-t border-l p-1 first:border-l-0',
                    outside && 'bg-muted/30 text-muted-foreground',
                  )}
                >
                  <span
                    className={cn(
                      'inline-grid size-6 place-items-center rounded-full text-xs',
                      key(d) === today && 'bg-primary text-primary-foreground font-semibold',
                    )}
                    aria-hidden
                  >
                    {d.getDate()}
                  </span>
                  <ul className="mt-1 grid min-w-0 grid-cols-1 gap-1">
                    {items.map((s) => (
                      <li key={s.id} className="min-w-0">
                        <Link
                          to={`/live/${s.id}`}
                          title={s.title}
                          className={cn(
                            'block truncate rounded px-1.5 py-0.5 text-xs hover:opacity-80',
                            s.status === 'cancelled'
                              ? 'bg-muted text-muted-foreground line-through'
                              : s.viewer?.registered
                                ? 'bg-primary text-primary-foreground'
                                : 'bg-secondary text-secondary-foreground',
                          )}
                        >
                          {new Date(s.starts_at).toLocaleTimeString(undefined, {
                            hour: 'numeric',
                            minute: '2-digit',
                          })}{' '}
                          {s.title}
                        </Link>
                      </li>
                    ))}
                  </ul>
                </div>
              )
            })}
          </div>
        ))}
      </div>
      <p className="text-muted-foreground text-xs">
        Times are shown in your timezone.{' '}
        <span className="bg-primary inline-block size-2 rounded-sm" aria-hidden /> = registered.
      </p>
    </div>
  )
}
