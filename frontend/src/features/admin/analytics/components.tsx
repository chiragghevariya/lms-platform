import type { LucideIcon } from 'lucide-react'
import { useId, type ReactNode } from 'react'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { cn } from '@/lib/utils'
import type { RangeParams } from './api'
import { isoDaysAgo, PRESETS } from './useRange'

const sel = 'border-input bg-background h-9 rounded-md border px-2 text-sm'

/** One filter row above the charts: preset ranges, custom dates, currency (no FX conversion). */
export function RangeBar({
  range,
  currencies,
  onChange,
  timezone,
}: {
  range: RangeParams
  currencies: string[]
  onChange: (patch: Partial<RangeParams>) => void
  timezone?: string
}) {
  const id = useId()
  const today = isoDaysAgo(0)
  const active = PRESETS.find((p) => range.to === today && range.from === isoDaysAgo(p.days - 1))
  return (
    <div className="mb-6 flex flex-wrap items-end gap-2" role="group" aria-label="Report range">
      <div className="bg-muted inline-flex rounded-lg p-1">
        {PRESETS.map((p) => (
          <Button
            key={p.days}
            size="sm"
            variant={active?.days === p.days ? 'default' : 'ghost'}
            aria-pressed={active?.days === p.days}
            className="h-7"
            onClick={() => onChange({ from: isoDaysAgo(p.days - 1), to: today })}
          >
            {p.label}
          </Button>
        ))}
      </div>
      <div className="grid gap-1 text-xs">
        <label htmlFor={`${id}-from`} className="text-muted-foreground">
          From
        </label>
        <Input
          id={`${id}-from`}
          type="date"
          className="h-9 w-40"
          value={range.from}
          max={range.to}
          onChange={(e) => e.target.value && onChange({ from: e.target.value })}
        />
      </div>
      <div className="grid gap-1 text-xs">
        <label htmlFor={`${id}-to`} className="text-muted-foreground">
          To
        </label>
        <Input
          id={`${id}-to`}
          type="date"
          className="h-9 w-40"
          value={range.to}
          min={range.from}
          max={today}
          onChange={(e) => e.target.value && onChange({ to: e.target.value })}
        />
      </div>
      <label className="grid gap-1 text-xs">
        <span className="text-muted-foreground">Currency</span>
        <select
          className={sel}
          value={range.currency}
          onChange={(e) => onChange({ currency: e.target.value })}
        >
          {(currencies.length ? currencies : [range.currency]).map((c) => (
            <option key={c}>{c}</option>
          ))}
        </select>
      </label>
      {timezone && <p className="text-muted-foreground ml-auto text-xs">Times in {timezone}</p>}
    </div>
  )
}

/** Stat tile: one headline number, label, and an optional plain-language note. */
export function KpiCard({
  label,
  value,
  note,
  icon: Icon,
  loading,
}: {
  label: string
  value: ReactNode
  note?: ReactNode
  icon?: LucideIcon
  loading?: boolean
}) {
  return (
    <Card>
      <CardContent className="grid gap-1 p-5">
        <p className="text-muted-foreground flex items-center gap-2 text-sm">
          {Icon && <Icon className="size-4" aria-hidden />}
          {label}
        </p>
        {loading ? (
          <Skeleton className="h-8 w-28" />
        ) : (
          <p className="text-2xl font-bold tracking-tight tabular-nums sm:text-3xl">{value}</p>
        )}
        {note && <p className="text-muted-foreground text-xs">{note}</p>}
      </CardContent>
    </Card>
  )
}

/**
 * Horizontal bars as a list: label, value text and a single-hue bar sized against the
 * largest row. Values are always printed, so colour never carries meaning on its own.
 */
export function BarList({
  rows,
  format = (v) => v.toLocaleString(),
  label,
  empty = 'No data in this period.',
}: {
  rows: { key: string; label: ReactNode; value: number; note?: ReactNode }[]
  format?: (v: number) => string
  label: string
  empty?: string
}) {
  const max = Math.max(1, ...rows.map((r) => r.value))
  if (rows.length === 0 || rows.every((r) => r.value === 0))
    return <p className="text-muted-foreground py-6 text-center text-sm">{empty}</p>
  return (
    <ul className="grid gap-3" aria-label={label}>
      {rows.map((r) => (
        <li key={r.key} className="grid gap-1">
          <div className="flex items-baseline justify-between gap-3 text-sm">
            <span className="truncate">{r.label}</span>
            <span className="font-medium tabular-nums">
              {format(r.value)}
              {r.note && <span className="text-muted-foreground ml-1 text-xs font-normal">{r.note}</span>}
            </span>
          </div>
          <div className="bg-muted h-2 overflow-hidden rounded-full">
            <div
              className="h-full rounded-full"
              style={{ width: `${(r.value / max) * 100}%`, background: 'var(--chart-1)' }}
            />
          </div>
        </li>
      ))}
    </ul>
  )
}

export function Panel({
  title,
  description,
  children,
  className,
  action,
}: {
  title: string
  description?: string
  children: ReactNode
  className?: string
  action?: ReactNode
}) {
  return (
    <section className={cn('bg-card grid content-start gap-4 rounded-xl border p-4 sm:p-5', className)}>
      <div className="flex items-start justify-between gap-2">
        <div>
          <h2 className="font-semibold">{title}</h2>
          {description && <p className="text-muted-foreground text-sm">{description}</p>}
        </div>
        {action}
      </div>
      {children}
    </section>
  )
}
